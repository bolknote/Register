<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

final class QueueRunnerLock
{
    /** @var resource|null */
    private mixed $handle = null;

    public function __construct(private readonly string $filename)
    {
        if (!str_starts_with($filename, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('The queue runner lock filename must be absolute.');
        }
    }

    public function acquire(): bool
    {
        if (\is_resource($this->handle)) {
            throw new \LogicException('The queue runner lock is already acquired.');
        }

        $directory = \dirname($this->filename);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Unable to create queue runner lock directory "%s".', $directory));
        }

        $handle = s2_call_without_warnings(fn() => fopen($this->filename, 'c'));
        if (!\is_resource($handle)) {
            throw new \RuntimeException(\sprintf('Unable to open queue runner lock file "%s".', $this->filename));
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        $this->handle = $handle;
        return true;
    }

    public function release(): void
    {
        if (!\is_resource($this->handle)) {
            return;
        }

        $handle       = $this->handle;
        $this->handle = null;
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function __destruct()
    {
        $this->release();
    }
}
