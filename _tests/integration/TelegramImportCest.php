<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentIdAllocator;
use Register\Comment\CommentRepository;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Register\Import\Telegram\TelegramImportService;
use Register\Import\Telegram\TelegramManagedMediaStorage;
use Register\Import\Telegram\TelegramSettings;
use Register\Import\ExternalImportMapRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Register\Module\Reactions\Manifest as ReactionManifest;
use Register\Module\Reactions\ReactionRepository;

final class TelegramImportCest
{
    public function olderArchivesPreserveTheNewestCommentAndItsProvenance(\IntegrationTester $I): void
    {
        $contentId = ContentId::post($this->insertPost($I->grabService(DbLayer::class)));
        $path = tempnam(sys_get_temp_dir(), 'register-telegram-version-');
        $I->assertIsString($path);
        $importer = $I->grabService(TelegramImportService::class);
        $latest = $this->export(null, 'The newest comment');
        $latest['messages'][1]['edited_unixtime'] = '300';
        $latest['messages'][1]['bot_update_id'] = 300;
        try {
            $importer->importLiveSnapshot(json_encode($latest, JSON_THROW_ON_ERROR));
            $maps = $I->grabService(ExternalImportMapRepository::class);
            $mapping = $maps->find('telegram', '123', 'comment', '2');
            foreach ([null, '200'] as $editedAt) {
                $older = $this->export(null, 'An obsolete comment');
                if ($editedAt !== null) {
                    $older['messages'][1]['edited_unixtime'] = $editedAt;
                }

                file_put_contents($path, json_encode($older, JSON_THROW_ON_ERROR));
                $report = $importer->importFile($path);
                $I->assertSame(1, $report['changes']['comments_stale_ignored']);
                $I->assertSame($mapping, $maps->find('telegram', '123', 'comment', '2'));
                $comment = $I->grabService(CommentRepository::class)->findForContent($contentId)[0];
                $I->assertStringContainsString('The newest comment', $comment->text);
                $I->assertSame(300, $comment->modifyTime);
            }

            $sameVersion = $latest;
            unset($sameVersion['messages'][1]['bot_update_id']);
            file_put_contents($path, json_encode($sameVersion, JSON_THROW_ON_ERROR));
            $importer->importFile($path);
            $sameMapping = $maps->find('telegram', '123', 'comment', '2');
            $I->assertSame($mapping['source_hash'], $sameMapping['source_hash']);
            $I->assertSame(300, $sameMapping['source_data']['telegram']['comment']['bot_update_id']);
            $delayed = $latest;
            $delayed['messages'][1]['bot_update_id'] = 299;
            $delayed['messages'][1]['text'] = 'A delayed same-second edit';
            $delayed['messages'][1]['text_entities'] = [['type' => 'plain', 'text' => $delayed['messages'][1]['text']]];
            $I->assertSame(1, $importer->importLiveSnapshot(json_encode($delayed, JSON_THROW_ON_ERROR))['changes']['comments_stale_ignored']);

            $newer = $this->export(null, 'A newer archive correction');
            $newer['messages'][1]['edited_unixtime'] = '400';
            file_put_contents($path, json_encode($newer, JSON_THROW_ON_ERROR));
            $I->assertSame(1, $importer->importFile($path)['changes']['comments_updated']);
        } finally {
            unlink($path);
        }
    }

    public function adminArchiveImportUsesTheSelectedAuthorInsteadOfTheUploadingAdministrator(\IntegrationTester $I): void
    {
        $contentId = ContentId::post($this->insertPost($I->grabService(DbLayer::class)));
        $authorId = (int)$I->grabService(DbLayer::class)->select('id')->from('users')->where("login = 'author'")->execute()->result();
        $I->setConfigValue(TelegramSettings::OWNER_TELEGRAM_ID, '22');
        $I->setConfigValue(TelegramSettings::AUTHOR_ID, (string)$authorId);
        $I->login('admin', 'admin');

        $path = tempnam(sys_get_temp_dir(), 'register-telegram-admin-author-');
        $I->assertIsString($path);
        try {
            file_put_contents($path, json_encode($this->export(null, 'An author comment'), JSON_THROW_ON_ERROR));
            $I->amOnPage('https://localhost/_admin/index.php?entity=TelegramImport');
            $token = $I->grabValueFrom('[data-telegram-import-form] input[name="csrf_token"]');
            $I->assertIsString($token);
            $I->sendPost('https://localhost/_admin/ajax.php?action=register_telegram_import',
                ['csrf_token' => $token], ['telegram_export' => new UploadedFile($path, 'result.json', 'application/json', UPLOAD_ERR_OK, true)]);
            $I->seeResponseCodeIs(200);
            $I->assertSame($authorId, $I->grabService(CommentRepository::class)->findForContent($contentId)[0]->userId);
        } finally {
            unlink($path);
        }
    }

    public function archiveImportRecognisesTheConfiguredPersonalAuthorWhenTheBotIsDisabled(\IntegrationTester $I): void
    {
        $db = $I->grabService(DbLayer::class);
        $contentId = ContentId::post($this->insertPost($db));
        $authorId = (int)$db->select('id')->from('users')->where("login = 'author'")->execute()->result();
        foreach ([TelegramSettings::OWNER_TELEGRAM_ID => '22', TelegramSettings::AUTHOR_ID => (string)$authorId] as $key => $value) {
            $I->setConfigValue($key, $value);
        }

        $path = tempnam(sys_get_temp_dir(), 'register-telegram-author-');
        $I->assertIsString($path);
        try {
            $export = $this->export(null, 'A personal author comment');
            $export['messages'][] = array_replace($export['messages'][1], ['id' => 3, 'from_id' => 'user33', 'from' => 'Guest']);
            file_put_contents($path, json_encode($export, JSON_THROW_ON_ERROR));
            $importer = $I->grabService(TelegramImportService::class);
            $importer->importFile($path);
            $comments = $I->grabService(CommentRepository::class)->findForContent($contentId);
            $I->assertSame($authorId, $comments[0]->userId);
            $I->assertNull($comments[1]->userId);
            $I->assertSame('Guest', $comments[1]->name);
            $importer->importFile($path);
            $I->assertSame($authorId, $I->grabService(CommentRepository::class)->find($comments[0]->id)?->userId);
        } finally {
            unlink($path);
        }
    }

    public function preservesLocalSiteReactionsWhileReconcilingTelegramAggregates(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $contentId = $this->insertPost($dbLayer);
        $jsonPath = tempnam(sys_get_temp_dir(), 'register-telegram-reactions-');
        $I->assertIsString($jsonPath);

        try {
            $firstExport = $this->export(null, 'A Telegram comment', [
                ['type' => 'emoji', 'count' => 2, 'emoji' => '👀'],
                ['type' => 'emoji', 'count' => 1, 'emoji' => '👎'],
            ]);
            $I->assertNotFalse(file_put_contents(
                $jsonPath,
                json_encode($firstExport, JSON_THROW_ON_ERROR),
            ));

            /** @var TelegramImportService $importService */
            $importService = $I->grabService(TelegramImportService::class);
            $firstReport = $importService->importFile($jsonPath, clientOriginalName: 'result.json');
            $I->assertSame(2, $firstReport['changes']['reaction_groups_inserted']);

            /** @var ReactionRepository $reactionRepository */
            $reactionRepository = $I->grabService(ReactionRepository::class);
            $I->assertSame(['👀' => 2, '👎' => 1], $reactionRepository->state($contentId)->extraCounts);

            $I->sendJson('https://localhost/_visitor/resolve', [
                'trackPage' => false,
            ], headers: ['Origin' => 'https://localhost']);
            $I->seeResponseCodeIs(200);
            $I->sendJson('https://localhost/_reactions/post/' . $contentId, [
                'reaction' => '👀',
            ], headers: ['Origin' => 'https://localhost']);
            $I->seeResponseCodeIs(200);
            $state = $I->grabJson();
            $I->assertIsArray($state);
            $I->assertSame(['👀' => 3, '👎' => 1], $state['extra']);

            $secondExport = $this->export(null, 'A Telegram comment', [
                ['type' => 'emoji', 'count' => 4, 'emoji' => '👀'],
                ['type' => 'emoji', 'count' => 1, 'emoji' => '👎'],
            ]);
            $I->assertNotFalse(file_put_contents(
                $jsonPath,
                json_encode($secondExport, JSON_THROW_ON_ERROR),
            ));
            $secondReport = $importService->importFile($jsonPath, clientOriginalName: 'result.json');
            $I->assertSame(1, $secondReport['changes']['reaction_groups_updated']);
            $I->assertSame(1, $secondReport['changes']['reaction_groups_unchanged']);

            $I->assertSame(1, (int)$dbLayer->select('COUNT(*)')
                ->from(ReactionManifest::TABLE_NAME)
                ->where('content_id = :content_id')->setParameter('content_id', $contentId)
                ->execute()
                ->result());
            $finalState = $reactionRepository->state($contentId);
            $I->assertSame(['👀' => 5, '👎' => 1], $finalState->extraCounts);
            $I->assertSame(6, $finalState->toArray()['total']);
        } finally {
            if (is_file($jsonPath)) {
                unlink($jsonPath);
            }
        }
    }

    public function repairsAndPreservesAttachmentsAcrossPartialImports(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $contentId = $this->insertPost($dbLayer);
        $publicRoot = sys_get_temp_dir() . '/register-telegram-import-' . bin2hex(random_bytes(6)) . '/';
        $I->assertTrue(mkdir($publicRoot, 0755, true));
        $I->replaceService(
            TelegramManagedMediaStorage::class,
            new TelegramManagedMediaStorage($publicRoot),
            [TelegramImportService::class],
        );

        $jsonPath = tempnam(sys_get_temp_dir(), 'register-telegram-json-');
        $zipPath = tempnam(sys_get_temp_dir(), 'register-telegram-zip-');
        $I->assertIsString($jsonPath);
        $I->assertIsString($zipPath);

        $storedFile = null;
        try {
            $missingExport = $this->export(
                '(File not included. Change data exporting settings to download.)',
            );
            $I->assertNotFalse(file_put_contents(
                $jsonPath,
                json_encode($missingExport, JSON_THROW_ON_ERROR),
            ));

            /** @var TelegramImportService $importService */
            $importService = $I->grabService(TelegramImportService::class);
            $firstReport = $importService->importFile($jsonPath, clientOriginalName: 'result.json');
            $I->assertSame(1, $firstReport['changes']['comments_inserted']);
            $I->assertSame(1, $firstReport['changes']['comments_media_unavailable']);

            /** @var CommentRepository $commentRepository */
            $commentRepository = $I->grabService(CommentRepository::class);
            $comments = $commentRepository->findForContent(ContentId::post($contentId));
            $I->assertCount(1, $comments);
            $commentId = $comments[0]->id;
            $I->assertStringContainsString('comment-media-missing', $comments[0]->text);
            $I->assertStringNotContainsString('File not included', $comments[0]->text);

            $zip = new \ZipArchive();
            $I->assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
            $fullExport = $this->export('photos/original.png');
            $I->assertTrue($zip->addFromString(
                'ChatExport/result.json',
                json_encode($fullExport, JSON_THROW_ON_ERROR),
            ));
            $png = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true,
            );
            $I->assertIsString($png);
            $I->assertTrue($zip->addFromString('ChatExport/photos/original.png', $png));
            $I->assertTrue($zip->close());

            $secondReport = $importService->importFile($zipPath, clientOriginalName: 'ChatExport.zip');
            $I->assertSame(0, $secondReport['changes']['comments_inserted']);
            $I->assertSame(1, $secondReport['changes']['comments_updated']);
            $I->assertSame(1, $secondReport['changes']['comments_media_repaired']);
            $I->assertSame(1, $secondReport['changes']['comments_media_available']);

            $comments = $commentRepository->findForContent(ContentId::post($contentId));
            $I->assertCount(1, $comments);
            $I->assertSame($commentId, $comments[0]->id);
            $I->assertStringContainsString('<figure class="comment-media"><img ', $comments[0]->text);
            $I->assertStringNotContainsString('comment-media-missing', $comments[0]->text);
            if (preg_match('~src="(/_pictures/telegram/comments/[^"]+)"~', $comments[0]->text, $matches) !== 1) {
                throw new \RuntimeException('The imported comment has no managed media URL.');
            }

            $storedFile = rtrim($publicRoot, '/') . $matches[1];
            $I->assertFileExists($storedFile);

            $textOnlyExport = $this->export(
                '(File not included. Change data exporting settings to download.)',
                'Updated text from Telegram',
            );
            $I->assertNotFalse(file_put_contents(
                $jsonPath,
                json_encode($textOnlyExport, JSON_THROW_ON_ERROR),
            ));

            $thirdReport = $importService->importFile($jsonPath, clientOriginalName: 'result.json');
            $I->assertSame(1, $thirdReport['changes']['comments_updated']);
            $I->assertSame(1, $thirdReport['changes']['comments_media_available']);
            $I->assertSame(0, $thirdReport['changes']['comments_media_unavailable']);
            $I->assertSame(1, $thirdReport['changes']['comments_media_preserved']);

            $comments = $commentRepository->findForContent(ContentId::post($contentId));
            $I->assertCount(1, $comments);
            $I->assertSame($commentId, $comments[0]->id);
            $I->assertStringContainsString('Updated text from Telegram', $comments[0]->text);
            $I->assertStringContainsString('<figure class="comment-media"><img ', $comments[0]->text);
            $I->assertStringNotContainsString('comment-media-missing', $comments[0]->text);
            $I->assertFileExists($storedFile);

            $withoutReferenceExport = $this->export(null, 'Updated again without a media reference');
            $I->assertNotFalse(file_put_contents(
                $jsonPath,
                json_encode($withoutReferenceExport, JSON_THROW_ON_ERROR),
            ));

            $fourthReport = $importService->importFile($jsonPath, clientOriginalName: 'result.json');
            $I->assertSame(1, $fourthReport['changes']['comments_updated']);
            $I->assertSame(1, $fourthReport['changes']['comments_media_preserved']);

            $comments = $commentRepository->findForContent(ContentId::post($contentId));
            $I->assertCount(1, $comments);
            $I->assertStringContainsString('Updated again without a media reference', $comments[0]->text);
            $I->assertStringContainsString('<figure class="comment-media"><img ', $comments[0]->text);
            $I->assertStringNotContainsString('comment-media-missing', $comments[0]->text);
            $I->assertFileExists($storedFile);
        } finally {
            if ($storedFile !== null && is_file($storedFile)) {
                unlink($storedFile);
            }

            (new \Symfony\Component\Filesystem\Filesystem())->remove($publicRoot);
            if (is_file($jsonPath)) {
                unlink($jsonPath);
            }

            if (is_file($zipPath)) {
                unlink($zipPath);
            }
        }
    }

    public function distinguishesDocumentsNamedTgsFromAnimatedStickersInZipExports(\IntegrationTester $I): void
    {
        $contentId = ContentId::post($this->insertPost($I->grabService(DbLayer::class)));
        $root = sys_get_temp_dir() . '/register-telegram-document-' . bin2hex(random_bytes(6));
        mkdir($root . '/public', 0755, true);
        $I->replaceService(TelegramManagedMediaStorage::class, new TelegramManagedMediaStorage($root . '/public'), [TelegramImportService::class]);
        try {
            $export = $this->export(null, 'A regular document');
            $export['messages'][1]['file'] = 'files/document.tgs';
            $export['messages'][1]['file_name'] = 'document.tgs';
            $export['messages'][1]['media_type'] = 'file';
            $export['messages'][] = array_replace($export['messages'][1], [
                'id' => 3, 'reply_to_message_id' => 2, 'text' => '', 'text_entities' => [],
                'file' => 'files/sticker.tgs', 'file_name' => 'sticker.tgs', 'media_type' => 'sticker',
            ]);
            $document = "A regular document with a .tgs filename\n";
            $sticker = gzencode((string)file_get_contents(__DIR__ . '/../_resources/telegram-sticker.json'));
            $I->assertIsString($sticker);
            $zip = new \ZipArchive();
            $I->assertTrue($zip->open($root . '/export.zip', \ZipArchive::CREATE));
            $I->assertTrue($zip->addFromString('result.json', json_encode($export, JSON_THROW_ON_ERROR)));
            $I->assertTrue($zip->addFromString('files/document.tgs', $document));
            $I->assertTrue($zip->addFromString('files/sticker.tgs', $sticker));
            $I->assertTrue($zip->close());
            $importer = $I->grabService(TelegramImportService::class);
            $report = $importer->importFile($root . '/export.zip');
            $I->assertSame(2, $report['changes']['comments_inserted']);
            $comments = $I->grabService(CommentRepository::class)->findForContent($contentId);
            $I->assertCount(2, $comments);
            $I->assertStringContainsString('comment-media-file', $comments[0]->text);
            $I->assertStringContainsString('document.tgs', $comments[0]->text);
            $I->assertStringNotContainsString('comment-sticker', $comments[0]->text);
            $I->assertStringContainsString('comment-sticker', $comments[1]->text);
            $stored = glob($root . '/public/_pictures/telegram/comments/123/2/*.bin');
            $I->assertIsArray($stored);
            $I->assertCount(1, $stored);
            $I->assertSame($document, file_get_contents($stored[0]));
            $I->assertSame(0, $importer->importFile($root . '/export.zip')['changes']['comments_updated']);
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($root);
        }
    }

    private function insertPost(DbLayer $dbLayer): int
    {
        $dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::POST->value)
            ->setValue('parent_id', 'NULL')
            ->setValue('slug_scope', "'root'")
            ->setValue('slug', "'apos'")
            ->setValue('title', "'Telegram import test'")
            ->setValue('excerpt', "''")
            ->setValue('body', "'Post text'")
            ->setValue('created_at', '100')
            ->setValue('published_at', '100')
            ->setValue('updated_at', '100')
            ->setValue('revision', '1')
            ->setValue('sort_order', '0')
            ->setValue('published', '1')
            ->setValue('featured', '0')
            ->setValue('comments_enabled', '1')
            ->setValue('template', "'site.php'")
            ->setValue('id', (string)ContentIdAllocator::allocate($dbLayer))
            ->execute()
        ;

        return (int)$dbLayer->insertId();
    }

    /**
     * @param list<array{type: string, count: int, emoji: string}> $rootReactions
     * @return array<string, mixed>
     */
    private function export(?string $photoPath, string $text = '', array $rootReactions = []): array
    {
        $comment = [
            'id' => 2,
            'type' => 'message',
            'date_unixtime' => '101',
            'reply_to_message_id' => 1,
            'from' => 'Reader',
            'from_id' => 'user22',
            'text' => $text,
            'text_entities' => $text === '' ? [] : [['type' => 'plain', 'text' => $text]],
        ];
        if ($photoPath !== null) {
            $comment['photo'] = $photoPath;
        }

        $root = [
            'id' => 1,
            'type' => 'message',
            'date_unixtime' => '100',
            'forwarded_from_id' => 'channel111',
            'text' => [[
                'type' => 'text_link',
                'text' => 'Post',
                'href' => 'http://register.localhost/apos',
            ]],
            'text_entities' => [[
                'type' => 'text_link',
                'text' => 'Post',
                'href' => 'http://register.localhost/apos',
            ]],
        ];
        if ($rootReactions !== []) {
            $root['reactions'] = $rootReactions;
        }

        return [
            'id' => 123,
            'name' => 'Example discussion',
            'type' => 'private_supergroup',
            'messages' => [
                $root,
                $comment,
            ],
        ];
    }
}
