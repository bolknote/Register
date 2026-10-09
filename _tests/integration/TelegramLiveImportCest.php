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
use Register\Import\Telegram\TelegramLiveImportConfig;
use Register\Import\Telegram\TelegramLiveImportController;
use Register\Module\Reactions\ReactionRepository;

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

    private function enable(\IntegrationTester $I): void
    {
        $I->replaceService(TelegramLiveImportConfig::class, new TelegramLiveImportConfig(
            self::TOKEN, -1_000_000_000_123, -1_000_000_000_111,
        ), [TelegramLiveImportController::class]);
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
