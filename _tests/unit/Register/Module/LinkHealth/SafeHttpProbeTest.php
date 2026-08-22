<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\LinkHealth;

use Codeception\Test\Unit;
use Register\Module\LinkHealth\LinkHttpClientInterface;
use Register\Module\LinkHealth\LinkProbeMethod;
use Register\Module\LinkHealth\LinkProbeResult;
use Register\Module\LinkHealth\LinkProbeState;
use Register\Module\LinkHealth\SafeHttpProbe;
use Register\Core\HttpClient\HttpClient;
use Register\Core\HttpClient\HttpResponse;
use Register\Core\HttpClient\Remote\HostResolverInterface;
use Register\Core\HttpClient\Remote\PublicAddressGuard;
use Register\Core\HttpClient\Remote\RemoteHostResolverUnavailable;
use Register\Core\HttpClient\Remote\RemoteHostResolutionTimedOut;

final class SafeHttpProbeTest extends Unit
{
    public function testValidatesAndPinsEveryRedirectHop(): void
    {
        $client = new RecordingLinkHttpClient([
            new HttpResponse(['HTTP/1.1 302 Found', 'Location: https://second.example/final'], 302, ''),
            new HttpResponse(['HTTP/1.1 200 OK'], 200, ''),
        ]);
        $probe = new SafeHttpProbe($client, new PublicAddressGuard($this->resolver([
            'first.example'  => ['93.184.216.34'],
            'second.example' => ['93.184.216.35'],
        ])));

        $firstStep = $probe->step(LinkProbeState::initial('https://first.example/start'));
        $nextState = $firstStep->nextState;
        self::assertNotNull($nextState);
        self::assertNull($firstStep->result);
        self::assertSame(1, $client->callCount());

        $secondStep = $probe->step($nextState);
        $result     = $secondStep->result;
        self::assertNotNull($result);

        self::assertSame(200, $result->statusCode);
        self::assertSame('https://second.example/final', $result->effectiveUrl);
        self::assertNull($secondStep->nextState);
        self::assertSame('93.184.216.34', $client->calls[0]['options'][HttpClient::RESOLVE_IP]);
        self::assertSame('93.184.216.35', $client->calls[1]['options'][HttpClient::RESOLVE_IP]);
        self::assertFalse($client->calls[0]['options'][HttpClient::FOLLOW_REDIRECTS]);
    }

    public function testFallsBackToBoundedGetWhenHeadFails(): void
    {
        $client = new RecordingLinkHttpClient([
            new HttpResponse(['HTTP/1.1 405 Method Not Allowed'], 405, ''),
            new HttpResponse(['HTTP/1.1 200 OK'], 200, 'partial'),
        ]);
        $probe = new SafeHttpProbe($client, new PublicAddressGuard($this->resolver([
            'headless.example' => ['93.184.216.34'],
        ])));

        $headStep = $probe->step(LinkProbeState::initial('https://headless.example/'));
        $getState = $headStep->nextState;
        self::assertNotNull($getState);
        self::assertSame(LinkProbeMethod::GET, $getState->method);
        self::assertSame(1, $client->callCount());

        $getStep = $probe->step($getState);
        $result  = $getStep->result;
        self::assertNotNull($result);

        self::assertSame(200, $result->statusCode);
        self::assertSame(['HEAD', 'GET'], array_column($client->calls, 'method'));
        self::assertSame([], $client->calls[1]['headers']);
        self::assertSame(1, $client->calls[1]['options'][HttpClient::CONNECT_TIMEOUT]);
        self::assertSame(2, $client->calls[1]['options'][HttpClient::READ_TIMEOUT]);
        self::assertSame(16_384, $client->calls[1]['options'][HttpClient::MAX_RESPONSE_BYTES]);
    }

    public function testBlocksPrivateAddressIntroducedByRedirect(): void
    {
        $client = new RecordingLinkHttpClient([
            new HttpResponse(['HTTP/1.1 302 Found', 'Location: http://internal.example/'], 302, ''),
        ]);
        $probe = new SafeHttpProbe($client, new PublicAddressGuard($this->resolver([
            'first.example'    => ['93.184.216.34'],
            'internal.example' => ['127.0.0.1'],
        ])));

        $redirectStep = $probe->step(LinkProbeState::initial('https://first.example/'));
        $nextState    = $redirectStep->nextState;
        self::assertNotNull($nextState);
        self::assertSame('http://internal.example/', $nextState->url);
        self::assertSame(1, $client->callCount());

        $blockedStep = $probe->step($nextState);
        $result      = $blockedStep->result;
        self::assertNotNull($result);

        self::assertSame(LinkProbeResult::ERROR_UNSAFE, $result->errorReason);
        self::assertSame('http://internal.example/', $result->effectiveUrl);
        self::assertCount(1, $client->calls);
    }

    public function testStopsAtThePersistedRedirectLimit(): void
    {
        $client = new RecordingLinkHttpClient([
            new HttpResponse(['HTTP/1.1 302 Found', 'Location: https://second.example/'], 302, ''),
        ]);
        $probe = new SafeHttpProbe($client, new PublicAddressGuard($this->resolver([
            'first.example' => ['93.184.216.34'],
        ])));

        $step = $probe->step(new LinkProbeState(
            'https://first.example/',
            redirects: LinkProbeState::MAX_REDIRECTS,
        ));
        $result = $step->result;
        self::assertNotNull($result);

        self::assertSame(LinkProbeResult::ERROR_REDIRECT, $result->errorReason);
        self::assertNull($step->nextState);
        self::assertSame(1, $client->callCount());
    }

    public function testTreatsBoundedDnsTimeoutAsLocalResolverFailure(): void
    {
        $resolver = new class implements HostResolverInterface {
            /** @return list<string> */
            #[\Override]
            public function resolve(string $host, ?float $timeoutSeconds = null): array
            {
                throw new RemoteHostResolutionTimedOut('DNS timed out.');
            }
        };
        $probe  = new SafeHttpProbe(new RecordingLinkHttpClient([]), new PublicAddressGuard($resolver));
        $result = $probe->step(LinkProbeState::initial('https://slow.example/'))->result;

        self::assertNotNull($result);
        self::assertSame(LinkProbeResult::ERROR_RESOLVER, $result->errorReason);
    }

    public function testReportsUnavailableBoundedResolverWithoutPoisoningDnsHealth(): void
    {
        $resolver = new class implements HostResolverInterface {
            /** @return list<string> */
            #[\Override]
            public function resolve(string $host, ?float $timeoutSeconds = null): array
            {
                throw new RemoteHostResolverUnavailable('Resolver unavailable.');
            }
        };
        $probe  = new SafeHttpProbe(new RecordingLinkHttpClient([]), new PublicAddressGuard($resolver));
        $result = $probe->step(LinkProbeState::initial('https://resolver.example/'))->result;

        self::assertNotNull($result);
        self::assertSame(LinkProbeResult::ERROR_RESOLVER, $result->errorReason);
    }

    /** @param array<string, list<string>> $answers */
    private function resolver(array $answers): HostResolverInterface
    {
        return new SafeHttpProbeHostResolver($answers);
    }
}

/** @internal */
final readonly class SafeHttpProbeHostResolver implements HostResolverInterface
{
    /** @param array<string, list<string>> $answers */
    public function __construct(private array $answers)
    {
    }

    /** @return list<string> */
    #[\Override]
    public function resolve(string $host, ?float $timeoutSeconds = null): array
    {
        return $this->answers[$host] ?? [];
    }
}

/** @internal */
final class RecordingLinkHttpClient implements LinkHttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, options: array<string, int|bool|string>}> */
    public array $calls = [];

    /** @param list<HttpResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, int|bool|string> $options
     */
    #[\Override]
    public function request(string $method, string $url, array $headers, array $options): HttpResponse
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'options' => $options];

        return array_shift($this->responses) ?? throw new \LogicException('No fake response remains.');
    }

    #[\Override]
    public function resolveRedirectUrl(string $location, string $currentUrl): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        $origin = (string)parse_url($currentUrl, PHP_URL_SCHEME) . '://'
            . (string)parse_url($currentUrl, PHP_URL_HOST);

        return $origin . '/' . ltrim($location, '/');
    }

    public function callCount(): int
    {
        return \count($this->calls);
    }
}
