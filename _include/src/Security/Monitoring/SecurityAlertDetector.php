<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Security\Monitoring;

use Register\Http\CspViolationReporter;
use Symfony\Component\HttpFoundation\Response;

/** Builds a small recent-event summary without loading complete log files into memory. */
final readonly class SecurityAlertDetector
{
    public const int WINDOW_SECONDS = 15 * 60;

    public const int UNAUTHORIZED_THRESHOLD = 10;

    public const int FORBIDDEN_THRESHOLD = 10;

    public const int RATE_LIMITED_THRESHOLD = 3;

    public const int CSP_THRESHOLD = 20;

    public const int UPLOAD_THRESHOLD = 10;

    private const int MAX_TAIL_BYTES = 1024 * 1024;

    private const float CAPACITY_WARNING_RATIO = 0.95;

    public function __construct(
        private string $telemetryFile,
        private string $cspFile,
        private int $telemetryMaxFileBytes = SecurityTelemetryRecorder::DEFAULT_MAX_FILE_BYTES,
        private int $cspMaxFileBytes = CspViolationReporter::DEFAULT_MAX_FILE_BYTES,
    ) {
        if ($this->telemetryMaxFileBytes < 1 || $this->cspMaxFileBytes < 1) {
            throw new \InvalidArgumentException('Security monitoring file limits must be positive.');
        }
    }

    public function inspect(?int $now = null): SecurityAlertSummary
    {
        $now ??= time();
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', $now - self::WINDOW_SECONDS);
        $latest = gmdate('Y-m-d\TH:i:s\Z', $now + 60);

        $unauthorized = 0;
        $forbidden    = 0;
        $rateLimited  = 0;
        $uploads      = 0;
        foreach ($this->recentRecords($this->telemetryFile, $cutoff, $latest) as $record) {
            if (($record['event'] ?? null) !== 'security_response') {
                continue;
            }

            $statusCode = $record['status_code'] ?? null;
            if ($statusCode === Response::HTTP_UNAUTHORIZED) {
                ++$unauthorized;
            } elseif ($statusCode === Response::HTTP_FORBIDDEN) {
                ++$forbidden;
            } elseif ($statusCode === Response::HTTP_TOO_MANY_REQUESTS) {
                ++$rateLimited;
            }

            if (($record['operation'] ?? null) === 'upload' && ($record['outcome'] ?? null) === 'failure') {
                ++$uploads;
            }
        }

        $cspViolations = 0;
        foreach ($this->recentRecords($this->cspFile, $cutoff, $latest) as $record) {
            if (($record['event'] ?? null) === 'csp_violation') {
                ++$cspViolations;
            }
        }

        return new SecurityAlertSummary(
            windowMinutes: intdiv(self::WINDOW_SECONDS, 60),
            unauthorizedResponses: $unauthorized,
            forbiddenResponses: $forbidden,
            rateLimitedResponses: $rateLimited,
            cspViolations: $cspViolations,
            uploadFailures: $uploads,
            unauthorizedSpike: $unauthorized >= self::UNAUTHORIZED_THRESHOLD,
            forbiddenSpike: $forbidden >= self::FORBIDDEN_THRESHOLD,
            rateLimitedSpike: $rateLimited >= self::RATE_LIMITED_THRESHOLD,
            cspSpike: $cspViolations >= self::CSP_THRESHOLD,
            uploadSpike: $uploads >= self::UPLOAD_THRESHOLD,
            telemetryNearCapacity: $this->nearCapacity(
                $this->telemetryFile,
                $this->telemetryMaxFileBytes,
            ) || $this->nearCapacity($this->cspFile, $this->cspMaxFileBytes),
        );
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function recentRecords(string $filename, string $cutoff, string $latest): \Generator
    {
        if (!is_file($filename) || is_link($filename)) {
            return;
        }

        $handle = s2_call_without_warnings(static fn() => fopen($filename, 'rb'));
        if ($handle === false) {
            return;
        }

        try {
            $stat = fstat($handle);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                return;
            }

            $offset = max(0, $stat['size'] - self::MAX_TAIL_BYTES);
            if ($offset > 0) {
                if (fseek($handle, $offset) !== 0) {
                    return;
                }
                fgets($handle);
            }

            while (($line = fgets($handle)) !== false) {
                if (strlen($line) > 65_536) {
                    continue;
                }

                try {
                    $record = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (!\is_array($record)) {
                    continue;
                }

                $occurredAt = $record['occurred_at'] ?? null;
                if (!\is_string($occurredAt)
                    || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $occurredAt) !== 1
                    || $occurredAt < $cutoff
                    || $occurredAt > $latest
                ) {
                    continue;
                }

                yield $record;
            }
        } finally {
            fclose($handle);
        }
    }

    private function nearCapacity(string $filename, int $limit): bool
    {
        if (!is_file($filename) || is_link($filename)) {
            return false;
        }

        $size = s2_call_without_warnings(static fn(): int|false => filesize($filename));

        return $size !== false && $size >= (int)($limit * self::CAPACITY_WARNING_RATIO);
    }
}
