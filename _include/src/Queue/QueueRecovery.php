<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

final readonly class QueueRecovery
{
    public function __construct(private \PDO $pdo, private string $dbPrefix)
    {
    }

    /** Requeues one explicitly selected dead-letter job and invalidates any stale generation. */
    public function retryFailed(string $id, string $code, ?int $now = null): bool
    {
        if ($id === '' || $code === '') {
            throw new \InvalidArgumentException('A failed queue job id and code must not be empty.');
        }

        $now ??= time();
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->dbPrefix . 'queue SET generation = generation + 1, attempts = 0, '
            . 'updated_at = :updated_at, available_at = :available_at, last_error = NULL, failed_at = NULL '
            . 'WHERE id = :id AND code = :code AND failed_at IS NOT NULL'
        );
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the failed queue job recovery query.');
        }

        $statement->execute([
            'updated_at'   => $now,
            'available_at' => $now,
            'id'           => $id,
            'code'         => $code,
        ]);

        return $statement->rowCount() === 1;
    }
}
