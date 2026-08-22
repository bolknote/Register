<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

use S2\Cms\Pdo\DbLayer;

/** Records the latest ActivityPub handler entered by the shared shutdown runner. */
final readonly class ActivityPubRunnerTelemetryRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function record(string $code, int $now): void
    {
        if ($now < 1
            || \strlen($code) > 80
            || preg_match('/^[a-z0-9_]+$/D', $code) !== 1
        ) {
            throw new \InvalidArgumentException('ActivityPub runner telemetry is invalid.');
        }

        $updated = $this->dbLayer->update(ActivityPubSchema::STATE_TABLE)
            ->set('last_runner_at', ':last_runner_at')->setParameter('last_runner_at', $now)
            ->set('last_runner_code', ':last_runner_code')->setParameter('last_runner_code', $code)
            ->where('id = :id')->setParameter('id', 'installation')
            ->execute()
            ->affectedRows()
        ;
        if ($updated !== 1) {
            throw new \RuntimeException('The ActivityPub runner telemetry row is missing.');
        }
    }

    /** @return array{last_runner_at: int|null, last_runner_code: string} */
    public function status(): array
    {
        $row = $this->dbLayer->select('last_runner_at', 'last_runner_code')
            ->from(ActivityPubSchema::STATE_TABLE)
            ->where('id = :id')->setParameter('id', 'installation')
            ->execute()
            ->fetchAssoc()
        ;
        if (!\is_array($row)) {
            throw new \RuntimeException('The ActivityPub runner telemetry row is missing.');
        }

        $lastRunnerAt = $row['last_runner_at'] ?? null;
        $lastRunnerCode = $row['last_runner_code'] ?? null;
        if (($lastRunnerAt !== null && !is_numeric($lastRunnerAt)) || !\is_string($lastRunnerCode)) {
            throw new \RuntimeException('The stored ActivityPub runner telemetry is invalid.');
        }

        return [
            'last_runner_at'   => $lastRunnerAt === null ? null : (int)$lastRunnerAt,
            'last_runner_code' => $lastRunnerCode,
        ];
    }
}
