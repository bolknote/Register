<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Comment\CommentRepository;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Register\Import\Telegram\TelegramImportService;
use Register\Import\Telegram\TelegramLiveImportController;
use Register\Import\Telegram\TelegramLiveImportConfig;
use Register\Import\Telegram\TelegramSettings;
use Register\Import\Telegram\TelegramFileClientInterface;
use Register\Import\Telegram\TelegramManagedMediaStorage;
use Register\Import\Telegram\TelegramMediaDownloadFailed;
use Register\Import\Telegram\TelegramLiveReactionService;
use Register\Import\Telegram\TelegramLiveMediaController;
use Register\Import\Telegram\TelegramMediaUploadStorage;
use Register\Import\ExternalImportMapRepository;
use Register\Module\Reactions\ReactionAggregateSchema;
use Register\Module\Reactions\ReactionRepository;
use Register\Url\ContentUrlAliasSchema;
use Register\Url\ContentUrlGenerator;
use Symfony\Component\HttpFoundation\Request;

final class TelegramLiveImportCest
{
    private const string TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function authenticatesScopesAndRejectsMalformedThreadsBeforeWriting(\IntegrationTester $I): void
    {
        $this->post($I);
        $this->send($I, $this->snapshot());
        $I->seeResponseCodeIs(404);
        $this->enable($I);
        $I->sendJson(TelegramLiveImportController::PATH, $this->snapshot());
        $I->seeResponseCodeIs(401);
        $I->sendJson(TelegramLiveImportController::PATH, $this->snapshot(), headers: ['X-Register-Telegram-Token' => 'wrong']);
        $I->seeResponseCodeIs(401);

        $wrongGroup = $this->snapshot();
        $wrongGroup['id'] = 456;
        $this->send($I, $wrongGroup);
        $I->seeResponseCodeIs(422);
        $wrongChannel = $this->snapshot();
        $wrongChannel['messages'][0]['forwarded_from_id'] = 'channel999';
        $this->send($I, $wrongChannel);
        $I->seeResponseCodeIs(422);
        $unrelated = $this->snapshot();
        $unrelated['messages'][2]['reply_to_message_id'] = 999;
        $this->send($I, $unrelated);
        $I->seeResponseCodeIs(422);
        $I->assertSame(0, $I->grabService(CommentRepository::class)->count(ContentId::post($this->postId($I)), true));
    }

    public function importsRepliesOnceAndPreservesLocalEditsAndModeration(\IntegrationTester $I): void
    {
        $contentId = ContentId::post($this->post($I));
        $this->enable($I);
        $I->setConfigValue('REGISTER_PREMODERATION', '0');
        $this->send($I, $this->snapshot());
        $I->seeResponseCodeIs(200);
        $I->assertSame(2, $this->change($I, 'comments_inserted'));
        $this->send($I, $this->snapshot());
        $I->seeResponseCodeIs(200);
        $I->assertSame(0, $this->change($I, 'comments_inserted'));

        /** @var CommentRepository $comments */
        $comments = $I->grabService(CommentRepository::class);
        $rows = $comments->findForContent($contentId);
        $I->assertCount(2, $rows);
        $I->assertSame($rows[0]->id, $rows[1]->parentId);
        $I->assertTrue($rows[0]->shown);
        $I->assertNull($rows[0]->userId);
        $I->assertSame('', $rows[0]->email);

        $comments->edit($rows[0]->id, ContentType::POST, 'A local correction');
        $comments->hide($rows[1]->id, ContentType::POST);

        $edit = $this->snapshot();
        $edit['messages'][1]['text'] = 'Remote correction';
        $edit['messages'][1]['text_entities'] = [['type' => 'plain', 'text' => 'Remote correction']];
        $edit['messages'][1]['edited_unixtime'] = '200';
        $edit['messages'][1]['bot_update_id'] = 200;
        $this->send($I, $edit);
        $I->seeResponseCodeIs(200);
        $I->assertSame(1, $this->change($I, 'comments_local_edits_preserved'));
        $I->assertSame('A local correction', $comments->find($rows[0]->id)?->text);
        $I->assertFalse($comments->find($rows[1]->id)?->shown);
    }

    public function ignoresLateEventsAndDoesNotEraseArchiveReactions(\IntegrationTester $I): void
    {
        $contentId = ContentId::post($this->post($I));
        $this->enable($I);
        $I->setConfigValue('REGISTER_PREMODERATION', '0');
        $archive = $this->snapshot();
        $archive['messages'][0]['reactions'] = [['type' => 'emoji', 'count' => 2, 'emoji' => '👀']];
        $file = tempnam(sys_get_temp_dir(), 'register-telegram-live-');
        $I->assertIsString($file);
        try {
            file_put_contents($file, json_encode($archive, JSON_THROW_ON_ERROR));
            /** @var TelegramImportService $importer */
            $importer = $I->grabService(TelegramImportService::class);
            $importer->importFile($file);
        } finally {
            unlink($file);
        }

        $edit = $this->snapshot();
        $edit['messages'][1]['text'] = 'Latest Telegram version';
        $edit['messages'][1]['text_entities'] = [['type' => 'plain', 'text' => 'Latest Telegram version']];
        $edit['messages'][1]['edited_unixtime'] = '200';
        $edit['messages'][1]['bot_update_id'] = 200;
        $this->send($I, $edit);
        $I->seeResponseCodeIs(200);
        $I->assertSame(1, $this->change($I, 'comments_updated'));
        $this->send($I, $this->snapshot());
        $I->seeResponseCodeIs(200);
        $I->assertSame(1, $this->change($I, 'comments_stale_ignored'));

        $sameSecond = $edit;
        $sameSecond['messages'][1]['text_entities'] = [['type' => 'plain', 'text' => 'Earlier version in the same second']];
        $sameSecond['messages'][1]['bot_update_id'] = 199;
        $this->send($I, $sameSecond);
        $I->assertSame(1, $this->change($I, 'comments_stale_ignored'));

        /** @var CommentRepository $comments */
        $comments = $I->grabService(CommentRepository::class);
        $rows = $comments->findForContent($contentId);
        $I->assertCount(2, $rows);
        $I->assertStringContainsString('Latest Telegram version', $rows[0]->text);
        $I->assertSame(['👀' => 2], $I->grabService(ReactionRepository::class)->state($contentId->value)->extraCounts);
    }

    public function honoursPremoderationAndRetriesUnpublishedTargets(\IntegrationTester $I): void
    {
        $id = $this->post($I);
        $this->enable($I);
        $I->setConfigValue('REGISTER_PREMODERATION', '1');
        $this->send($I, $this->snapshot());
        $I->seeResponseCodeIs(200);
        /** @var CommentRepository $comments */
        $comments = $I->grabService(CommentRepository::class);
        $I->assertSame(0, $comments->count(ContentId::post($id)));
        $I->assertSame(2, $comments->countPending());

        $missing = $this->snapshot();
        $missing['messages'][0]['text_entities'][0]['href'] = 'http://register.localhost/not-published';
        $this->send($I, $missing);
        $I->seeResponseCodeIs(409);
        $I->assertSame(2, $comments->count(ContentId::post($id), true));
    }

    public function recognisesTheDiscussionAuthorAndRepairsAnUnlinkedImportedIdentity(\IntegrationTester $I): void
    {
        $contentId = ContentId::post($this->post($I));
        $this->enable($I);
        $I->setConfigValue('REGISTER_PREMODERATION', '0');
        $snapshot = $this->snapshot();
        $snapshot['messages'] = array_slice($snapshot['messages'], 0, 2);
        $snapshot['messages'][1]['from'] = 'Example discussion Chat';
        $snapshot['messages'][1]['from_id'] = 'channel123';
        $this->send($I, $snapshot);
        $I->seeResponseCodeIs(200);

        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $owner = $db->select('id, name, login')->from('users')->where('edit_site = 1')->orderBy('id')->limit(1)->execute()->fetchAssoc();
        $I->assertIsArray($owner);
        /** @var CommentRepository $repository */
        $repository = $I->grabService(CommentRepository::class);
        $comment = $repository->findForContent($contentId)[0];
        $I->assertSame((int)$owner['id'], $comment->userId);
        $I->assertSame(trim((string)$owner['name']) !== '' ? trim((string)$owner['name']) : (string)$owner['login'], $comment->name);

        // Reproduce the old live importer without changing the recorded source hash/text.
        $db->update(\Register\Comment\CommentSchema::TABLE_NAME)
            ->set('user_id', 'NULL')->set('nick', ':nick')->setParameter('nick', 'Example discussion Chat')
            ->where('id = :id')->setParameter('id', $comment->id)->execute();
        $repository->edit($comment->id, ContentType::POST, 'A local owner correction');
        $this->send($I, $snapshot);
        $I->seeResponseCodeIs(200);
        $repaired = $repository->find($comment->id);
        $I->assertNotNull($repaired);
        $I->assertSame((int)$owner['id'], $repaired->userId);
        $I->assertSame(1, $this->change($I, 'comments_updated'));
        $I->assertSame('A local owner correction', $repaired->text);
        $I->assertSame(1, $this->change($I, 'comments_local_edits_preserved'));
        $I->assertCount(1, $repository->findForContent($contentId));
    }

    public function recognisesOnlyTheExplicitlyConfiguredPersonalTelegramOwner(\IntegrationTester $I): void
    {
        $contentId = ContentId::post($this->post($I));
        $this->enable($I);
        $I->setConfigValue(TelegramSettings::OWNER_TELEGRAM_ID, '22');
        $this->send($I, $this->snapshot());
        $I->seeResponseCodeIs(200);

        /** @var CommentRepository $repository */
        $repository = $I->grabService(CommentRepository::class);
        $comments = $repository->findForContent($contentId);
        $I->assertNotNull($comments[0]->userId);
        $I->assertNotSame('Reader', $comments[0]->name);
        $I->assertNull($comments[1]->userId);
        $I->assertSame('Another reader', $comments[1]->name);
    }

    public function settingsBindThePersonalAndAnonymousAccountsToTheSelectedAuthor(\IntegrationTester $I): void
    {
        $contentId = ContentId::post($this->post($I));
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $authorId = (int)$db->select('id')->from('users')->where("login = 'author'")->execute()->result();
        $db->update('users')->set('name', "'Selected author'")->where('id = :id')->setParameter('id', $authorId)->execute();
        foreach ([
            TelegramSettings::BRIDGE_TOKEN => self::TOKEN,
            TelegramSettings::DISCUSSION_ID => '-1000000000123',
            TelegramSettings::CHANNEL_ID => '-1000000000111',
            TelegramSettings::OWNER_TELEGRAM_ID => '22',
            TelegramSettings::AUTHOR_ID => (string)$authorId,
            TelegramSettings::ENABLED => '1',
        ] as $key => $value) {
            $I->setConfigValue($key, $value);
        }

        $snapshot = $this->snapshot();
        $snapshot['messages'][2]['from_id'] = 'channel123';
        $snapshot['messages'][2]['from'] = 'Example discussion Chat';
        $this->send($I, $snapshot);
        $I->seeResponseCodeIs(200);
        /** @var CommentRepository $repository */
        $repository = $I->grabService(CommentRepository::class);
        foreach ($repository->findForContent($contentId) as $comment) {
            $I->assertSame($authorId, $comment->userId);
            $I->assertSame('Selected author', $comment->name);
        }

        $I->setConfigValue(TelegramSettings::ENABLED, '0');
        $this->send($I, $snapshot);
        $I->seeResponseCodeIs(404);
    }

    private function enable(\IntegrationTester $I): void
    {
        foreach ([
            TelegramSettings::BRIDGE_TOKEN => self::TOKEN,
            TelegramSettings::DISCUSSION_ID => '-1000000000123',
            TelegramSettings::CHANNEL_ID => '-1000000000111',
            TelegramSettings::ENABLED => '1',
        ] as $key => $value) {
            $I->setConfigValue($key, $value);
        }
    }

    public function importsPersonalReactionsAndRemovalsOnceEvenWhenEventsAreReordered(\IntegrationTester $I): void
    {
        $id = $this->post($I);
        $this->enable($I);
        $this->send($I, $this->snapshot());
        $I->seeResponseCodeIs(200);
        $comment = $I->grabService(CommentRepository::class)->findForContent(ContentId::post($id), true)[0];
        $emoji = static fn(string $value): array => ['type' => 'emoji', 'emoji' => $value];
        $reaction = ['type' => 'change', 'chat_id' => -1000000000123, 'message_id' => 2,
            'actor' => 'user22', 'date' => 202, 'update_id' => 202,
            'old_reaction' => [$emoji('👍')], 'new_reaction' => [$emoji('❤')]];
        $this->send($I, ['reaction_update' => $reaction]);
        $I->seeResponseCodeIs(200);
        $this->send($I, ['reaction_update' => [...$reaction, 'date' => 201, 'update_id' => 201,
            'old_reaction' => [], 'new_reaction' => [$emoji('👍')]]]);
        $I->seeResponseCodeIs(200);
        $this->send($I, ['reaction_update' => $reaction]);
        $I->seeResponseCodeIs(200);
        $I->assertSame(['❤' => 1], $this->commentReactionCounts($I, $comment->id));
        $this->send($I, ['reaction_update' => [...$reaction, 'date' => 203, 'update_id' => 203,
            'old_reaction' => [$emoji('❤')], 'new_reaction' => []]]);
        $I->seeResponseCodeIs(200);
        $I->assertSame([], $this->commentReactionCounts($I, $comment->id));
        $this->send($I, ['reaction_update' => [...$reaction, 'date' => 204, 'update_id' => 204,
            'actor' => 'chat-1000000000555', 'old_reaction' => [], 'new_reaction' => [$emoji('👍')]]]);
        $I->assertSame(['👍' => 1], $this->commentReactionCounts($I, $comment->id));
    }

    public function reconcilesChannelCountsWithTheirDiscussionPostAndPreservesLocalLikes(\IntegrationTester $I): void
    {
        $id = $this->post($I);
        $this->enable($I);
        $snapshot = $this->snapshot();
        $snapshot['messages'][0]['channel_message_id'] = 10;
        $this->send($I, $snapshot);
        $I->seeResponseCodeIs(200);
        $channelPost = $snapshot['messages'][0];
        $channelPost['id'] = 10;
        $this->send($I, ['id' => 111, 'type' => 'supergroup', 'messages' => [$channelPost]]);
        $I->seeResponseCodeIs(200);
        $I->assertSame(0, $this->change($I, 'comments_inserted'));
        $I->sendJson('https://localhost/_visitor/resolve', ['trackPage' => false], headers: ['Origin' => 'https://localhost']);
        $I->sendJson('https://localhost/_reactions/post/' . $id, ['reaction' => 'like'], headers: ['Origin' => 'https://localhost']);
        $I->seeResponseCodeIs(200);

        $event = ['type' => 'count', 'chat_id' => -1000000000111, 'message_id' => 10, 'date' => 300, 'update_id' => 300,
            'reactions' => [['type' => ['type' => 'emoji', 'emoji' => '👍'], 'total_count' => 5]]];
        $this->send($I, ['reaction_update' => $event]);
        $I->seeResponseCodeIs(200);
        $this->send($I, ['reaction_update' => $event]);
        $I->seeResponseCodeIs(200);
        $I->assertSame(6, $I->grabService(ReactionRepository::class)->state($id)->counts['like']);
        $this->send($I, ['reaction_update' => [...$event, 'date' => 299, 'update_id' => 299,
            'reactions' => [['type' => ['type' => 'emoji', 'emoji' => '👍'], 'total_count' => 2]]]]);
        $I->assertSame(6, $I->grabService(ReactionRepository::class)->state($id)->counts['like']);
        $this->send($I, ['reaction_update' => [...$event, 'date' => 301, 'update_id' => 301, 'reactions' => []]]);
        $I->seeResponseCodeIs(200);
        $I->assertSame(1, $I->grabService(ReactionRepository::class)->state($id)->counts['like']);
        $this->send($I, ['reaction_update' => [...$event, 'date' => 302, 'update_id' => 302,
            'reactions' => [['type' => ['type' => 'custom_emoji', 'custom_emoji_id' => '123456789'], 'total_count' => 2],
                ['type' => ['type' => 'paid'], 'total_count' => 3]]]]);
        $I->seeResponseCodeIs(200);
        $extra = $I->grabService(ReactionRepository::class)->state($id)->extraCounts;
        ksort($extra);
        $expected = ['✦' => 2, '⭐' => 3];
        ksort($expected);
        $I->assertSame($expected, $extra);
    }

    public function retriesUnknownReactionTargetsAndRejectsOtherChatsBeforeWriting(\IntegrationTester $I): void
    {
        $this->post($I);
        $this->enable($I);
        $event = ['type' => 'change', 'chat_id' => -1000000000123, 'message_id' => 2,
            'actor' => 'user22', 'date' => 202, 'update_id' => 202,
            'old_reaction' => [], 'new_reaction' => [['type' => 'emoji', 'emoji' => '👍']]];
        $this->send($I, ['reaction_update' => [...$event, 'chat_id' => -1000000000999]]);
        $I->seeResponseCodeIs(422);
        $this->send($I, ['reaction_update' => $event]);
        $I->seeResponseCodeIs(409);
        $this->send($I, $this->snapshot());
        $this->send($I, ['reaction_update' => $event]);
        $I->seeResponseCodeIs(200);
    }

    public function downloadsPhotosVideosAndAllStickersAndKeepsFailedDownloadsRetryable(\IntegrationTester $I): void
    {
        $id = $this->post($I);
        $this->enable($I);
        $root = sys_get_temp_dir() . '/register-live-media-' . bin2hex(random_bytes(6));
        mkdir($root . '/_pictures/bolknote/comments', 0755, true);
        $I->replaceService(TelegramManagedMediaStorage::class, new TelegramManagedMediaStorage($root),
            [TelegramImportService::class, TelegramLiveReactionService::class, TelegramLiveImportController::class]);
        $files = [];
        foreach (['photo.png', 'video.mp4', 'sticker.webp', 'sticker.webm'] as $name) {
            $bytes = file_get_contents(__DIR__ . '/../_resources/telegram-media/' . $name);
            $I->assertIsString($bytes);
            $files[$name] = $bytes;
        }

        $sticker = gzencode((string)file_get_contents(__DIR__ . '/../_resources/telegram-sticker.json'));
        if (!\is_string($sticker)) {
            throw new \RuntimeException('The generated TGS fixture is unavailable.');
        }

        $files['sticker.tgs'] = $sticker;
        $client = new class($files) implements TelegramFileClientInterface {
            public int $calls = 0;

            public string $failOn = 'video.mp4';

            /** @param array<string, string> $files */
            public function __construct(private readonly array $files) {}

            public function download(array $media): ?string
            {
                ++$this->calls;
                if ($media['file_name'] === $this->failOn) {
                    throw new TelegramMediaDownloadFailed();
                }

                return $this->files[$media['file_name']] ?? null;
            }
        };
        $I->replaceService(TelegramFileClientInterface::class, $client, [TelegramLiveImportController::class]);
        try {
            $snapshot = $this->snapshot();
            $snapshot['messages'] = array_slice($snapshot['messages'], 0, 2);
            $snapshot['messages'][1]['text'] = '';
            $snapshot['messages'][1]['text_entities'] = [];
            $media = [];
            foreach (array_keys($files) as $position => $name) {
                $unique = 'file' . $position;
                $extension = pathinfo($name, PATHINFO_EXTENSION);
                $media[] = ['kind' => $extension === 'png' ? 'photo' : 'file', 'path' => 'live/2/' . ($position + 1) . '-' . $unique . '.' . $extension,
                    'file_id' => $unique, 'file_unique_id' => $unique, 'file_size' => \strlen($files[$name]),
                    'file_name' => $name, 'mime_type' => 'application/octet-stream', 'sticker' => str_starts_with($name, 'sticker'), 'emoji' => '🙂'];
            }

            $snapshot['messages'][1]['telegram_media'] = $media;
            $malformed = $snapshot;
            $malformed['messages'][1]['telegram_media'][0]['file_id'] = 'https://elsewhere.invalid/private';
            $this->send($I, $malformed);
            $I->seeResponseCodeIs(422);
            $I->assertSame(0, $client->calls);
            $this->send($I, $snapshot);
            $I->seeResponseCodeIs(503);
            $I->assertSame(0, $I->grabService(CommentRepository::class)->count(ContentId::post($id), true));
            $I->assertSame([], glob($root . '/_pictures/telegram/comments/123/2/*'));
            $client->failOn = '';
            $this->send($I, $snapshot);
            $I->seeResponseCodeIs(200);
            $I->assertSame(1, $this->change($I, 'comments_inserted'));
            $comment = $I->grabService(CommentRepository::class)->findForContent(ContentId::post($id), true)[0];
            $I->assertStringContainsString('<img ', $comment->text);
            $I->assertStringContainsString('<video ', $comment->text);
            $I->assertStringContainsString('comment-sticker', $comment->text);
            $storedFiles = glob($root . '/_pictures/telegram/comments/123/2/*');
            $I->assertIsArray($storedFiles);
            $I->assertCount(5, $storedFiles);
            $calls = $client->calls;
            $snapshot['messages'][1]['text_entities'] = [['type' => 'plain', 'text' => 'New caption']];
            $snapshot['messages'][1]['text'] = 'New caption';
            $snapshot['messages'][1]['edited_unixtime'] = '200';
            $snapshot['messages'][1]['bot_update_id'] = 200;
            $this->send($I, $snapshot);
            $I->seeResponseCodeIs(200);
            $I->assertSame($calls, $client->calls);
            $I->assertStringContainsString('New caption', $I->grabService(CommentRepository::class)->find($comment->id)->text);
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($root);
        }
    }

    /** @return array<string, int> */
    private function commentReactionCounts(\IntegrationTester $I, int $commentId): array
    {
        $rows = $I->grabService(DbLayer::class)->select('emoji, SUM(reaction_count) AS count')
            ->from(ReactionAggregateSchema::TABLE_NAME)->where("target_type = 'comment'")
            ->andWhere('target_id = :id')->setParameter('id', $commentId)->groupBy('emoji')->execute()->fetchAssocAll();
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['emoji']] = (int)$row['count'];
        }

        return $result;
    }

    public function acceptsAuthenticatedBytesBeforePublishingMediaAndRemovesPrivateStagingAfterCommit(\IntegrationTester $I): void
    {
        $id = $this->post($I);
        $this->enable($I);
        $root = sys_get_temp_dir() . '/register-direct-upload-' . bin2hex(random_bytes(6));
        mkdir($root . '/_pictures/bolknote/comments', 0755, true);
        $managed = new TelegramManagedMediaStorage($root);
        $uploads = new TelegramMediaUploadStorage($root . '/private', 123, $I->grabService(ExternalImportMapRepository::class), $managed);
        $I->replaceService(TelegramManagedMediaStorage::class, $managed, [TelegramImportService::class, TelegramLiveReactionService::class, TelegramLiveImportController::class]);
        $I->replaceService(TelegramFileClientInterface::class, $uploads, [TelegramLiveImportController::class]);
        $I->replaceService(TelegramMediaUploadStorage::class, $uploads, [TelegramLiveMediaController::class]);
        try {
            $probe = ['chat_id' => 123, 'message_id' => 2, 'file_unique_id' => 'photo'];
            $I->sendJson(TelegramLiveMediaController::PATH, $probe);
            $I->seeResponseCodeIs(401);
            $I->sendJson(TelegramLiveMediaController::PATH, [...$probe, 'chat_id' => 456], headers: ['X-Register-Telegram-Token' => self::TOKEN]);
            $I->seeResponseCodeIs(422);
            $png = file_get_contents(__DIR__ . '/../_resources/telegram-media/photo.png');
            $I->assertIsString($png);
            $snapshot = $this->snapshot();
            $snapshot['messages'] = array_slice($snapshot['messages'], 0, 2);
            $snapshot['messages'][1]['text'] = '';
            $snapshot['messages'][1]['text_entities'] = [];
            $snapshot['messages'][1]['telegram_media'] = [[
                'kind' => 'photo', 'path' => 'live/2/1-photo.png', 'file_id' => 'opaque', 'file_unique_id' => 'photo',
                'file_size' => \strlen($png), 'file_name' => 'photo.png', 'mime_type' => 'image/png', 'sticker' => false, 'emoji' => '',
            ]];
            $this->send($I, $snapshot);
            $I->seeResponseCodeIs(503);
            $I->assertSame(0, $I->grabService(CommentRepository::class)->count(ContentId::post($id), true));
            $headers = ['X-Register-Telegram-Token' => self::TOKEN, 'X-Register-Telegram-Chat' => '123',
                'X-Register-Telegram-Message' => '2', 'X-Register-Telegram-File' => 'photo',
                'X-Register-Telegram-Size' => (string)\strlen($png), 'X-Register-Telegram-Offset' => '0'];
            $I->sendBinary(TelegramLiveMediaController::PATH, $png, $headers);
            $I->seeResponseCodeIs(200);
            $I->sendBinary(TelegramLiveMediaController::PATH, $png, $headers);
            $I->seeResponseCodeIs(200);
            $this->send($I, $snapshot);
            $I->seeResponseCodeIs(200);
            $I->assertSame(1, $this->change($I, 'comments_inserted'));
            $comment = $I->grabService(CommentRepository::class)->findForContent(ContentId::post($id), true)[0];
            $I->assertStringContainsString('<img ', $comment->text);
            $I->assertSame([], glob($root . '/private/*.part'));
            $I->sendJson(TelegramLiveMediaController::PATH, $probe, headers: ['X-Register-Telegram-Token' => self::TOKEN]);
            $I->seeResponseCodeIs(200);
            $response = $I->grabJson();
            $I->assertIsArray($response);
            $I->assertTrue($response['owned'] ?? false);
            $this->send($I, $snapshot);
            $I->seeResponseCodeIs(200);
            $I->assertSame(0, $this->change($I, 'comments_inserted'));
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($root);
        }
    }

    public function removesAndReordersAlreadyOwnedMediaInAFreshPublicRoot(\IntegrationTester $I): void
    {
        $id = $this->post($I);
        $this->enable($I);
        $root = sys_get_temp_dir() . '/register-media-reorder-' . bin2hex(random_bytes(6));
        mkdir($root, 0755);
        $managed = new TelegramManagedMediaStorage($root);
        $uploads = new TelegramMediaUploadStorage($root . '/private', 123, $I->grabService(ExternalImportMapRepository::class), $managed);
        $I->replaceService(TelegramManagedMediaStorage::class, $managed, [TelegramImportService::class, TelegramLiveReactionService::class, TelegramLiveImportController::class]);
        $I->replaceService(TelegramFileClientInterface::class, $uploads, [TelegramLiveImportController::class]);
        $I->replaceService(TelegramMediaUploadStorage::class, $uploads, [TelegramLiveMediaController::class]);
        try {
            $files = [
                'photo' => (string)file_get_contents(__DIR__ . '/../_resources/telegram-media/photo.png'),
                'sticker' => gzencode((string)file_get_contents(__DIR__ . '/../_resources/telegram-sticker.json')),
            ];
            $snapshot = $this->snapshot();
            $snapshot['messages'] = array_slice($snapshot['messages'], 0, 2);
            $media = [];
            foreach ($files as $uniqueId => $bytes) {
                $I->assertIsString($bytes);
                $extension = $uniqueId === 'photo' ? 'png' : 'tgs';
                $media[] = ['kind' => 'file', 'path' => 'live/2/' . (\count($media) + 1) . '-' . $uniqueId . '.' . $extension,
                    'file_id' => $uniqueId, 'file_unique_id' => $uniqueId, 'file_size' => \strlen($bytes),
                    'file_name' => $uniqueId . '.' . $extension, 'mime_type' => 'application/octet-stream', 'sticker' => $uniqueId === 'sticker', 'emoji' => ''];
            }

            $I->sendJson(TelegramLiveMediaController::PATH, ['chat_id' => 123, 'message_id' => 2,
                'files' => array_map(static fn(array $file): array => ['file_unique_id' => $file['file_unique_id'], 'file_size' => $file['file_size']], $media)],
                headers: ['X-Register-Telegram-Token' => self::TOKEN]);
            $I->seeResponseCodeIs(200);
            foreach ($files as $uniqueId => $bytes) {
                $I->assertIsString($bytes);
                $I->sendBinary(TelegramLiveMediaController::PATH, $bytes, [
                    'X-Register-Telegram-Token' => self::TOKEN, 'X-Register-Telegram-Chat' => '123',
                    'X-Register-Telegram-Message' => '2', 'X-Register-Telegram-File' => $uniqueId,
                    'X-Register-Telegram-Size' => (string)\strlen($bytes), 'X-Register-Telegram-Offset' => '0',
                ]);
                $I->seeResponseCodeIs(200);
            }

            $snapshot['messages'][1]['telegram_media'] = $media;
            $this->send($I, $snapshot);
            $I->seeResponseCodeIs(200);
            $I->assertSame([], glob($root . '/private/*.part'));
            $I->assertDirectoryExists($root . '/_pictures/telegram/comments/123/2');
            $I->assertDirectoryDoesNotExist($root . '/_pictures/bolknote');
            $snapshot['messages'][1]['telegram_media'] = [array_replace($media[1], ['path' => 'live/2/1-sticker.tgs'])];
            $snapshot['messages'][1]['edited_unixtime'] = '200';
            $snapshot['messages'][1]['bot_update_id'] = 200;
            $I->sendJson(TelegramLiveMediaController::PATH,
                ['chat_id' => 123, 'message_id' => 2, 'file_unique_id' => 'sticker'],
                headers: ['X-Register-Telegram-Token' => self::TOKEN]);
            $I->assertTrue($I->grabJson()['owned'] ?? false);
            $this->send($I, $snapshot);
            $I->seeResponseCodeIs(200);
            $I->assertSame(1, $this->change($I, 'comments_updated'));
            $comment = $I->grabService(CommentRepository::class)->findForContent(ContentId::post($id), true)[0];
            $I->assertStringContainsString('comment-sticker', $comment->text);
            $I->assertStringNotContainsString('<img ', $comment->text);
            $I->assertSame(1, substr_count($comment->text, 'data-animation='));
            $I->assertSame([], glob($root . '/private/*.part'));
            $this->send($I, $snapshot);
            $I->seeResponseCodeIs(200);
            $I->assertSame(0, $this->change($I, 'comments_updated'));

            $comments = $I->grabService(CommentRepository::class);
            $comments->edit($comment->id, ContentType::POST, 'Local correction');
            $newBytes = $files['photo'] . 'replacement';
            $uploads->append(2, 'replacement', \strlen($newBytes), 0, $newBytes);
            $snapshot['messages'][1]['telegram_media'] = [array_replace($media[0], [
                'path' => 'live/2/1-replacement.png', 'file_unique_id' => 'replacement', 'file_id' => 'replacement', 'file_size' => \strlen($newBytes),
            ])];
            $snapshot['messages'][1]['edited_unixtime'] = '201';
            $snapshot['messages'][1]['bot_update_id'] = 201;
            $this->send($I, $snapshot);
            $I->seeResponseCodeIs(200);
            $I->assertSame(1, $this->change($I, 'comments_local_edits_preserved'));
            $I->assertSame('Local correction', $comments->find($comment->id)?->text);
            $I->assertSame([], glob($root . '/private/*.part'));
            $I->assertSame([], glob($root . '/private/*.json'));
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($root);
        }
    }

    public function acceptsCompactAncestorsOnlyAfterImportAndNeverRewritesTheirSources(\IntegrationTester $I): void
    {
        $id = $this->post($I);
        $this->enable($I);
        $json = (string)file_get_contents(__DIR__ . '/../_resources/telegram-live-snapshot-part.json');
        $part = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        $this->send($I, $part);
        $I->seeResponseCodeIs(409);
        $I->assertSame(0, $I->grabService(CommentRepository::class)->count(ContentId::post($id), true));

        $snapshot = $this->snapshot();
        $snapshot['messages'] = array_slice($snapshot['messages'], 0, 2);
        $this->send($I, $snapshot);
        $I->seeResponseCodeIs(200);
        $map = $I->grabService(ExternalImportMapRepository::class)->find('telegram', '123', 'comment', '2');
        $this->send($I, $part);
        $I->seeResponseCodeIs(200);
        $I->assertSame(1, $this->change($I, 'comments_inserted'));
        $I->assertSame($map, $I->grabService(ExternalImportMapRepository::class)->find('telegram', '123', 'comment', '2'));
        $part['messages'][0]['id'] = 99;
        $part['messages'][1]['reply_to_message_id'] = 99;
        $this->send($I, $part);
        $I->seeResponseCodeIs(409);
        $part['messages'][1]['text'] = 'Do not overwrite the parent';
        $this->send($I, $part);
        $I->seeResponseCodeIs(422);
    }

    public function importsLongChainsInBoundedPartsWithoutTruncatingCommentText(\IntegrationTester $I): void
    {
        $id = $this->post($I);
        $this->enable($I);
        $snapshot = $this->snapshot();
        $root = $snapshot['messages'][0];
        $template = $snapshot['messages'][1];
        $references = [];
        for ($messageId = 2; $messageId <= 36; ++$messageId) {
            $comment = array_replace($template, ['id' => $messageId,
                'reply_to_message_id' => $messageId - 1, 'text' => str_repeat('x', 4096),
                'text_entities' => [['type' => 'plain', 'text' => str_repeat('x', 4096)]]]);
            $snapshot['messages'] = [$root, ...$references, $comment];
            $I->assertLessThanOrEqual(TelegramLiveImportController::MAX_BYTES, \strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)));
            $this->send($I, $snapshot);
            $I->seeResponseCodeIs(200);
            $references[] = ['id' => $messageId, 'type' => 'message', 'telegram_reference' => true, 'reply_to_message_id' => $messageId - 1];
        }

        $comments = $I->grabService(CommentRepository::class)->findForContent(ContentId::post($id), true);
        $I->assertCount(35, $comments);
        foreach ($comments as $comment) {
            $I->assertSame(4096, \strlen(strip_tags($comment->text)));
        }
    }

    public function resolvesGeneratedPostLinksAndAliasesUnderTheInstalledBasePath(\IntegrationTester $I): void
    {
        $id = $this->post($I);
        $this->enable($I);
        $db = $I->grabService(DbLayer::class);
        $db->update(ContentSchema::TABLE_NAME)->set('slug', ':slug')->where('id = :id')->execute(['slug' => 'all/apos', 'id' => $id]);
        $db->insert(ContentUrlAliasSchema::TABLE_NAME)->values(['path' => "'old-apos'", 'content_id' => ':id'])->execute(['id' => $id]);
        $application = $I->createApplication(['base_path' => '/blog', 'base_url' => 'http://register.localhost/blog']);
        $pdo = $I->grabService(\PDO::class);
        $config = $I->grabService(TelegramLiveImportConfig::class);
        $application->container->decorate(\PDO::class, static fn(): \PDO => $pdo);
        $application->container->decorate(DbLayer::class, static fn(): DbLayer => $db);
        $application->container->decorate(TelegramLiveImportConfig::class, static fn(): TelegramLiveImportConfig => $config);

        $url = $application->container->get(ContentUrlGenerator::class)->post('all/apos');
        $I->assertSame('/blog/all/apos', $url);
        $snapshot = $this->snapshot();
        foreach ([$url, '/blog/old-apos', '/apos', '/blog-other/apos'] as $path) {
            $snapshot['messages'][0]['text_entities'][0]['href'] = 'http://register.localhost' . $path;
            $response = $application->container->get(TelegramLiveImportController::class)->handle(Request::create(
                'http://register.localhost/blog' . TelegramLiveImportController::PATH, 'POST',
                server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REGISTER_TELEGRAM_TOKEN' => self::TOKEN],
                content: json_encode($snapshot, JSON_THROW_ON_ERROR),
            ));
            $I->assertSame(str_starts_with($path, '/blog/') ? 200 : 409, $response->getStatusCode());
        }

        $I->assertSame(2, $I->grabService(CommentRepository::class)->count(ContentId::post($id), true));
    }

    private function change(\IntegrationTester $I, string $name): int
    {
        $data = $I->grabJson();
        if (!\is_array($data) || !\is_int($data['changes'][$name] ?? null)) {
            throw new \UnexpectedValueException('The importer did not return a change count.');
        }

        return $data['changes'][$name];
    }

    /** @param array<string, mixed> $data */
    private function send(\IntegrationTester $I, array $data): void
    {
        $I->sendJson(TelegramLiveImportController::PATH, $data, headers: ['X-Register-Telegram-Token' => self::TOKEN]);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $json = file_get_contents(__DIR__ . '/../_resources/telegram-live-snapshot.json');
        if ($json === false) {
            throw new \RuntimeException('The Telegram snapshot fixture is missing.');
        }

        return json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    }

    private function postId(\IntegrationTester $I): int
    {
        return (int)$I->grabService(DbLayer::class)->select('id')->from(ContentSchema::TABLE_NAME)
            ->where('slug = :slug')->setParameter('slug', 'apos')->execute()->result();
    }

    private function post(\IntegrationTester $I): int
    {
        /** @var DbLayer $db */
        $db = $I->grabService(DbLayer::class);
        $db->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', "'post'")->setValue('parent_id', 'NULL')
            ->setValue('slug_scope', "'root'")->setValue('slug', "'apos'")
            ->setValue('title', "'Example post'")->setValue('excerpt', "''")->setValue('body', "'Post body'")
            ->setValue('created_at', '100')->setValue('published_at', '100')->setValue('updated_at', '100')
            ->setValue('revision', '1')->setValue('sort_order', '0')->setValue('published', '1')
            ->setValue('featured', '0')->setValue('comments_enabled', '1')->setValue('template', "'site.php'")
            ->execute();

        return (int)$db->insertId();
    }
}
