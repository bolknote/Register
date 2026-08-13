<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Analytics;

use Codeception\Test\Unit;
use Register\Module\Analytics\RssReaderParser;

final class RssReaderParserTest extends Unit
{
    public function testParsesLegacyRssReaderLog(): void
    {
        $log = file_get_contents('_tests/_resources/rss.log');
        if ($log === false) {
            throw new \RuntimeException('Unable to read the RSS log fixture.');
        }

        self::assertSame(203, (new RssReaderParser())->totalReaders($log));
    }

    public function testRecognizesAggregatorReportedReaderCount(): void
    {
        self::assertSame(
            ['Feedlysubscribers', 42],
            (new RssReaderParser())->aggregator('Feedly 42 subscribers'),
        );
    }
}
