<?php

declare(strict_types = 1);

namespace Register\Media;

/** Detects any file mutation overlapping a database-and-media backup. */
final class MediaMutationTracker
{
    /** @var resource|null */
    private mixed $lock = null;

    private int $depth = 0;

    public function __construct(private readonly string $lockFile)
    {
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    public function run(\Closure $operation): mixed
    {
        $release = $this->begin();
        try {
            return $operation();
        } finally {
            $release();
        }
    }

    /** @return \Closure(): void */
    public function begin(): \Closure
    {
        if ($this->lock === null) {
            $lock = $this->open();
            try {
                if (!flock($lock, LOCK_EX)) {
                    throw new \RuntimeException('Unable to lock media mutations.');
                }

                $revision = bin2hex(random_bytes(32));
                if (!rewind($lock) || !ftruncate($lock, 0)
                    || fwrite($lock, $revision) !== \strlen($revision) || !fflush($lock)
                ) {
                    throw new \RuntimeException('Unable to record a media mutation.');
                }

                $this->lock = $lock;
            } catch (\Throwable $throwable) {
                fclose($lock);
                throw $throwable;
            }
        }

        ++$this->depth;
        $released = false;

        return function () use (&$released): void {
            if ($released) {
                return;
            }

            $released = true;
            if (--$this->depth === 0 && \is_resource($this->lock)) {
                flock($this->lock, LOCK_UN);
                fclose($this->lock);
                $this->lock = null;
            }
        };
    }

    /** Never wait here: an editor may hold a database transaction while changing media. */
    public function revision(): string
    {
        if ($this->depth > 0) {
            throw new \RuntimeException('Media files are being changed; retry the backup.');
        }

        $lock = $this->open();
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Media files are being changed; retry the backup.');
            }

            $revision = stream_get_contents($lock);
            if ($revision === false) {
                throw new \RuntimeException('Unable to read the media mutation revision.');
            }

            return $revision;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function assertUnchanged(string $revision): void
    {
        if (!hash_equals($revision, $this->revision())) {
            throw new \RuntimeException('Media files changed during the backup; retry the backup.');
        }
    }

    /** @return resource */
    private function open(): mixed
    {
        $directory = dirname($this->lockFile);
        if (!is_dir($directory)
            && !register_call_without_warnings(static fn(): bool => mkdir($directory, 0700, true))
            && !is_dir($directory)
        ) {
            throw new \RuntimeException('Unable to create the media mutation lock directory.');
        }

        if (is_link($directory) || is_link($this->lockFile)) {
            throw new \RuntimeException('The media mutation lock must not be a symbolic link.');
        }

        $lock = register_call_without_warnings(fn() => fopen($this->lockFile, 'c+b'));
        if ($lock === false) {
            throw new \RuntimeException('Unable to open the media mutation lock.');
        }

        $stat = fstat($lock);
        $pathStat = register_call_without_warnings(fn(): array|false => lstat($this->lockFile));
        if ($stat === false || $pathStat === false || ($stat['mode'] & 0170000) !== 0100000
            || $stat['dev'] !== $pathStat['dev'] || $stat['ino'] !== $pathStat['ino']
            || (DIRECTORY_SEPARATOR !== '\\' && !register_call_without_warnings(fn(): bool => chmod($this->lockFile, 0600)))
        ) {
            fclose($lock);
            throw new \RuntimeException('Unable to secure the media mutation lock.');
        }

        return $lock;
    }
}
