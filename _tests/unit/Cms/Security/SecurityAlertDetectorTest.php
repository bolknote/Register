<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Security;

use Codeception\Test\Unit;
use S2\Cms\Security\Monitoring\SecurityAlertDetector;

final class SecurityAlertDetectorTest extends Unit
{
    private string $directory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->directory = sys_get_temp_dir() . '/register_security_alerts_' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        foreach (['security-events.jsonl', 'csp-violations.jsonl'] as $name) {
            $file = $this->directory . '/' . $name;
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testDetectsRecentSpikesAndIgnoresOldOrMalformedRecords(): void
    {
        $now = 1_800_000_000;
        $recent = gmdate('Y-m-d\TH:i:s\Z', $now - 30);
        $old    = gmdate('Y-m-d\TH:i:s\Z', $now - SecurityAlertDetector::WINDOW_SECONDS - 1);

        $telemetry = [];
        for ($i = 0; $i < SecurityAlertDetector::UNAUTHORIZED_THRESHOLD; ++$i) {
            $telemetry[] = $this->responseRecord($recent, 401);
        }
        for ($i = 0; $i < SecurityAlertDetector::FORBIDDEN_THRESHOLD - 1; ++$i) {
            $telemetry[] = $this->responseRecord($recent, 403);
        }
        for ($i = 0; $i < SecurityAlertDetector::RATE_LIMITED_THRESHOLD; ++$i) {
            $telemetry[] = $this->responseRecord($recent, 429);
        }
        for ($i = 0; $i < SecurityAlertDetector::UPLOAD_THRESHOLD; ++$i) {
            $telemetry[] = [
                ...$this->responseRecord($recent, 422),
                'operation' => 'upload',
                'outcome'   => 'failure',
            ];
        }
        $telemetry[] = $this->responseRecord($old, 403);
        $this->writeRecords($this->telemetryFile(), $telemetry, true);

        $csp = [];
        for ($i = 0; $i < SecurityAlertDetector::CSP_THRESHOLD; ++$i) {
            $csp[] = ['occurred_at' => $recent, 'event' => 'csp_violation'];
        }
        $this->writeRecords($this->cspFile(), $csp);

        $summary = $this->detector()->inspect($now);
        self::assertSame(SecurityAlertDetector::UNAUTHORIZED_THRESHOLD, $summary->unauthorizedResponses);
        self::assertSame(SecurityAlertDetector::FORBIDDEN_THRESHOLD - 1, $summary->forbiddenResponses);
        self::assertSame(SecurityAlertDetector::RATE_LIMITED_THRESHOLD, $summary->rateLimitedResponses);
        self::assertSame(SecurityAlertDetector::CSP_THRESHOLD, $summary->cspViolations);
        self::assertSame(SecurityAlertDetector::UPLOAD_THRESHOLD, $summary->uploadFailures);
        self::assertTrue($summary->unauthorizedSpike);
        self::assertFalse($summary->forbiddenSpike);
        self::assertTrue($summary->rateLimitedSpike);
        self::assertTrue($summary->cspSpike);
        self::assertTrue($summary->uploadSpike);
        self::assertTrue($summary->hasAlerts());
    }

    public function testMissingFilesAreHealthyAndCapacityIsReported(): void
    {
        $summary = $this->detector()->inspect(1_800_000_000);
        self::assertFalse($summary->hasAlerts());

        file_put_contents($this->telemetryFile(), str_repeat('x', 96));
        $summary = $this->detector(100, 100)->inspect(1_800_000_000);
        self::assertTrue($summary->telemetryNearCapacity);
        self::assertTrue($summary->hasAlerts());
    }

    /** @return array{occurred_at: string, event: string, status_code: int} */
    private function responseRecord(string $occurredAt, int $statusCode): array
    {
        return [
            'occurred_at' => $occurredAt,
            'event'       => 'security_response',
            'status_code' => $statusCode,
        ];
    }

    /** @param list<array<string, mixed>> $records */
    private function writeRecords(string $filename, array $records, bool $prependMalformed = false): void
    {
        $lines = $prependMalformed ? ["not-json\n"] : [];
        foreach ($records as $record) {
            $lines[] = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
        }
        file_put_contents($filename, implode('', $lines));
    }

    private function detector(
        int $telemetryMaxBytes = 5 * 1024 * 1024,
        int $cspMaxBytes = 5 * 1024 * 1024,
    ): SecurityAlertDetector {
        return new SecurityAlertDetector(
            $this->telemetryFile(),
            $this->cspFile(),
            $telemetryMaxBytes,
            $cspMaxBytes,
        );
    }

    private function telemetryFile(): string
    {
        return $this->directory . '/security-events.jsonl';
    }

    private function cspFile(): string
    {
        return $this->directory . '/csp-violations.jsonl';
    }
}
