<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Http;

use Codeception\Test\Unit;
use S2\Cms\HttpClient\HttpClient;
use S2\Cms\HttpClient\HttpClientInterface;
use S2\Cms\HttpClient\HttpResponse;
use S2\Cms\HttpClient\Remote\HostResolverInterface;
use S2\Cms\HttpClient\Remote\PublicAddressGuard;
use S2\Cms\HttpClient\Remote\SafeRemoteHttpClient;
use S2\Cms\HttpClient\Remote\SafeRemoteRequestOptions;
use S2\Cms\HttpClient\Remote\UnsafeRemoteAddress;
use S2\Cms\Queue\QueueExecutionBudget;
use S2\Cms\Queue\QueueTimeBudgetExceeded;

final class SafeRemoteHttpClientTest extends Unit
{
    public function testPinsOneHopAndReturnsAValidatedRedirectCursor(): void
    {
        $transport = new RecordingSafeRemoteTransport(new HttpResponse(
            ['HTTP/1.1 302 Found', 'Location: /actor'],
            302,
            '',
        ));
        $client = new SafeRemoteHttpClient(
            $transport,
            new PublicAddressGuard(new SafeRemoteHostResolver([
                'social.example' => ['93.184.216.34'],
            ])),
        );

        $result = $client->requestHop(
            'POST',
            'https://social.example/inbox',
            ['Content-Type' => 'application/activity+json'],
            '{"type":"Follow"}',
            new SafeRemoteRequestOptions(1, 2, 4096),
        );

        self::assertSame('https://social.example/inbox', $result->effectiveUrl);
        self::assertSame('https://social.example/actor', $result->redirectUrl);
        self::assertSame('93.184.216.34', $transport->options[HttpClient::RESOLVE_IP]);
        self::assertFalse($transport->options[HttpClient::FOLLOW_REDIRECTS]);
        self::assertSame(4096, $transport->options[HttpClient::MAX_RESPONSE_BYTES]);
        self::assertSame(1000, $transport->options[HttpClient::CONNECT_TIMEOUT_MILLISECONDS]);
        self::assertSame(3000, $transport->options[HttpClient::TOTAL_TIMEOUT_MILLISECONDS]);
        self::assertSame('{"type":"Follow"}', $transport->body);
    }

    public function testRejectsPrivateDestinationBeforeOpeningTransport(): void
    {
        $transport = new RecordingSafeRemoteTransport(new HttpResponse());
        $client = new SafeRemoteHttpClient(
            $transport,
            new PublicAddressGuard(new SafeRemoteHostResolver([
                'internal.example' => ['127.0.0.1'],
            ])),
        );

        $this->expectException(UnsafeRemoteAddress::class);
        try {
            $client->requestHop('GET', 'https://internal.example/actor');
        } finally {
            self::assertSame(0, $transport->requests);
        }
    }

    public function testRequiresHttpsByDefault(): void
    {
        $transport = new RecordingSafeRemoteTransport(new HttpResponse());
        $client = new SafeRemoteHttpClient(
            $transport,
            new PublicAddressGuard(new SafeRemoteHostResolver([])),
        );

        $this->expectException(UnsafeRemoteAddress::class);
        $client->requestHop('GET', 'http://public.example/actor');
    }

    public function testCanExplicitlyAllowHttpForNonProductionTools(): void
    {
        $transport = new RecordingSafeRemoteTransport(new HttpResponse(['HTTP/1.1 200 OK'], 200, 'ok'));
        $client = new SafeRemoteHttpClient(
            $transport,
            new PublicAddressGuard(new SafeRemoteHostResolver([
                'public.example' => ['93.184.216.34'],
            ])),
        );

        $result = $client->requestHop(
            'GET',
            'http://public.example/',
            options: new SafeRemoteRequestOptions(requireHttps: false),
        );

        self::assertSame(200, $result->response->statusCode);
    }

    public function testDerivesDnsAndCurlLimitsFromRemainingQueueBudget(): void
    {
        $clock     = new SafeRemoteMutableClock();
        $transport = new RecordingSafeRemoteTransport(new HttpResponse(['HTTP/1.1 200 OK'], 200, 'ok'));
        $resolver  = new SafeRemoteHostResolver(
            ['public.example' => ['93.184.216.34']],
            static function () use ($clock): void {
                $clock->now += 0.4;
            },
        );
        $client = new SafeRemoteHttpClient($transport, new PublicAddressGuard($resolver));

        $client->requestHop(
            'GET',
            'https://public.example/',
            options: new SafeRemoteRequestOptions(deadlineSafetyMargin: 0.25),
            budget: new QueueExecutionBudget(2.0, $clock(...)),
        );

        self::assertEqualsWithDelta(1.75, $resolver->timeoutSeconds, 0.000_001);
        $totalMilliseconds = $transport->options[HttpClient::TOTAL_TIMEOUT_MILLISECONDS];
        self::assertIsInt($totalMilliseconds);
        self::assertGreaterThanOrEqual(1349, $totalMilliseconds);
        self::assertLessThanOrEqual(1350, $totalMilliseconds);
        self::assertSame(
            $totalMilliseconds,
            $transport->options[HttpClient::CONNECT_TIMEOUT_MILLISECONDS],
        );
    }

    public function testRefusesToStartDnsWhenOnlySafetyMarginRemains(): void
    {
        $clock     = new SafeRemoteMutableClock();
        $transport = new RecordingSafeRemoteTransport(new HttpResponse());
        $resolver  = new SafeRemoteHostResolver(['public.example' => ['93.184.216.34']]);
        $client    = new SafeRemoteHttpClient($transport, new PublicAddressGuard($resolver));

        $this->expectException(QueueTimeBudgetExceeded::class);
        try {
            $client->requestHop(
                'GET',
                'https://public.example/',
                budget: new QueueExecutionBudget(0.2, $clock(...)),
            );
        } finally {
            self::assertSame(0, $resolver->requests);
            self::assertSame(0, $transport->requests);
        }
    }
}

/** @internal */
final class SafeRemoteHostResolver implements HostResolverInterface
{
    public int $requests = 0;

    public ?float $timeoutSeconds = null;

    /**
     * @param array<string, list<string>> $answers
     * @param null|\Closure(): void $onResolve
     */
    public function __construct(
        private readonly array $answers,
        private readonly ?\Closure $onResolve = null,
    )
    {
    }

    /** @return list<string> */
    #[\Override]
    public function resolve(string $host, ?float $timeoutSeconds = null): array
    {
        ++$this->requests;
        $this->timeoutSeconds = $timeoutSeconds;
        ($this->onResolve ?? static function (): void {})();

        return $this->answers[$host] ?? [];
    }
}

/** @internal */
final class SafeRemoteMutableClock
{
    public float $now = 10.0;

    public function __invoke(): float
    {
        return $this->now;
    }
}

/** @internal */
final class RecordingSafeRemoteTransport implements HttpClientInterface
{
    public int $requests = 0;

    /** @var array<string, int|bool|string> */
    public array $options = [];

    public ?string $body = null;

    public function __construct(private readonly HttpResponse $response)
    {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, int|bool|string> $options
     */
    #[\Override]
    public function request(
        string  $method,
        string  $url,
        array   $headers = [],
        ?string $body = null,
        array   $options = [],
    ): HttpResponse {
        unset($method, $url, $headers);
        ++$this->requests;
        $this->options = $options;
        $this->body    = $body;

        return $this->response;
    }

    #[\Override]
    public function resolveRedirectUrl(string $location, string $currentUrl): string
    {
        return (new HttpClient())->resolveRedirectUrl($location, $currentUrl);
    }
}
