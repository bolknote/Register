<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Monitoring;

use Codeception\Test\Unit;
use Register\Core\Monitoring\RequestPerformanceInspector;
use Register\Core\Monitoring\RequestPerformanceMonitor;
use Register\Core\Pdo\PDO;

final class RequestPerformanceMonitorTest extends Unit
{
    private string $logFile = '';

    #[\Override]
    protected function _before(): void
    {
        $this->logFile = sys_get_temp_dir() . '/register-performance-' . bin2hex(random_bytes(8)) . '.jsonl';
    }

    #[\Override]
    protected function _after(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testRecordsOnlySlowRequestsWithoutSensitiveRequestData(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->query('SELECT 1');

        $monitor = new RequestPerformanceMonitor($pdo, $this->logFile, 100.0);

        $monitor->record([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/search?q=private',
            'REMOTE_ADDR' => '192.0.2.1',
            'HTTP_USER_AGENT' => 'Private browser',
        ], 200, 100.1);
        self::assertFileDoesNotExist($this->logFile);

        $monitor->record([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/search?q=private',
            'REMOTE_ADDR' => '192.0.2.1',
            'HTTP_USER_AGENT' => 'Private browser',
        ], 200, 101.1);

        $contents = file_get_contents($this->logFile);
        self::assertIsString($contents);
        self::assertStringContainsString('"path":"/search"', $contents);
        self::assertStringNotContainsString('private', $contents);
        self::assertStringNotContainsString('192.0.2.1', $contents);
        self::assertStringNotContainsString('SELECT 1', $contents);
    }

    public function testInspectorRanksPathsByAccumulatedSlowTime(): void
    {
        file_put_contents($this->logFile, implode("\n", [
            '{"at":"2026-08-25T20:00:00+00:00","path":"/post","duration_ms":1400,"db_ms":100}',
            '{"at":"2026-08-25T20:01:00+00:00","path":"/post","duration_ms":1300,"db_ms":50}',
            '{"at":"2026-08-25T20:02:00+00:00","path":"/admin","duration_ms":1500,"db_ms":20}',
        ]) . "\n");

        $now = (new \DateTimeImmutable('2026-08-25T21:00:00+00:00'))->getTimestamp();
        $result = (new RequestPerformanceInspector($this->logFile))->inspect($now);

        self::assertSame(3, $result['event_count']);
        self::assertSame('/post', $result['paths'][0]['path']);
        self::assertSame(2, $result['paths'][0]['count']);
        self::assertSame(2700.0, $result['paths'][0]['total_ms']);
        self::assertSame(1350.0, $result['paths'][0]['average_ms']);
    }

    public function testInspectorSeparatesRecentProblemsFromDailyHistoryAndAppliesCurrentThresholds(): void
    {
        file_put_contents($this->logFile, implode("\n", [
            '{"at":"2026-08-25T20:45:00+00:00","path":"/recent","duration_ms":1200,"db_ms":100,"db_slowest_ms":40}',
            '{"at":"2026-08-25T18:00:00+00:00","path":"/old","duration_ms":2000,"db_ms":100,"db_slowest_ms":40}',
            '{"at":"2026-08-25T20:50:00+00:00","path":"/ordinary","duration_ms":800,"db_ms":100,"db_slowest_ms":40}',
            '{"at":"2026-08-25T20:55:00+00:00","path":"/database","duration_ms":600,"db_ms":550,"db_slowest_ms":100}',
        ]) . "\n");

        $now = (new \DateTimeImmutable('2026-08-25T21:00:00+00:00'))->getTimestamp();
        $result = (new RequestPerformanceInspector($this->logFile))->inspectRecentAndDaily($now);

        self::assertSame(2, $result['recent']['event_count']);
        self::assertSame(['/recent', '/database'], array_column($result['recent']['paths'], 'path'));
        self::assertSame(3, $result['daily']['event_count']);
        self::assertSame(['/old', '/recent', '/database'], array_column($result['daily']['paths'], 'path'));
    }
}
