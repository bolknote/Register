<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Search\Service;

use Codeception\Test\Unit;
use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Content\ContentRepository;
use Register\Content\ContentSourceInterface;
use Register\Content\ContentType;
use Register\Module\Search\Service\ContentIndexer;
use Register\Module\Search\Service\SearchIndexRepairer;
use S2\Cms\Queue\QueueExecutionBudget;
use S2\Cms\Queue\QueuePublisher;
use S2\Rose\Entity\Indexable;
use S2\Rose\Indexer;
use S2\Rose\Stemmer\PorterStemmerEnglish;
use S2\Rose\Storage\Database\PdoStorage;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class SearchIndexRepairerTest extends Unit
{
    public function testPlansRetryableBatchesWithoutErasingTheLiveIndex(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createQueue($pdo);

        $storage = new PdoStorage($pdo, 'search_');
        $storage->erase();

        $indexer = new Indexer($storage, new PorterStemmerEnglish());
        $indexer->index((new Indexable('post:999', 'Still searchable', 'Old document'))->setUrl('/old'));

        $content = [];
        for ($id = 1; $id <= 51; ++$id) {
            $content[] = new ContentItem(ContentId::post($id), 'Post ' . $id, 'Body', '/post-' . $id, null);
        }

        $repairer = new SearchIndexRepairer(
            new ContentRepository(new RepairContentSource(...$content)),
            $storage,
            $indexer,
            new ArrayAdapter(),
            new QueuePublisher($pdo, ''),
        );

        $repairer->schedule(100);
        $repairer->handle(
            SearchIndexRepairer::JOB_ID,
            SearchIndexRepairer::REPAIR_QUEUE_CODE,
            ['offset' => 0],
            new QueueExecutionBudget(5.0),
        );

        self::assertSame(1, $storage->getTocSize(null), 'Planning must not erase the usable index.');
        self::assertSame(50, $this->jobCount($pdo, ContentIndexer::QUEUE_CODE));
        self::assertSame(
            '{"offset":50}',
            $this->jobPayload($pdo, SearchIndexRepairer::JOB_ID, SearchIndexRepairer::REPAIR_QUEUE_CODE),
        );

        $repairer->handle(
            SearchIndexRepairer::JOB_ID,
            SearchIndexRepairer::REPAIR_QUEUE_CODE,
            ['offset' => 50],
            new QueueExecutionBudget(5.0),
        );

        self::assertSame(51, $this->jobCount($pdo, ContentIndexer::QUEUE_CODE));
        self::assertSame(1, $this->jobCount($pdo, SearchIndexRepairer::REMOVE_QUEUE_CODE));
        self::assertSame(
            ':post:999',
            $this->jobId($pdo, SearchIndexRepairer::REMOVE_QUEUE_CODE),
        );
    }

    private function createQueue(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE queue (
    id VARCHAR(80) NOT NULL,
    code VARCHAR(80) NOT NULL,
    payload TEXT NOT NULL,
    generation INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    available_at INTEGER NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    failed_at INTEGER NULL,
    PRIMARY KEY (id, code)
)
SQL);
    }

    private function jobCount(\PDO $pdo, string $code): int
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM queue WHERE code = :code');
        self::assertNotFalse($statement);
        $statement->execute(['code' => $code]);

        return (int)$statement->fetchColumn();
    }

    private function jobPayload(\PDO $pdo, string $id, string $code): string
    {
        $statement = $pdo->prepare('SELECT payload FROM queue WHERE id = :id AND code = :code');
        self::assertNotFalse($statement);
        $statement->execute(['id' => $id, 'code' => $code]);
        $payload = $statement->fetchColumn();
        self::assertIsString($payload);

        return $payload;
    }

    private function jobId(\PDO $pdo, string $code): string
    {
        $statement = $pdo->prepare('SELECT id FROM queue WHERE code = :code');
        self::assertNotFalse($statement);
        $statement->execute(['code' => $code]);
        $id = $statement->fetchColumn();
        self::assertIsString($id);

        return $id;
    }
}

/** @internal */
final readonly class RepairContentSource implements ContentSourceInterface
{
    /** @var array<int, ContentItem> */
    private array $content;

    public function __construct(ContentItem ...$content)
    {
        $indexed = [];
        foreach ($content as $item) {
            $indexed[$item->id->value] = $item;
        }

        $this->content = $indexed;
    }

    #[\Override]
    public function type(): ContentType
    {
        return ContentType::POST;
    }

    #[\Override]
    public function find(ContentId $id): ?ContentItem
    {
        return $id->type === $this->type() ? ($this->content[$id->value] ?? null) : null;
    }

    /** @return iterable<ContentItem> */
    #[\Override]
    public function published(): iterable
    {
        yield from $this->content;
    }
}
