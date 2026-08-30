<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Analytics;

use Codeception\Test\Unit;
use Register\Core\Config\DynamicConfigProvider;
use Register\Module\Analytics\AnalyticsRateLimiter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

final class AnalyticsRateLimiterTest extends Unit
{
    private string $directory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/register_analytics_rate_' . bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testFilesystemLimitIsSharedByLimiterInstances(): void
    {
        $provider = new class extends DynamicConfigProvider {
            #[\Override]
            public function get(string $paramName): mixed
            {
                return $paramName === 'salt'
                    ? str_repeat('a', 64)
                    : throw new \LogicException('Unexpected test configuration parameter.');
            }
        };
        $salt     = $provider->getStringProxy('salt');
        $request  = Request::create('https://example.test/_analytics/collect', server: [
            'REMOTE_ADDR' => '192.0.2.10',
        ]);
        $visitor = str_repeat('b', 64);
        $now     = 1788100000;

        $first = new AnalyticsRateLimiter($salt, $this->directory, useApcu: false);
        self::assertTrue($first->accepts($request, $visitor, 120, $now));

        $second = new AnalyticsRateLimiter($salt, $this->directory, useApcu: false);
        self::assertFalse($second->accepts($request, $visitor, 1, $now));
        self::assertTrue($second->accepts($request, $visitor, 1, $now + 60));

        $files = glob($this->directory . '/*.json');
        self::assertIsArray($files);
        self::assertNotEmpty($files);
        $contents = file_get_contents($files[0]);
        self::assertIsString($contents);
        self::assertStringNotContainsString('192.0.2.10', $contents);
        self::assertStringNotContainsString($visitor, $contents);
    }
}
