<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license MIT
 * @package S2
 */

declare(strict_types = 1);

namespace unit\extensions\s2_counter;

use Codeception\Test\Unit;

if (!defined('S2_COUNTER_FUNCTIONS_LOADED')) {
    require_once __DIR__ . '/../../../../_extensions/s2_counter/functions.php';
}

final class RssUserAgentParserTest extends Unit
{
    #[\Override]
    protected function _before(): void
    {
    }

    #[\Override]
    protected function _after(): void
    {
    }

    // tests
    public function testParseRssReadersUserAgents(): void
    {
        $log = file_get_contents('_tests/_resources/rss.log');
        if ($log === false) {
            throw new \RuntimeException('Unable to read the RSS log fixture.');
        }

        self::assertSame(203, s2_counter_get_total_readers($log));
    }
}
