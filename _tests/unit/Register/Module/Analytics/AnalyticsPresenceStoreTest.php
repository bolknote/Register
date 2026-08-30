<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Analytics;

use Codeception\Test\Unit;
use Register\Module\Analytics\AnalyticsPresenceStore;
use Symfony\Component\Filesystem\Filesystem;

final class AnalyticsPresenceStoreTest extends Unit
{
    private string $directory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->directory = sys_get_temp_dir() . '/register_analytics_presence_' . bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testSharesFreshPresenceThroughBoundedFilesystemShards(): void
    {
        $writer = new AnalyticsPresenceStore($this->directory, '0123456789abcdef', useApcu: false);
        $reader = new AnalyticsPresenceStore($this->directory, '0123456789abcdef', useApcu: false);
        $writer->touch(str_repeat('1', 64), str_repeat('a', 64), '/first', 'First', 1000);
        $writer->touch(str_repeat('2', 64), str_repeat('a', 64), '/second', 'Second', 1010);

        self::assertSame([
            [
                'visitor_key' => str_repeat('a', 64),
                'path'        => '/second',
                'title'       => 'Second',
                'seen_at'     => 1010,
            ],
            [
                'visitor_key' => str_repeat('a', 64),
                'path'        => '/first',
                'title'       => 'First',
                'seen_at'     => 1000,
            ],
        ], $reader->snapshot(1020));

        $writer->touch(str_repeat('1', 64), str_repeat('b', 64), '/updated', 'Updated', 1030);
        $snapshot = $reader->snapshot(1040);
        self::assertCount(2, $snapshot);
        self::assertSame('/updated', $snapshot[0]['path']);
        self::assertSame(str_repeat('b', 64), $snapshot[0]['visitor_key']);
        self::assertSame([], $reader->snapshot(1100));
    }
}
