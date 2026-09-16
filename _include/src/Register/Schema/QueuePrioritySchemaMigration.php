<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Content\ContentPublicationQueueHandler;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;
use Register\Core\Queue\QueuePublisher;

/** Adds generic queue priorities and promotes already queued scheduled publications. */
final readonly class QueuePrioritySchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 33;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 34;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        $dbLayer->addField(
            'queue',
            'priority',
            SchemaBuilderInterface::TYPE_INTEGER,
            null,
            false,
            QueuePublisher::PRIORITY_NORMAL,
            'available_at',
        );

        $dbLayer
            ->update('queue')
            ->set('priority', ':priority')->setParameter('priority', QueuePublisher::PRIORITY_HIGH)
            ->where('code = :code')->setParameter('code', ContentPublicationQueueHandler::CODE)
            ->execute()
        ;
    }
}
