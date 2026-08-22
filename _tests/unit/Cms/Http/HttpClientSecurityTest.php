<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Http;

use Codeception\Test\Unit;
use Register\Core\HttpClient\HttpClient;
use Register\Core\HttpClient\HttpClientException;

final class HttpClientSecurityTest extends Unit
{
    public function testSslVerificationIsEnabledByDefault(): void
    {
        $verifySsl = new \ReflectionProperty(HttpClient::class, 'verifySsl');

        self::assertTrue($verifySsl->getValue(new HttpClient()));
    }

    /** @dataProvider unsafeUrlProvider */
    public function testRejectsUnsafeUrls(string $url): void
    {
        $this->expectException(HttpClientException::class);

        (new HttpClient())->request('GET', $url);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeUrlProvider(): iterable
    {
        yield 'local file scheme' => ['file://localhost/etc/passwd'];
        yield 'FTP scheme' => ['ftp://example.test/file'];
        yield 'embedded credentials' => ['https://user:password@example.test/'];
        yield 'request-line injection' => ["https://example.test/\r\nX-Injected: yes"];
    }

    public function testRejectsHeaderInjectionBeforeOpeningConnection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new HttpClient())->request('GET', 'https://example.test/', [
            'X-Test' => "value\r\nX-Injected: yes",
        ]);
    }

    public function testRejectsHeadersManagedByTheTransport(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new HttpClient())->request('GET', 'https://example.test/', [
            'Transfer-Encoding' => 'chunked',
        ]);
    }

    public function testRejectsTlsDowngradeRedirect(): void
    {
        $resolver = new \ReflectionMethod(HttpClient::class, 'newUrlFromLocation');

        $this->expectException(HttpClientException::class);
        $resolver->invoke(new HttpClient(), 'http://example.test/unsafe', 'https://example.test/safe');
    }

    public function testResolvesRelativeRedirectWithoutKeepingOldQuery(): void
    {
        $resolver = new \ReflectionMethod(HttpClient::class, 'newUrlFromLocation');

        self::assertSame(
            'https://example.test/articles/final?fresh=1',
            $resolver->invoke(
                new HttpClient(),
                '../final?fresh=1',
                'https://example.test/articles/archive/start?stale=1',
            ),
        );
    }
}
