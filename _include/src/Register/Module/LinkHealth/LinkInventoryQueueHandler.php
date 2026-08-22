<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Core\Queue\QueuePublisher;

final readonly class LinkInventoryQueueHandler implements QueueHandlerInterface
{
    public const string JOB_ID = 'all-published-content';

    private const int BATCH_SIZE = 20;

    public function __construct(
        private DbLayer        $dbLayer,
        private LinkInventory  $linkInventory,
        private QueuePublisher $queuePublisher,
    ) {
    }

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return [LinkQueue::INVENTORY_CODE];
    }

    #[\Override]
    public function minimumExecutionTime(): float
    {
        return 0.2;
    }

    /** @param array<mixed> $payload */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        if ($id !== self::JOB_ID || $code !== LinkQueue::INVENTORY_CODE) {
            throw new \InvalidArgumentException('Invalid link-inventory job.');
        }

        $cursor = $payload['cursor'] ?? 0;
        if (!\is_int($cursor) || $cursor < 0 || array_diff_key($payload, ['cursor' => true]) !== []) {
            throw new \InvalidArgumentException('Invalid link-inventory cursor.');
        }

        $budget->checkpoint($this->minimumExecutionTime());
        $rows = $this->dbLayer
            ->select('id', 'content_type')
            ->from(ContentSchema::TABLE_NAME)
            ->where('published = 1')
            ->andWhere('id > :cursor')->setParameter('cursor', $cursor)
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->execute()
            ->fetchAssocAll()
        ;

        $this->linkInventory->refreshPathIndex();
        foreach ($rows as $row) {
            $budget->checkpoint(0.02);
            $cursor = (int)$row['id'];
            $this->linkInventory->synchronize(new ContentId(
                ContentType::from((string)$row['content_type']),
                $cursor,
            ), refreshPathIndex: false);
        }

        if (\count($rows) === self::BATCH_SIZE) {
            $this->queuePublisher->publish(
                self::JOB_ID,
                LinkQueue::INVENTORY_CODE,
                ['cursor' => $cursor],
                time() + 1,
            );
            return;
        }

        $this->dbLayer->update('config')
            ->set('value', ':value')->setParameter('value', (string)Manifest::INVENTORY_GENERATION)
            ->where('name = :name')->setParameter('name', Manifest::INVENTORY_GENERATION_CONFIG_KEY)
            ->execute()
        ;
    }
}
