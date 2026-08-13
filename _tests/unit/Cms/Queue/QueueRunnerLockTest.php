<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Queue;

use Codeception\Test\Unit;
use S2\Cms\Queue\QueueRunnerLock;

final class QueueRunnerLockTest extends Unit
{
    private string $directory = '';

    private string $filename = '';

    #[\Override]
    protected function _before(): void
    {
        $this->directory = sys_get_temp_dir() . '/register-runner-lock-' . bin2hex(random_bytes(8));
        $this->filename  = $this->directory . '/runner.lock';
    }

    #[\Override]
    protected function _after(): void
    {
        if (file_exists($this->filename)) {
            unlink($this->filename);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testOnlyOneRunnerAcquiresTheLock(): void
    {
        $first  = new QueueRunnerLock($this->filename);
        $second = new QueueRunnerLock($this->filename);

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire());

        $first->release();
        self::assertTrue($second->acquire());
    }

    public function testDestructorReleasesTheLock(): void
    {
        $first = new QueueRunnerLock($this->filename);
        self::assertTrue($first->acquire());
        unset($first);

        $second = new QueueRunnerLock($this->filename);
        self::assertTrue($second->acquire());
    }
}
