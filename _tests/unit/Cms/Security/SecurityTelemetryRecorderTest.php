<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Security;

use Codeception\Test\Unit;
use S2\Cms\Comment\Antispam\SpamIdentityHasher;
use S2\Cms\Security\Monitoring\SecurityTelemetryRecorder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityTelemetryRecorderTest extends Unit
{
    private string $directory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->directory = sys_get_temp_dir() . '/register_security_events_' . bin2hex(random_bytes(8));
    }

    #[\Override]
    protected function _after(): void
    {
        foreach (['security-events.jsonl', 'target'] as $name) {
            $file = $this->directory . '/' . $name;
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testStoresOnlyBoundedResponseClassificationsAndFingerprints(): void
    {
        $recorder = $this->recorder();
        $request = Request::create(
            'https://example.test/private?token=never-log-this-token',
            Request::METHOD_POST,
            ['password' => 'never-log-this-password', 'filename' => 'private-name.jpg'],
            server: ['REMOTE_ADDR' => '192.0.2.42', 'HTTP_USER_AGENT' => 'Telemetry browser'],
        );

        self::assertTrue($recorder->recordResponse($request, new Response('', Response::HTTP_OK)));
        self::assertFileDoesNotExist($this->filename());

        self::assertTrue($recorder->recordResponse(
            $request,
            new Response('', Response::HTTP_UNAUTHORIZED),
        ));
        self::assertTrue($recorder->recordResponse(
            $request,
            new JsonResponse(['success' => false, 'message' => 'private-name.jpg failed'], Response::HTTP_UNPROCESSABLE_ENTITY),
            true,
        ));
        self::assertTrue($recorder->recordResponse(
            $request,
            new JsonResponse(['success' => false], Response::HTTP_FORBIDDEN),
            true,
        ));

        $contents = $this->contents();
        foreach (['never-log-this', 'private-name.jpg', '192.0.2.42', 'Telemetry browser', '/private'] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $contents);
        }

        $records = $this->records();
        self::assertCount(3, $records);
        self::assertSame([401, 422, 403], array_column($records, 'status_code'));
        self::assertSame('upload', $records[1]['operation']);
        self::assertSame('failure', $records[1]['outcome']);
        self::assertArrayNotHasKey('operation', $records[2]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $records[0]['remote_ip_hash']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $records[0]['user_agent_hash']);

        $permissions = fileperms($this->filename());
        self::assertIsInt($permissions);
        self::assertSame(0600, $permissions & 0777);
    }

    public function testStopsAtConfiguredFileLimit(): void
    {
        self::assertFalse($this->recorder(1)->recordResponse(
            Request::create('/'),
            new Response('', Response::HTTP_FORBIDDEN),
        ));
        self::assertSame('', $this->contents());
    }

    public function testSymbolicLinkIsNotFollowed(): void
    {
        mkdir($this->directory, 0700, true);
        $target = $this->directory . '/target';
        file_put_contents($target, 'unchanged');
        symlink($target, $this->filename());

        self::assertFalse($this->recorder()->recordResponse(
            Request::create('/'),
            new Response('', Response::HTTP_TOO_MANY_REQUESTS),
        ));
        self::assertSame('unchanged', file_get_contents($target));
    }

    private function recorder(int $maxFileBytes = SecurityTelemetryRecorder::DEFAULT_MAX_FILE_BYTES): SecurityTelemetryRecorder
    {
        return new SecurityTelemetryRecorder(
            $this->filename(),
            new SpamIdentityHasher(str_repeat('a', 32)),
            $maxFileBytes,
        );
    }

    private function filename(): string
    {
        return $this->directory . '/security-events.jsonl';
    }

    private function contents(): string
    {
        $contents = file_get_contents($this->filename());
        self::assertIsString($contents);

        return $contents;
    }

    /** @return list<array<string, mixed>> */
    private function records(): array
    {
        return array_map(
            static fn(string $line): array => json_decode($line, true, 16, JSON_THROW_ON_ERROR),
            array_values(array_filter(
                explode("\n", $this->contents()),
                static fn(string $line): bool => $line !== '',
            )),
        );
    }
}
