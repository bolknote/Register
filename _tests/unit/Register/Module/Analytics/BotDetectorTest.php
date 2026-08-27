<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Analytics;

use Codeception\Test\Unit;
use Register\Module\Analytics\BotDetector;

final class BotDetectorTest extends Unit
{
    public function testRecognizesBotsCaseInsensitively(): void
    {
        $detector = new BotDetector();

        self::assertTrue($detector->isBot('Mozilla/5.0 compatible ExampleBot/1.0'));
        self::assertTrue($detector->isBot('EXAMPLE CRAWLER'));
        self::assertTrue($detector->isBot('Mozilla/5.0 HeadlessChrome/140.0'));
        self::assertTrue($detector->isBot('Mozilla/5.0 Chrome-Lighthouse'));
        self::assertFalse($detector->isBot('Mozilla/5.0 Firefox/141.0'));
    }
}
