<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Register\Core\Queue\QueuePublisher;
use Register\Module\Search\Service\ContentIndexer;

/** Moves accidentally published future content into the existing publication queue. */
final readonly class FuturePublicationSchemaMigration implements SchemaMigrationInterface
{
    public function __construct(private QueuePublisher $queuePublisher)
    {
    }

    #[\Override]
    public function fromGeneration(): int
    {
        return 29;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 30;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        $now = time();
        $dbLayer
            ->update(ContentSchema::TABLE_NAME)
            ->set('scheduled_at', 'published_at')
            ->set('published_at', 'NULL')
            ->set('published', '0')
            ->where('published = 1')
            ->andWhere('published_at IS NOT NULL')
            ->andWhere('published_at > :now')->setParameter('now', $now)
            ->execute()
        ;

        // This migration can run after an accidentally public item has already reached search.
        // Queue every scheduled item rather than only the rows selected before the update: this
        // keeps retries safe if publishing a queue job fails halfway through the migration.
        $result = $dbLayer
            ->select('id, content_type')
            ->from(ContentSchema::TABLE_NAME)
            ->where('published = 0')
            ->andWhere('scheduled_at > 0')
            ->execute()
        ;
        while (($row = $result->fetchAssoc()) !== false) {
            $contentId = new ContentId(ContentType::from((string)$row['content_type']), (int)$row['id']);
            $this->queuePublisher->publish((string)$contentId, ContentIndexer::QUEUE_CODE);
        }
    }
}
