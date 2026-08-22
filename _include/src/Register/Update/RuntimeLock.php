<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update;

final class RuntimeLock
{
    private const string FILENAME = '_cache/.register-runtime.lock';

    /** @var resource|null */
    private mixed $handle;

    private function __construct(mixed $handle)
    {
        if (!\is_resource($handle)) {
            throw new \InvalidArgumentException('A runtime lock requires a file handle.');
        }

        $this->handle = $handle;
    }

    public static function acquireShared(string $applicationRoot): self
    {
        $handle = self::open($applicationRoot);
        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            throw new \RuntimeException('Unable to acquire the Register runtime lock.');
        }

        return new self($handle);
    }

    public static function acquireExclusive(string $applicationRoot, int $timeoutSeconds = 30): self
    {
        $handle   = self::open($applicationRoot);
        $deadline = microtime(true) + (float)max(1, $timeoutSeconds);
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return new self($handle);
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        fclose($handle);
        throw new \RuntimeException('Active requests did not finish before the update timeout.');
    }

    public function release(): void
    {
        if (!\is_resource($this->handle)) {
            return;
        }

        $handle = $this->handle;
        $this->handle = null;
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function __destruct()
    {
        $this->release();
    }

    /** @return resource */
    private static function open(string $applicationRoot): mixed
    {
        $filename = rtrim($applicationRoot, '/\\') . '/' . self::FILENAME;
        if (is_link($filename)) {
            throw new \RuntimeException('The Register runtime lock must not be a symbolic link.');
        }

        $handle = fopen($filename, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the Register runtime lock.');
        }

        if (DIRECTORY_SEPARATOR !== '\\' && !chmod($filename, 0600)) {
            fclose($handle);
            throw new \RuntimeException('Unable to secure the Register runtime lock.');
        }

        return $handle;
    }
}
