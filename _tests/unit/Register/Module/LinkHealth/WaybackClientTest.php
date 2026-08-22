<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\LinkHealth;

use Codeception\Test\Unit;
use Register\Module\LinkHealth\ArchiveStatus;
use Register\Module\LinkHealth\LinkHttpClientInterface;
use Register\Core\HttpClient\Remote\HostResolverInterface;
use Register\Core\HttpClient\Remote\PublicAddressGuard;
use Register\Module\LinkHealth\WaybackClient;
use Register\Core\HttpClient\HttpClient;
use Register\Core\HttpClient\HttpResponse;

final class WaybackClientTest extends Unit
{
    public function testAcceptsOnlyAvailableSuccessfulReplayAndPinsArchiveHost(): void
    {
        $httpClient = new WaybackRecordingClient(new HttpResponse(
            statusCode: 200,
            content: json_encode([
                'archived_snapshots' => [
                    'closest' => [
                        'available' => true,
                        'status'    => '200',
                        'timestamp' => '20240102030405',
                        'url'       => 'http://web.archive.org/web/20240102030405/https://old.example/item',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ));

        $result = $this->client($httpClient)->lookup('https://old.example/item?a=1', 1_700_000_000);

        self::assertSame(ArchiveStatus::AVAILABLE, $result->status);
        self::assertSame(
            'https://web.archive.org/web/20240102030405/https://old.example/item',
            $result->url,
        );
        self::assertStringContainsString('url=https%3A%2F%2Fold.example%2Fitem%3Fa%3D1', $httpClient->url);
        self::assertStringContainsString('timestamp=20231114221320', $httpClient->url);
        self::assertSame('GET', $httpClient->method);
        self::assertSame('application/json', $httpClient->headers['Accept']);
        self::assertSame('93.184.216.34', $httpClient->options[HttpClient::RESOLVE_IP]);
        self::assertFalse($httpClient->options[HttpClient::FOLLOW_REDIRECTS]);
        self::assertSame(65_536, $httpClient->options[HttpClient::MAX_RESPONSE_BYTES]);
        self::assertSame(1, $httpClient->options[HttpClient::CONNECT_TIMEOUT]);
        self::assertSame(2, $httpClient->options[HttpClient::READ_TIMEOUT]);
    }

    public function testReturnsMissingWhenNoUsableSnapshotExists(): void
    {
        $httpClient = new WaybackRecordingClient(new HttpResponse(
            statusCode: 200,
            content: '{"archived_snapshots":{}}',
        ));

        $result = $this->client($httpClient)->lookup('https://old.example/', 1_700_000_000);

        self::assertSame(ArchiveStatus::MISSING, $result->status);
        self::assertNull($result->url);
    }

    public function testRejectsAReplayUrlOutsideTheWaybackHost(): void
    {
        $httpClient = new WaybackRecordingClient(new HttpResponse(
            statusCode: 200,
            content: '{"archived_snapshots":{"closest":{"available":true,"status":"200",'
                . '"timestamp":"20240102030405","url":"https://evil.example/web/20240102030405/x"}}}',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsafe replay URL');
        $this->client($httpClient)->lookup('https://old.example/', 1_700_000_000);
    }

    public function testRejectsAReplayUrlWithAnExplicitPortOrFragment(): void
    {
        $httpClient = new WaybackRecordingClient(new HttpResponse(
            statusCode: 200,
            content: '{"archived_snapshots":{"closest":{"available":true,"status":"200",'
                . '"timestamp":"20240102030405","url":"https://web.archive.org:443/web/20240102030405/x#part"}}}',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsafe replay URL');
        $this->client($httpClient)->lookup('https://old.example/', 1_700_000_000);
    }

    private function client(WaybackRecordingClient $httpClient): WaybackClient
    {
        $resolver = new class implements HostResolverInterface {
            /** @return list<string> */
            #[\Override]
            public function resolve(string $host, ?float $timeoutSeconds = null): array
            {
                return $host === 'archive.org' ? ['93.184.216.34'] : [];
            }
        };

        return new WaybackClient($httpClient, new PublicAddressGuard($resolver));
    }
}

/** @internal */
final class WaybackRecordingClient implements LinkHttpClientInterface
{
    public string $method = '';

    public string $url = '';

    /** @var array<string, string> */
    public array $headers = [];

    /** @var array<string, int|bool|string> */
    public array $options = [];

    public function __construct(private readonly HttpResponse $response)
    {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, int|bool|string> $options
     */
    #[\Override]
    public function request(string $method, string $url, array $headers, array $options): HttpResponse
    {
        $this->method  = $method;
        $this->url     = $url;
        $this->headers = $headers;
        $this->options = $options;

        return $this->response;
    }

    #[\Override]
    public function resolveRedirectUrl(string $location, string $currentUrl): string
    {
        throw new \LogicException('Wayback requests must not follow redirects.');
    }
}
