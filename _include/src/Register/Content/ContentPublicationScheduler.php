<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use S2\Cms\Pdo\DbLayer;

/** Publishes due drafts without exposing future content to public readers. */
final readonly class ContentPublicationScheduler
{
    public function __construct(
        private DbLayer                 $dbLayer,
        private \PDO                    $pdo,
        private ContentChangeDispatcher $contentChangeDispatcher,
    ) {
    }

    /**
     * A direct publication takes precedence over a date left in the scheduling field.
     *
     * @param array<string, mixed> $data
     */
    public function prepareForSave(array &$data): void
    {
        if ((bool)($data['published'] ?? false)) {
            // AdminYard converts the normalized null value to the database sentinel 0.
            $data['scheduled_at'] = null;
        }
    }

    /**
     * Publishes every due draft once and emits the normal content lifecycle event.
     *
     * The conditional update is the concurrency lock. When a queue publisher shares this PDO,
     * the publication and its search-index outbox entry are committed atomically.
     */
    public function publishDue(?int $now = null): int
    {
        $now ??= time();
        if ($now <= 0) {
            throw new \InvalidArgumentException('The publication timestamp must be positive.');
        }

        $result = $this->dbLayer
            ->select('id, content_type, scheduled_at')
            ->from(ContentSchema::TABLE_NAME)
            ->where('published = 0')
            ->andWhere('scheduled_at > 0')
            ->andWhere('scheduled_at <= :now')->setParameter('now', $now)
            ->orderBy('scheduled_at ASC', 'id ASC')
            ->execute()
        ;

        $published = 0;
        while (($row = $result->fetchAssoc()) !== false) {
            $published += $this->publishRow($row);
        }

        return $published;
    }

    /** @param array<string, mixed> $row */
    private function publishRow(array $row): int
    {
        $id          = (int)$row['id'];
        $scheduledAt = (int)$row['scheduled_at'];
        $contentType = ContentType::from((string)$row['content_type']);
        $outerTransaction = $this->pdo->inTransaction();

        if (!$outerTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $updated = $this->dbLayer
                ->update(ContentSchema::TABLE_NAME)
                ->set('published', '1')
                ->set('published_at', ':published_at')->setParameter('published_at', $scheduledAt)
                ->set('scheduled_at', '0')
                ->where('id = :id')->setParameter('id', $id)
                ->andWhere('content_type = :content_type')->setParameter('content_type', $contentType->value)
                ->andWhere('published = 0')
                ->andWhere('scheduled_at = :scheduled_at')->setParameter('scheduled_at', $scheduledAt)
                ->execute()
                ->affectedRows()
            ;

            if ($updated === 1) {
                if ($contentType === ContentType::PAGE) {
                    $this->contentChangeDispatcher->dispatchPageBranch($id);
                } else {
                    $this->contentChangeDispatcher->dispatch(ContentId::post($id));
                }
            }

            if (!$outerTransaction) {
                $this->pdo->commit();
            }

            return $updated === 1 ? 1 : 0;
        } catch (\Throwable $throwable) {
            if (!$outerTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $throwable;
        }
    }
}
