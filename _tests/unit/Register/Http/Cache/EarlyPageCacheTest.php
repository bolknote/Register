<?php

declare(strict_types = 1);

namespace unit\Register\Http\Cache;

use PHPUnit\Framework\TestCase;
use Register\Core\Http\Cache\EarlyPageCache;
use Register\Content\ContentId;
use Register\Module\Blog\Model\ContentViewResponseProcessor;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EarlyPageCacheTest extends TestCase
{
    private string $directory = '';

    private ?EarlyPageCache $cache = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/register-early-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->cache = new EarlyPageCache($this->directory . '/cache', $this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    private function cache(): EarlyPageCache
    {
        return $this->cache ?? throw new \LogicException('The cache fixture has not been initialized.');
    }

    public function testReadsAnAnonymousSnapshotAndKeepsItsSecurityHeaders(): void
    {
        self::assertNull($this->cache()->lookup($this->request()));
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), '<h1>Public page</h1>'));
        $hit = $this->cache()->lookup($this->request());
        self::assertNotNull($hit);
        self::assertSame('<h1>Public page</h1>', $hit['body']);
        self::assertSame(['hit'], $hit['headers']['x-register-early-cache']);
        self::assertSame(["default-src 'self'"], $hit['headers']['content-security-policy']);
        self::assertArrayNotHasKey('x-register-page-cache-key', $hit['headers']);
    }

    public function testBypassesEveryCookieAuthorizationQueryAndNonPublicMethod(): void
    {
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), 'public'));
        foreach ([['HTTP_COOKIE' => 'session=someone'], ['HTTP_COOKIE' => 'visitor=anonymous'],
            ['HTTP_AUTHORIZATION' => 'Bearer secret'], ['REDIRECT_HTTP_AUTHORIZATION' => 'Basic credentials'],
            ['PHP_AUTH_USER' => 'private'], ['PHP_AUTH_PW' => 'private'],
            ['REQUEST_URI' => '/example?reply_to=1'], ['REQUEST_URI' => '/example?utm_source=anything'],
            ['REQUEST_METHOD' => 'POST'], ['REQUEST_METHOD' => 'OPTIONS'],
            ['HTTP_X_REGISTER_NAVIGATION' => 'partial'], ['HTTP_RANGE' => 'bytes=0-10'],
            ['HTTP_CACHE_CONTROL' => 'no-cache'], ['HTTP_PRAGMA' => 'no-cache']] as $override) {
            self::assertNull($this->cache()->lookup($this->request($override)), implode(',', array_keys($override)));
        }
    }

    public function testNeverPublishesPrivateFormsNoncePagesAndUncachedResponses(): void
    {
        foreach ([
            ['set-cookie' => ['visitor=secret']],
            ['cache-control' => ['no-store, private']],
            ['content-security-policy' => ["script-src 'nonce-sensitive'"]],
            ['content-type' => ['application/json']],
            ['vary' => ['Authorization']],
        ] as $override) {
            self::assertFalse($this->cache()->store($this->request(), array_replace($this->headers(), $override), 'private'));
        }

        $headers = $this->headers();
        unset($headers['x-register-page-cache']);
        self::assertFalse($this->cache()->store($this->request(), $headers, 'not a shared response'));
        self::assertFalse($this->cache()->store($this->request(), $this->headers(), '<script nonce="private">alert(1)</script>'));
        self::assertNull($this->cache()->lookup($this->request()));
    }

    public function testNormalizesNullableFrameworkHeadersWithoutLosingTheirPrivacySignal(): void
    {
        self::assertTrue($this->cache()->store($this->request(), $this->headers() + ['x-optional' => [null, 'present']], 'public'));
        self::assertSame(['present'], $this->hit()['headers']['x-optional']);
        self::assertFalse($this->cache()->store($this->request(), $this->headers() + ['set-cookie' => [null]], 'private'));
        self::assertSame('bypass-response-set-cookie', $this->cache()->decision());
    }

    public function testSeparatesReaderCrawlerHostAndTransportRepresentations(): void
    {
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), 'reader'));
        self::assertNull($this->cache()->lookup($this->request(['REGISTER_EARLY_VARIANT' => 'crawler'])));
        self::assertTrue($this->cache()->store($this->request(['REGISTER_EARLY_VARIANT' => 'crawler']), $this->headers(), 'crawler'));
        self::assertSame('reader', $this->hit()['body']);
        self::assertSame('crawler', $this->hit(['REGISTER_EARLY_VARIANT' => 'crawler'])['body']);
        self::assertNull($this->cache()->lookup($this->request(['HTTP_HOST' => 'other.test'])));
        self::assertNull($this->cache()->lookup($this->request(['HTTPS' => 'on'])));
    }

    public function testHonorsExpiryMaintenanceAndConfigurationChanges(): void
    {
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), 'body', validUntil: 200, now: 100));
        self::assertNotNull($this->cache()->lookup($this->request(), 199));
        self::assertNull($this->cache()->lookup($this->request(), 200));
        file_put_contents($this->directory . '/.register-maintenance.json', '{}');
        self::assertNull($this->cache()->lookup($this->request(), 150));
        unlink($this->directory . '/.register-maintenance.json');
        file_put_contents($this->directory . '/config.php', '<?php return ["changed" => true];');
        self::assertNull($this->cache()->lookup($this->request(), 150));
    }

    public function testIgnoredForwardingCannotFragmentTheCacheAndTrustedForwardingFallsThrough(): void
    {
        $ignored = new EarlyPageCache($this->directory . '/cache', $this->directory, ignoreForwardingHeaders: true);
        self::assertTrue($ignored->store($this->request(), $this->headers(), 'public'));
        foreach (['HTTP_FORWARDED' => 'host=proxy.test;proto=https', 'HTTP_X_FORWARDED_HOST' => 'proxy.test',
            'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PORT' => '8443',
            'HTTP_X_FORWARDED_PREFIX' => '/proxy', 'HTTP_X_FORWARDED_FOR' => '192.0.2.1'] as $name => $value) {
            $proxy = [$name => $value, 'REMOTE_ADDR' => '198.51.100.1'];
            self::assertNull($this->cache()->lookup($this->request($proxy)));
            self::assertFalse($this->cache()->store($this->request($proxy), $this->headers(), 'trusted forwarding'));
            self::assertSame('bypass-request-forwarded', $this->cache()->decision());
            $hit = $ignored->lookup($this->request($proxy));
            self::assertNotNull($hit);
            self::assertSame('public', $hit['body']);
            self::assertTrue($ignored->store($this->request(array_replace($proxy, ['REMOTE_ADDR' => '198.51.100.2'])), $this->headers(), 'public'));
        }

        $files = glob($this->directory . '/cache/early-pages-v1/*/*.json');
        self::assertNotFalse($files);
        self::assertCount(1, $files);
    }

    public function testInvalidationRejectsAnInFlightStalePublisher(): void
    {
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), 'old'));
        $mutator = new EarlyPageCache($this->directory . '/cache', $this->directory);
        $mutator->invalidate();
        self::assertNull($this->cache()->lookup($this->request()));
        self::assertFalse($this->cache()->store($this->request(), $this->headers(), 'stale render'));
        $fresh = new EarlyPageCache($this->directory . '/cache', $this->directory);
        self::assertTrue($fresh->store($this->request(), $this->headers(), 'new'));
        self::assertSame('new', $this->hit()['body']);
    }

    public function testArbitraryXHttpsValuesAndEquivalentTransportFlagsShareOneSnapshot(): void
    {
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), 'http'));
        foreach (['on', 'off', 'https', 'arbitrary-1', 'arbitrary-2'] as $value) {
            $request = $this->request(['HTTP_X_HTTPS' => $value, 'HTTPS' => 'OFF', 'HTTP_HOST' => 'EXAMPLE.TEST']);
            self::assertSame('http', $this->cache()->lookup($request)['body'] ?? null);
            self::assertTrue($this->cache()->store($request, $this->headers(), 'http'));
        }

        self::assertCount(1, $this->snapshots());
        self::assertTrue($this->cache()->store($this->request(['HTTPS' => 'on']), $this->headers(), 'https'));
        self::assertSame('https', $this->hit(['HTTPS' => '1', 'HTTP_X_HTTPS' => 'untrusted'])['body']);
        self::assertSame('http', $this->hit()['body']);
        self::assertCount(2, $this->snapshots());
    }

    public function testEnginePulsesRemoveExpiredSnapshotsWithoutRevisitingTheirUrls(): void
    {
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), 'expired', validUntil: 101, now: 100));
        self::assertTrue($this->cache()->store($this->request(['REQUEST_URI' => '/fresh']), $this->headers(), 'fresh', now: 200));
        $this->cache()->publishView('post:1', 7, '<span>7 views</span>');
        for ($i = 0; $i < 256; ++$i) {
            $this->cache()->engineFinished(200 + $i * 10);
        }

        $snapshots = array_filter($this->snapshots(), static fn(string $path): bool => basename(dirname($path)) !== 'views');
        self::assertCount(1, $snapshots);
        self::assertSame('fresh', $this->cache()->lookup($this->request(['REQUEST_URI' => '/fresh']), 3000)['body'] ?? null);
        self::assertFileExists($this->directory . '/cache/early-pages-v1/views/' . hash('sha256', 'post:1') . '.json');
    }

    public function testPublicationCapsTheNumberOfSnapshotsInABucket(): void
    {
        $this->seedLegacyBucket(80);
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), 'updated'));
        self::assertLessThanOrEqual(32, count($this->snapshots()));
        self::assertSame('updated', $this->hit()['body']);
    }

    public function testPublicationCapsTheBytesInABucket(): void
    {
        $this->seedLegacyBucket(20, 90_000);
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), 'updated'));
        $bytes = 0;
        foreach ($this->snapshots() as $path) {
            $size = filesize($path);
            self::assertIsInt($size);
            $bytes += $size;
        }

        self::assertLessThanOrEqual(1_048_576, $bytes);
        self::assertSame('updated', $this->hit()['body']);
    }

    public function testLargeLegacyBucketsDrainInBoundedPasses(): void
    {
        $this->seedLegacyBucket(200);
        self::assertFalse($this->cache()->store($this->request(), $this->headers(), 'updated'));
        self::assertSame('bypass-capacity', $this->cache()->decision());
        self::assertGreaterThanOrEqual(201 - 128, count($this->snapshots()));
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), 'updated'));
        self::assertLessThanOrEqual(32, count($this->snapshots()));
    }

    public function testOversizedEntriesAndContendedWritersKeepTheExistingSnapshot(): void
    {
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), 'original'));
        self::assertFalse($this->cache()->store($this->request(), $this->headers(), base64_encode(random_bytes(700_000))));
        self::assertSame('bypass-capacity', $this->cache()->decision());
        $lock = fopen(dirname($this->snapshots()[0]) . '/bucket.lock', 'c+b');
        self::assertIsResource($lock);
        try {
            self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            self::assertFalse($this->cache()->store($this->request(), $this->headers(), 'contending writer'));
            self::assertSame('original', $this->hit()['body']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::assertTrue($this->cache()->store($this->request(), $this->headers(), 'updated'));
        self::assertSame('updated', $this->hit()['body']);
    }

    public function testGzipNegotiationConditionalRequestsAndHead(): void
    {
        $body = str_repeat('<p>A cached page.</p>', 100);
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), $body));
        $gzip = $this->cache()->lookup($this->request(['HTTP_ACCEPT_ENCODING' => 'br, gzip;q=1']));
        self::assertNotNull($gzip);
        self::assertSame(['gzip'], $gzip['headers']['content-encoding']);
        self::assertSame($body, gzdecode($gzip['body']));
        $identity = $this->cache()->lookup($this->request(['HTTP_ACCEPT_ENCODING' => 'gzip;q=0']));
        self::assertNotNull($identity);
        self::assertSame($body, $identity['body']);
        self::assertArrayNotHasKey('content-encoding', $identity['headers']);
        self::assertNull($this->cache()->lookup($this->request(['HTTP_ACCEPT_ENCODING' => 'identity;q=0,gzip;q=0'])));
        $conditional = $this->cache()->lookup($this->request(['HTTP_IF_NONE_MATCH' => $gzip['headers']['etag'][0]]));
        self::assertNotNull($conditional);
        self::assertSame(304, $conditional['status']);
        self::assertSame('', $conditional['body']);
        self::assertNotNull($this->cache()->lookup($this->request(['REQUEST_METHOD' => 'HEAD'])));
    }

    public function testViewCountersRefreshWithoutRebuildingThePage(): void
    {
        $old = '<span>1 view</span>';
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), '<h1>Article</h1>' . $old,
            ['post:42' => ['html' => $old, 'count' => 1]], 'post:42'));
        self::assertSame('<h1>Article</h1>' . $old, $this->hit()['body']);
        $this->cache()->publishView('post:42', 2, '<span>2 views</span>');
        self::assertSame('<h1>Article</h1><span>2 views</span>', $this->hit()['body']);
        $gzip = $this->cache()->lookup($this->request(['HTTP_ACCEPT_ENCODING' => 'gzip']));
        self::assertNotNull($gzip);
        self::assertSame('<h1>Article</h1><span>2 views</span>', gzdecode($gzip['body']));
        self::assertSame('post:42', $gzip['content_id']);
    }

    public function testCorruptSnapshotsAndSymlinksFailClosed(): void
    {
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), 'body'));
        $files = glob($this->directory . '/cache/early-pages-v1/*/*.json');
        self::assertNotFalse($files);
        self::assertCount(1, $files);
        file_put_contents($files[0], '{not json');
        self::assertNull($this->cache()->lookup($this->request()));
        unlink($files[0]);
        file_put_contents($this->directory . '/private.json', '{"body":"private"}');
        symlink($this->directory . '/private.json', $files[0]);
        self::assertNull($this->cache()->lookup($this->request()));
    }

    public function testOnlyOneRequestRunsPeriodicMaintenanceAndExpiredLeasesRetry(): void
    {
        self::assertTrue($this->cache()->requiresEngine(100));
        $other = new EarlyPageCache($this->directory . '/cache', $this->directory);
        self::assertFalse($other->requiresEngine(100));
        $this->cache()->engineFinished(100);
        self::assertFalse($other->requiresEngine(109));
        self::assertTrue($other->requiresEngine(110));
        $other->engineFinished(110);
        self::assertFalse($this->cache()->requiresEngine(119));
    }

    public function testLocationIsPublishedOnlyForTheEvaluatedConfigurationAndCurrentCode(): void
    {
        self::assertNull(EarlyPageCache::locate($this->directory));
        $stamp = EarlyPageCache::sourceStamp($this->directory);
        $this->cache()->publishLocation($stamp);
        self::assertSame(realpath($this->directory . '/cache'), EarlyPageCache::locate($this->directory)['directory'] ?? null);
        file_put_contents($this->directory . '/index.php', '<?php // A new release');
        self::assertNull(EarlyPageCache::locate($this->directory));
        $this->cache()->publishLocation($stamp);
        self::assertNull(EarlyPageCache::locate($this->directory));
        $disabled = new EarlyPageCache($this->directory . '/cache', $this->directory, disabled: true);
        $disabled->publishLocation(EarlyPageCache::sourceStamp($this->directory));
        self::assertNull(EarlyPageCache::locate($this->directory));
    }

    public function testEqualCountersOnDifferentArticlesRemainIndependent(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Views');
        $one = ContentId::post(1);
        $two = ContentId::post(2);
        $first = ContentViewResponseProcessor::renderCounter(0, $translator, $one);
        $second = ContentViewResponseProcessor::renderCounter(0, $translator, $two);
        self::assertNotSame($first, $second);
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), $first . '|' . $second, [
            'post:1' => ['html' => $first, 'count' => 0], 'post:2' => ['html' => $second, 'count' => 0],
        ]));
        $updated = ContentViewResponseProcessor::renderCounter(3, $translator, $two);
        $this->cache()->publishView('post:2', 3, $updated);
        self::assertSame($first . '|' . $updated, $this->hit()['body']);
        $this->cache()->publishView('post:2', 1, $second);
        self::assertSame($first . '|' . $updated, $this->hit()['body']);
    }

    public function testCorruptHeaderValuesAreNeverEmitted(): void
    {
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), 'body'));
        $files = glob($this->directory . '/cache/early-pages-v1/*/*.json');
        self::assertNotFalse($files);
        self::assertCount(1, $files);
        $entry = json_decode((string)file_get_contents($files[0]), true, 16, JSON_THROW_ON_ERROR);
        foreach ([['x-custom' => ["value\r\nSet-Cookie: private"]], ['set-cookie' => ['private']], ['x-custom' => [123]]] as $headers) {
            $entry['headers'] = $headers;
            file_put_contents($files[0], json_encode($entry, JSON_THROW_ON_ERROR));
            self::assertNull($this->cache()->lookup($this->request()));
        }
    }

    /** @return list<string> */
    private function snapshots(): array
    {
        $files = glob($this->directory . '/cache/early-pages-v1/*/*.json');
        self::assertNotFalse($files);
        return $files;
    }

    private function seedLegacyBucket(int $count, int $padding = 0): void
    {
        self::assertTrue($this->cache()->store($this->request(), $this->headers(), 'original'));
        $path = $this->snapshots()[0];
        $entry = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        $entry['legacy_padding'] = str_repeat('x', $padding);
        for ($i = 0; $i < $count; ++$i) {
            file_put_contents(dirname($path) . '/' . hash('sha256', 'legacy-snapshot-' . $i) . '.json', json_encode($entry, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * @param array<string,mixed> $override
     * @return array{status:int,headers:array<string,list<string>>,body:string,content_id:?string}
     */
    private function hit(array $override = []): array
    {
        $hit = $this->cache()->lookup($this->request($override));
        self::assertNotNull($hit);
        return $hit;
    }

    /**
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function request(array $override = []): array
    {
        return array_replace(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/example',
            'HTTP_HOST' => 'example.test', 'HTTP_USER_AGENT' => 'Mozilla/5.0 ordinary browser'], $override);
    }

    /** @return array<string,list<string>> */
    private function headers(): array
    {
        return ['content-type' => ['text/html; charset=UTF-8'], 'x-register-page-cache' => ['hit'],
            'x-register-page-cache-key' => ['internal'], 'content-security-policy' => ["default-src 'self'"]];
    }
}
