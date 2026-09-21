<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Analytics;

use Codeception\Test\Unit;
use Register\Module\Analytics\BotDetector;
use Register\Module\Analytics\NonInteractiveRequestDetector;
use Symfony\Component\HttpFoundation\Request;

final class NonInteractiveRequestDetectorTest extends Unit
{
    public function testKeepsCrawlerAndPrefetchResponsesInTheSameNonInteractiveVariant(): void
    {
        $detector = new NonInteractiveRequestDetector(new BotDetector());

        $crawler = Request::create('https://example.test/', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Baiduspider-render/2.0)',
        ]);
        self::assertSame('bot', $detector->reason($crawler));

        $prefetch = Request::create('https://example.test/', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/141.0',
            'HTTP_SEC_PURPOSE' => 'prefetch',
        ]);
        self::assertSame('prefetch', $detector->reason($prefetch));

        $reader = Request::create('https://example.test/', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/141.0',
        ]);
        self::assertNull($detector->reason($reader));
    }
}
