<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Content;

use Codeception\Test\Unit;
use Register\Content\ContentId;
use Register\Content\ContentViewRepository;
use Register\Content\ContentViewIncrement;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Pdo\PDO;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ContentViewRepositoryTest extends Unit
{
    public function testRejectsAStaleTotalWrittenAfterAConcurrentInvalidation(): void
    {
        [$repository, $cache, $pdo] = $this->repository();
        $contentId = ContentId::post(7);
        $hash = hash('sha256', (string)$contentId);
        $totalKey = 'content_view_total_v2_' . $hash;
        $versionKey = 'content_view_total_version_v1_' . $hash;

        $repository->record($contentId);
        self::assertSame(1, $repository->total($contentId));
        $oldVersion = $cache->getItem($versionKey)->get();
        self::assertIsString($oldVersion);

        $repository->record($contentId);
        self::assertSame(2, $repository->total($contentId));

        // Simulate an older worker completing its SELECT and cache write late.
        $stale = $cache->getItem($totalKey);
        $stale->set(['version' => $oldVersion, 'value' => 1]);

        $cache->save($stale);
        $pdo->cleanLogs();

        self::assertSame(2, $repository->total($contentId));
        self::assertNotSame([], $pdo->getQueryLog());
    }

    public function testDoesNotPublishAnUncommittedTotalAndKeepsTheCommittedValueOnRollback(): void
    {
        [$repository, $_cache, $pdo] = $this->repository();
        $contentId = ContentId::post(8);

        $repository->record($contentId);
        self::assertSame(1, $repository->total($contentId));

        $pdo->beginTransaction();
        $repository->record($contentId);
        self::assertSame(2, $repository->total($contentId));
        $pdo->rollBack();

        self::assertSame(1, $repository->total($contentId));
    }

    public function testAggregatesAWholeBatchIntoOneUpsert(): void
    {
        [$repository, , $pdo] = $this->repository();
        $contentId = ContentId::post(9);

        $pdo->clearState();
        $repository->recordBatch(
            new ContentViewIncrement($contentId, '2026-08-30', 2),
            new ContentViewIncrement($contentId, '2026-08-30', 3),
            new ContentViewIncrement(ContentId::page(10), '2026-08-30', 4),
        );

        self::assertSame(1, $pdo->getQueryCount());
        self::assertSame(5, $repository->total($contentId));
        self::assertSame(4, $repository->total(ContentId::page(10)));
    }

    /** @return array{ContentViewRepository, ArrayAdapter, PDO} */
    private function repository(): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(<<<'SQL'
            CREATE TABLE content_views_daily (
                content_type TEXT NOT NULL,
                content_id INTEGER NOT NULL,
                day TEXT NOT NULL,
                views INTEGER NOT NULL,
                PRIMARY KEY (content_type, content_id, day)
            )
            SQL);
        $cache = new ArrayAdapter();

        return [
            new ContentViewRepository(new DbLayerSqlite($pdo), $cache, $pdo),
            $cache,
            $pdo,
        ];
    }
}
