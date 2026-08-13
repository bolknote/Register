<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

use S2\Cms\Comment\Antispam\SpamMaintenance;
use S2\Cms\Comment\Antispam\SpamMaintenanceQueueHandler;

final readonly class ScheduledMaintenance
{
    public const int INTERVAL_SECONDS = 3600;

    private const string CONFIG_KEY = 'S2_LAST_MAINTENANCE';

    private const int INITIAL_QUEUE_DELAY_SECONDS = 1;

    public function __construct(
        private \PDO          $pdo,
        private string        $dbPrefix,
        private QueuePublisher $queuePublisher,
    ) {
    }

    public function runIfDue(?int $now = null): bool
    {
        $now ??= time();
        $statement = $this->pdo->prepare(
            'SELECT value FROM ' . $this->dbPrefix . 'config WHERE name = :name'
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the maintenance schedule query.');
        }

        $statement->execute(['name' => self::CONFIG_KEY]);
        $lastValue = $statement->fetchColumn();
        if (!\is_string($lastValue)) {
            throw new \UnexpectedValueException('The maintenance schedule is missing from configuration.');
        }

        $lastMaintenance = filter_var($lastValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($lastMaintenance !== false && $lastMaintenance > $now - self::INTERVAL_SECONDS) {
            return false;
        }

        foreach (SpamMaintenance::OPERATIONS as $operation) {
            $this->queuePublisher->publish(
                $operation,
                SpamMaintenanceQueueHandler::CODE,
                ['scheduled_at' => $now],
                $now + self::INITIAL_QUEUE_DELAY_SECONDS,
            );
        }

        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->dbPrefix . 'config SET value = :now WHERE name = :name AND value = :previous_value'
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the maintenance completion query.');
        }

        $statement->execute([
            'now'            => (string)$now,
            'name'           => self::CONFIG_KEY,
            'previous_value' => $lastValue,
        ]);

        return true;
    }
}
