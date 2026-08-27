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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
        $clientGroup = $state->clientGroup('192.0.2.10', 'Mozilla/5.0', 159);
        self::assertIsString($clientGroup);
        self::assertMatchesRegularExpression('/^[a-f0-9]{12}$/D', $clientGroup);
        self::assertSame($clientGroup, $state->clientGroup('192.0.2.10', 'Mozilla/5.0', 159));
        self::assertNotSame($clientGroup, $state->clientGroup('192.0.2.11', 'Mozilla/5.0', 159));
        self::assertFalse($state->isActive(160));
        self::assertNull($state->clientGroup('192.0.2.10', 'Mozilla/5.0', 160));
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
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/search?q=hidden-url-value',
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/150.0 hidden-user-agent',
            'HTTP_PURPOSE' => 'prefetch',
            'HTTP_SEC_FETCH_MODE' => 'navigate',
            'HTTP_SEC_FETCH_DEST' => 'document',
        ];
        $request = Request::create(
            '/search?q=hidden-url-value',
            cookies: ['private-cookie' => 'hidden-cookie-value'],
            server: $server,
        );
        $request->attributes->set('_register_page_cache_policy', 'query');

        $response = new Response('', Response::HTTP_OK, ['X-Register-Page-Cache' => 'miss']);
        $profiler->record($server, 200, 100.25, $request, $response);

        $contents = file_get_contents($this->logFile);
        self::assertIsString($contents);
        self::assertStringContainsString('"path":"/search"', $contents);
        self::assertStringNotContainsString('hidden-sql-value', $contents);
        self::assertStringNotContainsString('hidden-url-value', $contents);
        self::assertStringNotContainsString('192.0.2.10', $contents);
        self::assertStringNotContainsString('hidden-user-agent', $contents);
        self::assertStringNotContainsString('hidden-cookie-value', $contents);
        $permissions = fileperms($this->logFile);
        self::assertIsInt($permissions);
        self::assertSame(0600, $permissions & 0777);

        $report = (new QueryProfilerInspector($log))->inspect();
        self::assertSame(1, $report['request_count']);
        self::assertSame($pdo->getQueryCount(), $report['query_count']);
        self::assertSame('GET', $report['paths'][0]['method']);
        self::assertSame('/search', $report['paths'][0]['path']);
        self::assertSame('/search', $report['recent'][0]['path']);
        self::assertSame('chrome', $report['contexts'][0]['agent']);
        self::assertSame('miss', $report['contexts'][0]['page_cache']);
        self::assertSame('query', $report['contexts'][0]['cache_policy']);
        self::assertSame('present', $report['contexts'][0]['query']);
        self::assertSame('present', $report['contexts'][0]['cookies']);
        self::assertSame('prefetch', $report['contexts'][0]['purpose']);
        self::assertSame('navigate', $report['contexts'][0]['fetch_mode']);
        self::assertSame('document', $report['contexts'][0]['fetch_dest']);
        self::assertSame($report['contexts'][0]['client_group'], $report['recent'][0]['request_context']['client_group']);
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
