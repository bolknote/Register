<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Content;

use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use Register\Content\ContentId;
use Register\Content\ContentViewIncrement;
use Register\Content\ContentViewRepository;
use Register\Content\ContentViewSpool;
use Register\Content\ContentViewSpoolProcessor;
use Register\Content\ContentViewSpoolReceiptSchema;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Pdo\PDO;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;

final class ContentViewSpoolTest extends Unit
{
    private string $directory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/register_content_view_spool_' . bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testAggregatesSegmentsAndRemovesTheirReceipts(): void
    {
        [$pdo, $dbLayer, $repository, $spool, $processor] = $this->services();
        $now = 1_788_100_000;
        $spool->append(new ContentViewIncrement(ContentId::post(7), '2026-08-30'), $now);
        $spool->append(new ContentViewIncrement(ContentId::post(7), '2026-08-30'), $now);
        $spool->append(new ContentViewIncrement(ContentId::page(8), '2026-08-30'), $now);

        self::assertTrue($spool->hasDueWork($now));
        self::assertGreaterThan(0, $spool->sealDue($now));
        foreach ($spool->sealedSegments(16) as $segment) {
            $processor->process($segment);
        }

        self::assertSame(2, $repository->total(ContentId::post(7)));
        self::assertSame(1, $repository->total(ContentId::page(8)));
        self::assertSame(0, (int)$dbLayer->select('COUNT(*)')
            ->from(ContentViewSpoolReceiptSchema::TABLE_NAME)
            ->execute()
            ->result());
        self::assertFalse($spool->hasDueWork($now));
        self::assertFalse($pdo->inTransaction());
    }

    public function testExistingReceiptPreventsReplayAfterACommittedBatch(): void
    {
        [, $dbLayer, $repository, $spool, $processor] = $this->services();
        $now = 1_788_100_000;
        $increment = new ContentViewIncrement(ContentId::post(9), '2026-08-30');
        $spool->append($increment, $now);
        $spool->sealDue($now);

        $segment = $spool->sealedSegments()[0];

        // State left by a process that committed the database transaction and died before unlink().
        $repository->recordBatch($increment);
        $dbLayer->insert(ContentViewSpoolReceiptSchema::TABLE_NAME)
            ->setValue('receipt_id', ':receipt_id')->setParameter('receipt_id', $spool->segmentId($segment))
            ->setValue('created_at', ':created_at')->setParameter('created_at', $now)
            ->execute();

        self::assertSame(0, $processor->process($segment));
        self::assertSame(1, $repository->total(ContentId::post(9)));
        self::assertFileDoesNotExist($segment);
    }

    public function testOnlyOneWorkerCanClaimTheSameSegment(): void
    {
        [, , , $spool] = $this->services();
        $now = 1_788_100_000;
        $spool->append(new ContentViewIncrement(ContentId::post(10), '2026-08-30'), $now);
        $spool->sealDue($now);

        $segment = $spool->sealedSegments()[0];

        $firstLock = $spool->acquireSegment($segment);
        self::assertIsResource($firstLock);
        self::assertNull($spool->acquireSegment($segment));
        $spool->releaseSegment($segment, $firstLock);

        $secondLock = $spool->acquireSegment($segment);
        self::assertIsResource($secondLock);
        $spool->releaseSegment($segment, $secondLock);
    }

    public function testDeletedContentDoesNotPoisonAQueuedSegment(): void
    {
        [, , $repository, $spool, $processor] = $this->services();
        $now = 1_788_100_000;
        $spool->append(new ContentViewIncrement(ContentId::post(404), '2026-08-30'), $now);
        $spool->sealDue($now);

        $segment = $spool->sealedSegments()[0];
        self::assertSame(0, $processor->process($segment));
        self::assertSame(0, $repository->total(ContentId::post(404)));
        self::assertFileDoesNotExist($segment);
    }

    /**
     * @return array{PDO, DbLayerSqlite, ContentViewRepository, ContentViewSpool, ContentViewSpoolProcessor}
     */
    private function services(): array
    {
        $pdo = new PDO('sqlite::memory:');
        $dbLayer = new DbLayerSqlite($pdo);
        $dbLayer->query('CREATE TABLE content (
            id INTEGER NOT NULL PRIMARY KEY,
            content_type TEXT NOT NULL
        )');
        $dbLayer->query("INSERT INTO content (id, content_type) VALUES
            (7, 'post'), (8, 'page'), (9, 'post'), (10, 'post')");
        $dbLayer->query('CREATE TABLE content_views_daily (
            content_type TEXT NOT NULL,
            content_id INTEGER NOT NULL,
            day TEXT NOT NULL,
            views INTEGER NOT NULL,
            PRIMARY KEY (content_type, content_id, day)
        )');
        ContentViewSpoolReceiptSchema::create($dbLayer);
        $repository = new ContentViewRepository($dbLayer, new ArrayAdapter(), $pdo);
        $spool = new ContentViewSpool($this->directory, minimumSegmentAge: 0);

        return [
            $pdo,
            $dbLayer,
            $repository,
            $spool,
            new ContentViewSpoolProcessor($pdo, $dbLayer, $repository, $spool, new NullLogger()),
        ];
    }
}
