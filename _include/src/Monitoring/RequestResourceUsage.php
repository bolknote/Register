<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Monitoring;

/** Per-request CPU deltas, not process-lifetime counters or network/database wait time. */
final class RequestResourceUsage
{
    /** @var array<string, int>|false|null */
    private array|false|null $bootstrapUsage = null;

    private ?float $bootstrapFinishedAt = null;

    /** @var \Closure(): (array<string, int>|false|null) */
    private readonly \Closure $reader;

    /**
     * @param array<string, int>|null $startedUsage
     * @param (\Closure(): (array<string, int>|false|null))|null $reader
     */
    public function __construct(
        private readonly float $startedAt,
        private readonly ?array $startedUsage,
        ?\Closure $reader = null,
    ) {
        $this->reader = $reader ?? static function (): ?array {
            if (!\function_exists('getrusage')) {
                return null;
            }

            return self::normalizeUsage(getrusage());
        };
    }

    public function markBootstrap(?float $finishedAt = null): void
    {
        $this->bootstrapFinishedAt = $finishedAt ?? microtime(true);
        $this->bootstrapUsage = ($this->reader)();
    }

    /** @return array{cpu_ms:?float,cpu_user_ms:?float,cpu_system_ms:?float,bootstrap_ms:?float,bootstrap_cpu_ms:?float} */
    public function metrics(): array
    {
        $start = $this->cpuTimes($this->startedUsage);
        $end = $this->cpuTimes(($this->reader)());
        $boot = $this->cpuTimes($this->bootstrapUsage);
        $user = $start !== null && $end !== null ? max(0.0, $end['user'] - $start['user']) : null;
        $system = $start !== null && $end !== null ? max(0.0, $end['system'] - $start['system']) : null;

        return [
            'cpu_ms' => $user !== null && $system !== null ? round($user + $system, 3) : null,
            'cpu_user_ms' => $user !== null ? round($user, 3) : null,
            'cpu_system_ms' => $system !== null ? round($system, 3) : null,
            'bootstrap_ms' => $this->bootstrapFinishedAt !== null
                ? round(max(0.0, $this->bootstrapFinishedAt - $this->startedAt) * 1000.0, 3)
                : null,
            'bootstrap_cpu_ms' => $start !== null && $boot !== null
                ? round(max(0.0, $boot['user'] - $start['user']) + max(0.0, $boot['system'] - $start['system']), 3)
                : null,
        ];
    }

    /**
     * @param array<string, int>|false $usage
     * @return array<string, int>|null
     */
    private static function normalizeUsage(array|false $usage): ?array
    {
        return $usage === false ? null : $usage;
    }

    /**
     * @param array<string, int>|false|null $usage
     * @return array{user:float,system:float}|null
     */
    private function cpuTimes(array|false|null $usage): ?array
    {
        if (!\is_array($usage)) {
            return null;
        }

        foreach (['ru_utime.tv_sec', 'ru_utime.tv_usec', 'ru_stime.tv_sec', 'ru_stime.tv_usec'] as $key) {
            if (!isset($usage[$key]) || $usage[$key] < 0) {
                return null;
            }
        }

        return [
            'user' => ($usage['ru_utime.tv_sec'] * 1_000_000 + $usage['ru_utime.tv_usec']) / 1000,
            'system' => ($usage['ru_stime.tv_sec'] * 1_000_000 + $usage['ru_stime.tv_usec']) / 1000,
        ];
    }
}
