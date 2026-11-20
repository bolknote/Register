<?php

declare(strict_types = 1);

namespace unit\Cms\Monitoring;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Register\Core\Logger\Logger;
use Register\Core\Monitoring\FailureDiagnostics;
use Register\Core\Monitoring\FailureResourceSnapshot;
use Register\Core\Monitoring\IncidentLogger;
use Register\Core\Monitoring\QueryProfilerLog;
use Symfony\Component\Filesystem\Filesystem;

final class FailureDiagnosticsTest extends TestCase
{
    private string $directory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/register-failures-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/register-build.json', json_encode([
            'release_id' => 'fixture', 'commit' => str_repeat('a', 40),
        ], JSON_THROW_ON_ERROR));
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testCorrelatesNativeDatabaseFailureWithRequestAndExistingExceptionLog(): void
    {
        $diagnostics = $this->recorder();
        $logger = new IncidentLogger(new Logger($this->directory . '/app.log', 'app'), $diagnostics);
        try {
            (new \Register\Core\Pdo\PDO('sqlite:' . $this->directory . '/missing/database.sqlite', lazy: true))->query('SELECT 1');
            self::fail('The missing database directory must fail through the normal PDO wrapper.');
        } catch (\PDOException $exception) {
            $logger->critical('Uncaught exception', ['exception' => $exception]);
        }

        $id = $diagnostics->incidentId();
        self::assertNotNull($id);
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/D', $id);
        $json = file_get_contents($this->directory . '/incidents.jsonl');
        self::assertIsString($json);
        $record = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($id, $record['incident_id']);
        self::assertSame('/search', $record['path']);
        self::assertSame('GET', $record['method']);
        self::assertSame(['driver' => 'mysql', 'transport' => 'unix'], $record['database']);
        self::assertSame('HY000', $record['exceptions'][0]['sqlstate']);
        self::assertSame(14, $record['exceptions'][0]['driver_code']);
        self::assertSame('connect', $record['exceptions'][0]['operation']);
        self::assertNotEmpty($record['exceptions'][0]['trace']);
        self::assertArrayHasKey('host_load_average', $record['resources']);
        self::assertSame(['release_id' => 'fixture', 'commit' => str_repeat('a', 40)], $record['release']);
        self::assertStringNotContainsString('private-query', $json);
        self::assertStringNotContainsString('private-cookie', $json);
        self::assertStringNotContainsString('192.0.2.5', $json);
        self::assertStringNotContainsString('SELECT 1', $json);
        $appLog = file_get_contents($this->directory . '/app.log');
        self::assertIsString($appLog);
        self::assertStringContainsString($id, $appLog);
        self::assertStringContainsString('unable to open database file', $appLog);
        $permissions = fileperms($this->directory . '/incidents.jsonl');
        self::assertIsInt($permissions);
        self::assertSame(0600, $permissions & 0777);
    }

    public function testOrdinaryLogMessagesDoNotCreateAnIncident(): void
    {
        $diagnostics = $this->recorder();
        $logger = new IncidentLogger(new NullLogger(), $diagnostics);
        $logger->warning('Handled failure', ['exception' => new \RuntimeException('Handled')]);
        $logger->info('Routine work');
        self::assertNull($diagnostics->incidentId());
        self::assertFileDoesNotExist($this->directory . '/incidents.jsonl');
    }

    public function testUsesTheApplicationLoggerAfterBootstrap(): void
    {
        $diagnostics = $this->recorder();
        $logger = new IncidentLogger(new NullLogger(), $diagnostics);
        $logger->setLogger(new Logger($this->directory . '/custom.log', 'extension'));
        $logger->critical('Unhandled failure', ['exception' => new \RuntimeException('Original failure')]);

        $log = file_get_contents($this->directory . '/custom.log');
        self::assertIsString($log);
        self::assertStringContainsString('[extension]', $log);
        self::assertNotNull($diagnostics->incidentId());
        self::assertStringContainsString($diagnostics->incidentId(), $log);
        self::assertStringContainsString('Original failure', $log);
    }

    public function testRecordsExceptionChainAndRedactsAuthenticationPathSegments(): void
    {
        $diagnostics = $this->recorder('/blog/auth/email/private-token?return=private-query');
        $diagnostics->record(new \RuntimeException('private-message', previous: new \LogicException('private-cause')));

        $json = file_get_contents($this->directory . '/incidents.jsonl');
        self::assertIsString($json);
        $record = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('/blog/auth', $record['path']);
        self::assertCount(2, $record['exceptions']);
        self::assertStringNotContainsString('private-', $json);
    }

    public function testReadOnlyLogTargetCannotBreakTheOriginalErrorHandler(): void
    {
        $diagnostics = new FailureDiagnostics(new QueryProfilerLog($this->directory), microtime(true),
            [], ['driver' => 'mysql', 'transport' => 'unix'], $this->directory);
        $errorLog = ini_get('error_log');
        ini_set('error_log', $this->directory . '/fallback.log');
        try {
            (new IncidentLogger(new NullLogger(), $diagnostics))->critical('Failure', ['exception' => new \RuntimeException('Original')]);
            self::assertNotNull($diagnostics->incidentId());
            $fallback = file_get_contents($this->directory . '/fallback.log');
            self::assertIsString($fallback);
            self::assertStringContainsString($diagnostics->incidentId(), $fallback);
        } finally {
            ini_set('error_log', $errorLog !== false ? $errorLog : '');
        }
    }

    public function testSnapshotKeepsRelevantLimitsWithoutOtherProcessData(): void
    {
        $proc = $this->directory . '/proc';
        $cgroup = $this->directory . '/cgroup';
        mkdir($proc . '/self/fd', 0700, true);
        mkdir($proc . '/sys/fs', 0700, true);
        mkdir($cgroup . '/account', 0700, true);
        file_put_contents($proc . '/self/status', "Name:\tprivate-command\nVmRSS:\t1234 kB\nThreads:\t2\n");
        file_put_contents($proc . '/self/limits', "Max open files            256                 1024                files\nMax processes             32                  32                  processes\n");
        file_put_contents($proc . '/sys/fs/file-nr', "1024\t0\t65536\n");
        file_put_contents($proc . '/self/cgroup', "0::/account\n");
        file_put_contents($cgroup . '/account/pids.current', '32');
        file_put_contents($cgroup . '/account/pids.max', '32');
        file_put_contents($cgroup . '/account/pids.events', "max 5\n");
        // Linux metrics are optional on other supported operating systems.
        $snapshot = FailureResourceSnapshot::capture($proc, $cgroup);
        self::assertSame(['VmRSS:' . "\t1234 kB", 'Threads:' . "\t2"], $snapshot['self/status']);
        self::assertSame('32', $snapshot['cgroup']['pids.current']);
        self::assertSame('max 5', $snapshot['cgroup']['pids.events']);
        self::assertStringNotContainsString('private-command', json_encode($snapshot, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('cgroup', FailureResourceSnapshot::capture($proc . '/missing', $cgroup));
    }

    private function recorder(string $path = '/search?q=private-query'): FailureDiagnostics
    {
        return new FailureDiagnostics(new QueryProfilerLog($this->directory . '/incidents.jsonl'), microtime(true), [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path,
            'HTTP_COOKIE' => 'private-cookie', 'REMOTE_ADDR' => '192.0.2.5',
        ], ['driver' => 'mysql', 'transport' => 'unix'], $this->directory);
    }
}
