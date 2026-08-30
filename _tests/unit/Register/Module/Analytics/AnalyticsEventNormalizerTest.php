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
use Register\Module\Analytics\AnalyticsEventNormalizer;
use Symfony\Component\HttpFoundation\Request;

final class AnalyticsEventNormalizerTest extends Unit
{
    public function testClassifiesSourceDomainsWithoutSubstringFalsePositives(): void
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
        $normalizer = new AnalyticsEventNormalizer($provider->getStringProxy('salt'));
        $request    = Request::create('https://blog.example/_analytics/collect');
        $receivedAt = 1_788_100_000;

        foreach ([
            'https://t.me/register'             => 'social',
            'https://m.youtube.com/watch?v=123' => 'social',
            'https://www.google.co.uk/search'   => 'search',
            'https://yandex.ru/search/'         => 'search',
            'https://evilgoogle.com/article'    => 'referral',
            'https://blog.example/post'         => 'internal',
            ''                                  => 'direct',
        ] as $referrer => $expectedKind) {
            $event = $normalizer->normalize([
                'id'          => str_repeat('1', 32),
                'type'        => 'pageview',
                'occurred_at' => $receivedAt * 1000,
                'session_id'  => str_repeat('2', 32),
                'pageview_id' => str_repeat('3', 32),
                'path'        => '/article',
                'title'       => 'Article',
                'referrer'    => $referrer,
                'utm'         => [],
            ], $request, str_repeat('4', 32), $receivedAt);

            self::assertSame($expectedKind, $event->sourceKind, $referrer);
        }
    }
}
