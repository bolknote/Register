<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Install;

use Codeception\Test\Unit;
use Register\Core\Config\SecretConfigPathResolver;
use Register\Core\HttpClient\HttpClient;
use Register\Core\HttpClient\HttpResponse;
use Register\Core\Install\SecretFileBoundaryVerifier;
use Symfony\Component\Filesystem\Filesystem;

final class SecretFileBoundaryVerifierTest extends Unit
{
    private string $publicRoot = '';

    #[\Override]
    protected function _before(): void
    {
        $this->publicRoot = sys_get_temp_dir() . '/register_boundary_test_' . bin2hex(random_bytes(6));
        mkdir($this->publicRoot, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        if ($this->publicRoot !== '') {
            (new Filesystem())->remove($this->publicRoot);
        }
    }

    public function testRejectsServerThatReturnsPrivatePhpSource(): void
    {
        $seenOptions = null;
        $request = function (string $_url, array $options) use (&$seenOptions): HttpResponse {
            $seenOptions = $options;
            $content = file_get_contents(
                $this->publicRoot . '/' . SecretConfigPathResolver::fallbackFilename(),
            );
            self::assertIsString($content);

            return new HttpResponse(statusCode: 200, content: $content);
        };
        $verifier = new SecretFileBoundaryVerifier(new HttpClient(), $request);

        self::assertFalse($verifier->verifyFallback(
            $this->publicRoot,
            'https://example.com/blog',
            'example.com',
            '192.0.2.10',
            443,
        ));
        self::assertIsArray($seenOptions);
        self::assertSame('192.0.2.10', $seenOptions[HttpClient::RESOLVE_IP]);
        self::assertFalse($seenOptions[HttpClient::FOLLOW_REDIRECTS]);
        self::assertFileDoesNotExist(
            $this->publicRoot . '/' . SecretConfigPathResolver::fallbackFilename(),
        );
    }

    public function testAcceptsExplicitAccessDenialAndRemovesProbe(): void
    {
        $verifier = new SecretFileBoundaryVerifier(
            new HttpClient(),
            static fn(string $_url, array $_options): HttpResponse => new HttpResponse(
                statusCode: 403,
                content: 'Forbidden',
            ),
        );

        self::assertTrue($verifier->verifyFallback(
            $this->publicRoot,
            'http://localhost:8080',
            'localhost',
            '127.0.0.1',
            8080,
        ));
        self::assertFileDoesNotExist(
            $this->publicRoot . '/' . SecretConfigPathResolver::fallbackFilename(),
        );
    }

    public function testRejectsHostOrPortMismatchWithoutCreatingProbe(): void
    {
        $called = false;
        $verifier = new SecretFileBoundaryVerifier(
            new HttpClient(),
            static function (string $_url, array $_options) use (&$called): HttpResponse {
                $called = true;
                return new HttpResponse(statusCode: 403);
            },
        );

        self::assertFalse($verifier->verifyFallback(
            $this->publicRoot,
            'https://attacker.example',
            'example.com',
            '192.0.2.10',
            443,
        ));
        self::assertFalse($verifier->verifyFallback(
            $this->publicRoot,
            'https://example.com',
            'example.com',
            '192.0.2.10',
            8443,
        ));
        self::assertFalse($called);
        self::assertFileDoesNotExist(
            $this->publicRoot . '/' . SecretConfigPathResolver::fallbackFilename(),
        );
    }
}
