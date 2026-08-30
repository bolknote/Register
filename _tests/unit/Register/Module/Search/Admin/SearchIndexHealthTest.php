<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Search\Admin;

use Codeception\Test\Unit;
use Register\Module\Search\Admin\SearchIndexHealth;
use Register\Module\Search\Admin\SearchIndexHealthStatus;
use Register\Module\Search\Admin\TranslationProvider;
use Register\Module\Search\Service\BulkIndexingProviderInterface;
use Register\Module\Search\Service\ContentIndexer;
use Register\Module\Search\Service\SearchIndexRepairer;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Rose\Entity\Indexable;
use Register\Rose\Indexer;
use Register\Rose\Stemmer\PorterStemmerEnglish;
use Register\Rose\Storage\Database\PdoStorage;

final class SearchIndexHealthTest extends Unit
{
    public function testReportsCurrentAndAutomaticallyUpdatingIndexes(): void
    {
        [$pdo, $storage, $indexer] = $this->environment('health_');
        $page = (new Indexable('page:1', 'Home', 'Welcome'))->setUrl('/');
        $post = (new Indexable('post:2', 'Post', 'Original body'))->setUrl('/post');
        $indexer->index($page);
        $indexer->index($post);

        $health = new SearchIndexHealth($storage, new DbLayerSqlite($pdo), $this->provider($page, $post));
        $status = $health->inspect();

        self::assertTrue($status->isCurrent());
        self::assertSame(2, $status->expectedDocuments);
        self::assertSame(2, $status->indexedDocuments);
        self::assertSame(2, $status->currentDocuments);
        self::assertSame(0, $status->mismatchedDocuments);

        $changedPost = (new Indexable('post:2', 'Post', 'Changed body'))->setUrl('/post');
        $pdo->exec("INSERT INTO queue (id, code, payload) VALUES ('post:2', '" . ContentIndexer::QUEUE_CODE . "', '{}')");
        $updating = (new SearchIndexHealth(
            $storage,
            new DbLayerSqlite($pdo),
            $this->provider($page, $changedPost),
        ))->inspect();

        self::assertTrue($updating->isUpdating());
        self::assertFalse($updating->repairRequired);
        self::assertSame(1, $updating->pendingUpdates);
        self::assertSame(1, $updating->currentDocuments);
        self::assertSame(1, $updating->mismatchedDocuments);
    }

    public function testRecommendsRepairForUnqueuedMismatchOrExtraDocument(): void
    {
        [$pdo, $storage, $indexer] = $this->environment('repair_');
        $stored = (new Indexable('post:1', 'Post', 'Stored'))->setUrl('/post');
        $indexer->index($stored);

        $changed = (new Indexable('post:1', 'Post', 'Changed'))->setUrl('/post');
        $mismatch = (new SearchIndexHealth(
            $storage,
            new DbLayerSqlite($pdo),
            $this->provider($changed),
        ))->inspect();
        self::assertTrue($mismatch->repairRequired);

        $indexer->index((new Indexable('post:2', 'Extra', 'Extra'))->setUrl('/extra'));
        $extra = (new SearchIndexHealth(
            $storage,
            new DbLayerSqlite($pdo),
            $this->provider($stored),
        ))->inspect();
        self::assertTrue($extra->repairRequired);
        self::assertSame(2, $extra->indexedDocuments);
    }

    public function testReportsUnavailableStorageAsRepairable(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $this->createQueue($pdo);
        $status = (new SearchIndexHealth(
            new PdoStorage($pdo, 'missing_'),
            new DbLayerSqlite($pdo),
            $this->provider(),
        ))->inspect();

        self::assertFalse($status->available);
        self::assertTrue($status->repairRequired);
        self::assertFalse($status->isCurrent());
    }

    public function testReportsAnActiveRepairInsteadOfRequestingManualRecovery(): void
    {
        [$pdo, $storage] = $this->environment('repairing_');
        $missing = (new Indexable('post:1', 'Post', 'Missing'))->setUrl('/post');
        $pdo->exec("INSERT INTO queue (id, code, payload) VALUES ('all', '"
            . SearchIndexRepairer::REPAIR_QUEUE_CODE . "', '{\"offset\":0}')");

        $status = (new SearchIndexHealth(
            $storage,
            new DbLayerSqlite($pdo),
            $this->provider($missing),
        ))->inspect();

        self::assertTrue($status->repairPending);
        self::assertTrue($status->isUpdating());
        self::assertFalse($status->repairRequired);
        self::assertSame(0, $status->currentDocuments);
    }

    public function testRepairDashboardShowsActualProgressSeparatelyFromCoverage(): void
    {
        $health = new SearchIndexHealthStatus(true, 7807, 7807, 304, 51, 7503, true, false);
        $translations = (new TranslationProvider())->getTranslations('Russian', 'ru');
        $trans = static function (string $key, array $parameters = []) use ($translations): string {
            $text = (string)($translations[$key] ?? $key);

            return strtr($text, array_map(static fn(mixed $value): string => (string)$value, $parameters));
        };
        $friendlyFilesize = static fn(int $bytes): string => $bytes . ' Б';
        $rows = 1_309_481;
        $bytes = 95_556_812;
        $basePath = '';
        $csrfToken = 'test-token';

        ob_start();
        require dirname(__DIR__, 6) . '/_include/src/Register/Module/Search/resources/views/dashboard/search-item.php.inc';
        $html = (string)ob_get_clean();

        self::assertStringContainsString('Индекс перестраивается — 4%.', $html);
        self::assertStringContainsString('max="7807"', $html);
        self::assertStringContainsString('value="304"', $html);
        self::assertStringContainsString('Актуальны 304 из 7807; осталось 7503.', $html);
        self::assertStringContainsString('Актуальный формат: 304 из 7807', $html);
        self::assertStringContainsString('Присутствуют в индексе: 7807 из 7807', $html);
    }

    /** @return array{\PDO, PdoStorage, Indexer} */
    private function environment(string $prefix): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $this->createQueue($pdo);
        $storage = new PdoStorage($pdo, $prefix);
        $storage->erase();

        return [$pdo, $storage, new Indexer($storage, new PorterStemmerEnglish())];
    }

    private function createQueue(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE queue (
    id VARCHAR(80) NOT NULL,
    code VARCHAR(80) NOT NULL,
    payload TEXT NOT NULL,
    failed_at INTEGER NULL,
    PRIMARY KEY (id, code)
)
SQL);
    }

    private function provider(Indexable ...$indexables): BulkIndexingProviderInterface
    {
        return new InMemoryBulkIndexingProvider(...$indexables);
    }
}

/** @internal */
final readonly class InMemoryBulkIndexingProvider implements BulkIndexingProviderInterface
{
    /** @var list<Indexable> */
    private array $indexables;

    public function __construct(Indexable ...$indexables)
    {
        $this->indexables = array_values($indexables);
    }

    #[\Override]
    public function getIndexables(): \Generator
    {
        yield from $this->indexables;
    }
}
