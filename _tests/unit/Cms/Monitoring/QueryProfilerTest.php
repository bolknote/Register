<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Monitoring;

use Codeception\Test\Unit;
use Register\Core\Monitoring\QueryProfilerInspector;
use Register\Core\Monitoring\QueryProfilerLog;
use Register\Core\Monitoring\QueryProfilerState;
use Register\Core\Monitoring\RequestQueryProfiler;
use Register\Core\Monitoring\SqlQueryTemplateSanitizer;
use Register\Core\Pdo\PDO;

final class QueryProfilerTest extends Unit
{
    private string $directory = '';

    private string $stateFile = '';

    private string $logFile = '';

    #[\Override]
    protected function _before(): void
    {
        $this->directory = sys_get_temp_dir() . '/register-query-profiler-' . bin2hex(random_bytes(8));
        $this->stateFile = $this->directory . '/state.json';
        $this->logFile = $this->directory . '/profile.jsonl';
    }

    #[\Override]
    protected function _after(): void
    {
        foreach ([$this->stateFile, $this->logFile] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testStateAutomaticallyExpiresAndUsesPrivatePermissions(): void
    {
        $state = new QueryProfilerState($this->stateFile);
        self::assertFalse($state->isActive(100));

        $state->start(60, 100);
        self::assertSame(['active' => true, 'expires_at' => 160], $state->status(159));
        self::assertFalse($state->isActive(160));
        $permissions = fileperms($this->stateFile);
        self::assertIsInt($permissions);
        self::assertSame(0600, $permissions & 0777);

        $state->stop();
        self::assertSame(['active' => false, 'expires_at' => 0], $state->status(100));
    }

    public function testSanitizerRemovesCommentsAndLiteralValues(): void
    {
        $sanitizer = new SqlQueryTemplateSanitizer();
        $template = $sanitizer->sanitize(<<<'SQL'
            /* private marker */ SELECT * FROM users
            WHERE email = 'private@example.com' AND password = "secret" AND id = 42
            AND note = 'value -- still private # marker' AND score = -1.5e2
            AND payload = 0xCAFE AND pg_value = $tag$dollar-private$tag$ AND pg_id = $1 -- another secret
            SQL);

        self::assertStringNotContainsString('private', $template);
        self::assertStringNotContainsString('secret', $template);
        self::assertStringNotContainsString('42', $template);
        self::assertStringNotContainsString('CAFE', $template);
        self::assertStringNotContainsString('dollar-private', $template);
        self::assertStringContainsString('email = ?', $template);
        self::assertStringContainsString('score = ?', $template);
        self::assertStringContainsString('pg_id = $1', $template);
    }

    public function testRecordsRedactedQueriesAndBuildsAggregates(): void
    {
        $state = new QueryProfilerState($this->stateFile);
        $log = new QueryProfilerLog($this->logFile);
        $state->start(60, 100);

        $pdo = new PDO('sqlite::memory:');
        $statement = $pdo->prepare('SELECT :value AS private_value, 123 AS fixed_value');
        self::assertNotFalse($statement);
        $statement->execute(['value' => 'hidden-sql-value']);

        $profiler = new RequestQueryProfiler(
            $pdo,
            $state,
            $log,
            new SqlQueryTemplateSanitizer(),
            100.0,
        );
        $profiler->record([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/search?q=hidden-url-value',
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_USER_AGENT' => 'hidden-user-agent',
        ], 200, 100.25);

        $contents = file_get_contents($this->logFile);
        self::assertIsString($contents);
        self::assertStringContainsString('"path":"/search"', $contents);
        self::assertStringNotContainsString('hidden-sql-value', $contents);
        self::assertStringNotContainsString('hidden-url-value', $contents);
        self::assertStringNotContainsString('192.0.2.10', $contents);
        self::assertStringNotContainsString('hidden-user-agent', $contents);
        $permissions = fileperms($this->logFile);
        self::assertIsInt($permissions);
        self::assertSame(0600, $permissions & 0777);

        $report = (new QueryProfilerInspector($log))->inspect();
        self::assertSame(1, $report['request_count']);
        self::assertSame($pdo->getQueryCount(), $report['query_count']);
        self::assertSame('GET', $report['paths'][0]['method']);
        self::assertSame('/search', $report['paths'][0]['path']);
        self::assertSame('/search', $report['recent'][0]['path']);
        self::assertStringContainsString('SELECT :value AS private_value, ? AS fixed_value', $contents);
    }

    public function testSuppressedProfilerDoesNotRecordItsControlRequest(): void
    {
        $state = new QueryProfilerState($this->stateFile);
        $state->start(60, 100);

        $profiler = new RequestQueryProfiler(
            new PDO('sqlite::memory:'),
            $state,
            new QueryProfilerLog($this->logFile),
            new SqlQueryTemplateSanitizer(),
            100.0,
        );

        $profiler->suppress();
        $profiler->record(['REQUEST_URI' => '/_admin/ajax.php'], 303, 100.1);

        self::assertFileDoesNotExist($this->logFile);
    }
}
