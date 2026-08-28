<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Mail;

final readonly class MailDeliveryInspector
{
    public function __construct(private MailDeliveryLog $log)
    {
    }

    /**
     * @return array{
     *     hour:array{accepted:int,failed:int,p50_ms:float,p95_ms:float},
     *     day:array{accepted:int,failed:int,p50_ms:float,p95_ms:float},
     *     last:array{at:string,type:string,status:string,transport:string,duration_ms:float,error_code:int|null,error:string|null}|null
     * }
     */
    public function inspect(?int $now = null): array
    {
        $now ??= time();
        $windows = [
            'hour' => ['cutoff' => $now - 3600, 'accepted' => 0, 'failed' => 0, 'durations' => []],
            'day'  => ['cutoff' => $now - 86400, 'accepted' => 0, 'failed' => 0, 'durations' => []],
        ];
        $last = null;
        foreach ($this->log->lines() as $line) {
            try {
                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!\is_array($row)
                || !\is_string($row['at'] ?? null)
                || !\is_string($row['type'] ?? null)
                || !\is_string($row['status'] ?? null)
                || !\is_string($row['transport'] ?? null)
            ) {
                continue;
            }

            $timestamp = strtotime($row['at']);
            $duration = $row['duration_ms'] ?? null;
            if ($timestamp === false || !\is_int($duration) && !\is_float($duration)) {
                continue;
            }

            $status = \in_array($row['status'], ['accepted', 'failed'], true) ? $row['status'] : null;
            if ($status === null) {
                continue;
            }

            foreach ($windows as &$window) {
                if ($timestamp < $window['cutoff']) {
                    continue;
                }

                ++$window[$status];
                $window['durations'][] = (float)$duration;
            }

            unset($window);
            $last = [
                'at'          => mb_substr($row['at'], 0, 64),
                'type'        => mb_substr($row['type'], 0, 64),
                'status'      => $status,
                'transport'   => mb_substr($row['transport'], 0, 32),
                'duration_ms' => (float)$duration,
                'error_code'  => \is_int($row['error_code'] ?? null) ? $row['error_code'] : null,
                'error'       => \is_string($row['error'] ?? null) ? mb_substr($row['error'], 0, 500) : null,
            ];
        }

        $summary = [];
        foreach ($windows as $name => $window) {
            sort($window['durations'], SORT_NUMERIC);
            $summary[$name] = [
                'accepted' => $window['accepted'],
                'failed'   => $window['failed'],
                'p50_ms'   => $this->percentile($window['durations'], 0.50),
                'p95_ms'   => $this->percentile($window['durations'], 0.95),
            ];
        }

        return ['hour' => $summary['hour'], 'day' => $summary['day'], 'last' => $last];
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        $index = (int)ceil($percentile * (float)\count($values)) - 1;

        return round($values[max(0, min($index, \count($values) - 1))], 1);
    }
}
