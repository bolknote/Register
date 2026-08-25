<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Import;

use Codeception\Test\Unit;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Import\ExternalImportMapRepository;
use Register\Import\ExternalImportMapSchema;
use Register\Import\Telegram\TelegramDiscussionArchive;

final class TelegramDiscussionArchiveTest extends Unit
{
    public function testExtractsOnlyExactSiteThreadsWithoutInstallationSpecificIds(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'register-telegram-');
        self::assertIsString($path);
        file_put_contents($path, json_encode([
            'id' => 123,
            'name' => 'Example discussion',
            'type' => 'private_supergroup',
            'messages' => [
                $this->root(1, 'channel111', 'https://example.test/post-a'),
                $this->comment(2, 1, 'Reader', 'user22', 'First'),
                $this->comment(3, 2, 'Site chat', 'channel123', 'Nested'),
                ['id' => 4, 'type' => 'message', 'date_unixtime' => '104', 'text' => 'General chat'],
                [
                    'id' => 5,
                    'type' => 'message',
                    'date_unixtime' => '105',
                    'forwarded_from_id' => 'channel111',
                    'text' => 'Channel post without a link',
                    'text_entities' => [['type' => 'plain', 'text' => 'Channel post without a link']],
                ],
                $this->root(6, 'channel999', 'https://elsewhere.test/not-ours'),
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $archive = TelegramDiscussionArchive::fromFile($path);
            $result = $archive->extract(
                static fn(string $postPath): ?array => $postPath === 'post-a'
                    ? ['content_id' => 7, 'canonical_path' => 'post-a']
                    : null,
                ['example.test'],
            );
        } finally {
            unlink($path);
        }

        self::assertSame(['channel111'], $result['source']['source_channel_ids']);
        self::assertSame(1, $result['stats']['accepted_threads']);
        self::assertSame(2, $result['stats']['comments']);
        self::assertSame(1, $result['stats']['excluded_roots']);
        self::assertSame(2, $result['stats']['unthreaded_messages']);
        self::assertFalse($result['threads'][0]['comments'][0]['author']['is_site_author']);
        self::assertTrue($result['threads'][0]['comments'][1]['author']['is_site_author']);
        self::assertNull(TelegramDiscussionArchive::normaliseSitePath(
            'https://example.test.evil.invalid/post-a',
            ['example.test'],
        ));
    }

    public function testExternalImportMapUpsertsStableIdentity(): void
    {
        $dbLayer = new DbLayerSqlite(new \PDO('sqlite::memory:'));
        ExternalImportMapSchema::create($dbLayer);
        $repository = new ExternalImportMapRepository($dbLayer);
        $repository->store(
            'telegram',
            '123',
            'comment',
            '2',
            'comment',
            8,
            str_repeat('a', 64),
            ['version' => 1],
            100,
        );
        $repository->store(
            'telegram',
            '123',
            'comment',
            '2',
            'comment',
            8,
            str_repeat('b', 64),
            ['version' => 2],
            110,
            100,
        );

        $entries = $repository->forScope('telegram', '123', 'comment');
        self::assertSame(1, $repository->count('telegram'));
        self::assertSame(str_repeat('b', 64), $entries[2]['source_hash']);
        self::assertSame(['version' => 2], $entries[2]['source_data']);
        self::assertSame(100, $entries[2]['created_at']);
        self::assertSame(110, $entries[2]['updated_at']);
    }

    /** @return array<string, mixed> */
    private function root(int $id, string $channelId, string $url): array
    {
        return [
            'id' => $id,
            'type' => 'message',
            'date_unixtime' => (string)(100 + $id),
            'forwarded_from_id' => $channelId,
            'text' => [['type' => 'text_link', 'text' => 'Post', 'href' => $url]],
            'text_entities' => [['type' => 'text_link', 'text' => 'Post', 'href' => $url]],
        ];
    }

    /** @return array<string, mixed> */
    private function comment(int $id, int $parentId, string $name, string $authorId, string $text): array
    {
        return [
            'id' => $id,
            'type' => 'message',
            'date_unixtime' => (string)(100 + $id),
            'reply_to_message_id' => $parentId,
            'from' => $name,
            'from_id' => $authorId,
            'text' => $text,
            'text_entities' => [['type' => 'plain', 'text' => $text]],
        ];
    }
}
