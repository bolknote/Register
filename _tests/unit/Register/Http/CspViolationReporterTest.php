<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Http;

use Codeception\Test\Unit;
use Register\Http\CspViolationReporter;
use S2\Cms\Comment\Antispam\SpamIdentityHasher;
use Symfony\Component\HttpFoundation\Request;

final class CspViolationReporterTest extends Unit
{
    private string $directory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->directory = sys_get_temp_dir() . '/register_csp_' . bin2hex(random_bytes(8));
    }

    #[\Override]
    protected function _after(): void
    {
        foreach (['csp-violations.jsonl', 'target'] as $name) {
            $file = $this->directory . '/' . $name;
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testStoresOnlySanitizedViolationMetadata(): void
    {
        $request = Request::create(
            'https://example.test/.well-known/csp-report',
            Request::METHOD_POST,
            server: ['REMOTE_ADDR' => '192.0.2.42', 'HTTP_USER_AGENT' => 'CSP test browser'],
        );
        $report = [
            'document-uri'       => 'https://example.test/private/article?token=top-secret#fragment',
            'referrer'           => 'https://secret.example/referrer?password=hidden',
            'effective-directive' => 'style-src-elem',
            'blocked-uri'        => 'https://cdn.example/assets/private.css?api_key=hidden',
            'source-file'        => 'https://example.test/private/app.js?session=hidden',
            'sample'             => 'password = "must-not-be-logged"',
            'original-policy'    => 'style-src top-secret-policy',
            'disposition'        => 'report',
            'status-code'        => 200,
            'line-number'        => 17,
            'column-number'      => 4,
        ];

        self::assertTrue($this->reporter()->record($request, $report));

        $contents = $this->contents();
        foreach (['top-secret', 'password', 'api_key', 'session=hidden', '192.0.2.42', 'CSP test browser'] as $secret) {
            self::assertStringNotContainsString($secret, $contents);
        }

        $record = json_decode(trim($contents), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('csp_violation', $record['event']);
        self::assertSame('report', $record['disposition']);
        self::assertSame('style-src-elem', $record['effective_directive']);
        self::assertSame('cross_origin_https', $record['blocked_resource']);
        self::assertSame('https://cdn.example', $record['blocked_origin']);
        self::assertSame(200, $record['status_code']);
        self::assertSame(17, $record['line_number']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $record['document_hash']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $record['remote_ip_hash']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $record['user_agent_hash']);

        $permissions = fileperms($this->directory . '/csp-violations.jsonl');
        self::assertNotFalse($permissions);
        self::assertSame(0600, $permissions & 0777);
    }

    public function testStopsAtConfiguredFileLimit(): void
    {
        $reporter = $this->reporter(1);

        self::assertFalse($reporter->record(Request::create('/'), ['effective-directive' => 'style-src']));
        self::assertSame('', $this->contents());
    }

    public function testSymbolicLinkIsNotFollowed(): void
    {
        mkdir($this->directory, 0700, true);
        $target = $this->directory . '/target';
        file_put_contents($target, 'unchanged');
        symlink($target, $this->directory . '/csp-violations.jsonl');

        self::assertFalse($this->reporter()->record(Request::create('/'), ['effective-directive' => 'style-src']));
        self::assertSame('unchanged', file_get_contents($target));
    }

    private function reporter(int $maxFileBytes = CspViolationReporter::DEFAULT_MAX_FILE_BYTES): CspViolationReporter
    {
        return new CspViolationReporter(
            $this->directory . '/csp-violations.jsonl',
            new SpamIdentityHasher(str_repeat('a', 32)),
            $maxFileBytes,
        );
    }

    private function contents(): string
    {
        $contents = file_get_contents($this->directory . '/csp-violations.jsonl');
        self::assertIsString($contents);

        return $contents;
    }
}
