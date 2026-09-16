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
use Register\Import\Telegram\TelegramManagedMediaStorage;
use Register\Module\Reactions\Manifest as ReactionManifest;
use Register\Module\Reactions\ReactionRepository;

final class TelegramImportCest
{
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
        $I->assertTrue(mkdir($publicRoot . '_pictures/bolknote/comments', 0755, true));
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
            if (preg_match('~src="(/_pictures/bolknote/comments/telegram/[^"]+)"~', $comments[0]->text, $matches) !== 1) {
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

            foreach (['123/2', '123', ''] as $suffix) {
                $directory = $publicRoot . '_pictures/bolknote/comments/telegram'
                    . ($suffix === '' ? '' : '/' . $suffix);
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }

            foreach ([
                $publicRoot . '_pictures/bolknote/comments',
                $publicRoot . '_pictures/bolknote',
                $publicRoot . '_pictures',
                $publicRoot,
            ] as $directory) {
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }

            if (is_file($jsonPath)) {
                unlink($jsonPath);
            }

            if (is_file($zipPath)) {
                unlink($zipPath);
            }
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
