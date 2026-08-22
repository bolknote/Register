<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update;

final readonly class UpdateStorage
{
    public const int CHUNK_BYTES = 1024 * 1024;

    public const int MAX_ARCHIVE_BYTES = 512 * 1024 * 1024;

    public const int SESSION_RETENTION_SECONDS = 7 * 24 * 60 * 60;

    private const array PRUNABLE_STATUSES = [
        'uploading',
        'uploaded',
        'ready',
        'blocked',
        'complete',
    ];

    public function __construct(private string $directory)
    {
    }

    /** @return array<string, mixed> */
    public function start(string $filename, int $size): array
    {
        $filename = basename($filename);
        if ($filename === ''
            || \strlen($filename) > 180
            || preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]*\.(?:zip|tar\.gz|tar\.bz2)$/Di', $filename) !== 1
        ) {
            throw new \InvalidArgumentException('The release archive filename is invalid.');
        }

        if ($size < 1 || $size > self::MAX_ARCHIVE_BYTES) {
            throw new \InvalidArgumentException('The release archive size is invalid or unsupported.');
        }

        $this->ensureDirectory();
        $this->pruneExpired();
        $id        = bin2hex(random_bytes(16));
        $session   = $this->sessionDirectory($id);
        if (!mkdir($session, 0700)) {
            throw new \RuntimeException('Unable to create the update upload directory.');
        }

        $archivePath = $session . '/' . $filename;
        $archive = fopen($archivePath, 'xb');
        if ($archive === false) {
            $this->removeTree($session);
            throw new \RuntimeException('Unable to create the update upload file.');
        }

        fclose($archive);
        register_call_without_warnings(static fn(): bool => chmod($archivePath, 0600));

        $now   = gmdate(\DateTimeInterface::ATOM);
        $state = [
            'format'     => 1,
            'id'         => $id,
            'filename'   => $filename,
            'size'       => $size,
            'received'   => 0,
            'status'     => 'uploading',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->writeState($id, $state);

        return $this->publicState($state);
    }

    /** @return array<string, mixed> */
    public function append(string $id, int $offset, string $chunkPath): array
    {
        return $this->exclusive($id, function (array $state) use ($id, $offset, $chunkPath): array {
            if (($state['status'] ?? null) !== 'uploading') {
                throw new \RuntimeException('This update upload is no longer accepting chunks.');
            }

            $received = $this->stateInt($state, 'received');
            $total    = $this->stateInt($state, 'size');
            if ($offset !== $received) {
                throw new \RuntimeException('The update chunk offset does not match the server state.');
            }

            if (!is_file($chunkPath) || is_link($chunkPath)) {
                throw new \RuntimeException('The update chunk is missing or unsafe.');
            }

            $chunkSize = filesize($chunkPath);
            if (!\is_int($chunkSize)
                || $chunkSize < 1
                || $chunkSize > self::CHUNK_BYTES
                || $received + $chunkSize > $total
            ) {
                throw new \RuntimeException('The update chunk has an invalid size.');
            }

            $input  = fopen($chunkPath, 'rb');
            $output = fopen($this->archivePath($id), 'c+b');
            if ($input === false || $output === false) {
                if (\is_resource($input)) {
                    fclose($input);
                }

                if (\is_resource($output)) {
                    fclose($output);
                }

                throw new \RuntimeException('Unable to open the update upload stream.');
            }

            try {
                if (fseek($output, $received) !== 0
                    || stream_copy_to_stream($input, $output, $chunkSize) !== $chunkSize
                    || !fflush($output)
                ) {
                    throw new \RuntimeException('Unable to persist the update chunk.');
                }
            } finally {
                fclose($input);
                fclose($output);
            }

            $state['received']   = $received + $chunkSize;
            $state['status']     = $state['received'] === $total ? 'uploaded' : 'uploading';
            $state['updated_at'] = gmdate(\DateTimeInterface::ATOM);
            $this->writeState($id, $state);

            return $this->publicState($state);
        });
    }

    /** @return array<string, mixed> */
    public function load(string $id): array
    {
        $this->assertId($id);
        $json = file_get_contents($this->sessionDirectory($id) . '/state.json');
        if (!\is_string($json)) {
            throw new \RuntimeException('The update session does not exist.');
        }

        try {
            $state = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The update session state is corrupt.', 0, $exception);
        }

        if (!\is_array($state)
            || ($state['format'] ?? null) !== 1
            || ($state['id'] ?? null) !== $id
            || !\is_string($state['status'] ?? null)
        ) {
            throw new \RuntimeException('The update session state is invalid.');
        }

        return $state;
    }

    /** @return array<string, mixed>|null */
    public function latestRecoverable(): ?array
    {
        if (!is_dir($this->directory) || is_link($this->directory)) {
            return null;
        }

        $entries = scandir($this->directory);
        if ($entries === false) {
            return null;
        }

        $best         = null;
        $bestPriority = -1;
        $bestUpdated  = '';
        foreach ($entries as $id) {
            if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
                continue;
            }

            try {
                $state = $this->load($id);
            } catch (\Throwable) {
                continue;
            }

            $status = $state['status'] ?? null;
            if (!\is_string($status)) {
                continue;
            }

            $priority = match ($status) {
                'backing_up', 'applying_files', 'rollback_failed', 'files_switched',
                'migrating', 'opening_site', 'migration_failed' => 2,
                'ready', 'blocked' => 1,
                default => 0,
            };
            $updated = \is_string($state['updated_at'] ?? null) ? $state['updated_at'] : '';
            if ($priority === 0
                || $priority < $bestPriority
                || ($priority === $bestPriority && strcmp($updated, $bestUpdated) <= 0)
            ) {
                continue;
            }

            $best         = $state;
            $bestPriority = $priority;
            $bestUpdated  = $updated;
        }

        return \is_array($best) ? $this->publicState($best) : null;
    }

    /**
     * @template T
     * @param callable(array<string, mixed>): T $callback
     * @return T
     */
    public function exclusive(string $id, callable $callback): mixed
    {
        $this->assertId($id);
        $lock = fopen($this->sessionDirectory($id) . '/lock', 'c+b');
        if ($lock === false) {
            throw new \RuntimeException('The update session does not exist.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock the update session.');
            }

            return $callback($this->load($id));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $state */
    public function save(string $id, array $state): void
    {
        $state['updated_at'] = gmdate(\DateTimeInterface::ATOM);
        $this->writeState($id, $state);
    }

    public function archivePath(string $id): string
    {
        $state = $this->load($id);
        $filename = $state['filename'] ?? null;
        if (!\is_string($filename) || basename($filename) !== $filename) {
            throw new \RuntimeException('The update archive filename is invalid.');
        }

        return $this->sessionDirectory($id) . '/' . $filename;
    }

    public function stageRoot(string $id): string
    {
        $this->assertId($id);

        return $this->sessionDirectory($id) . '/stage';
    }

    public function rollbackRoot(string $id): string
    {
        $this->assertId($id);

        return $this->sessionDirectory($id) . '/rollback';
    }

    /** Removes staging left by an interrupted prepare request so verification can be retried. */
    public function resetStage(string $id): void
    {
        $stageRoot = $this->stageRoot($id);
        if (is_link($stageRoot) || (file_exists($stageRoot) && !is_dir($stageRoot))) {
            throw new \RuntimeException('The update staging path is unsafe.');
        }

        $this->removeTree($stageRoot);
    }

    /** Removes bulky release data after a successful update while retaining its small audit state. */
    public function cleanupCompleted(string $id): void
    {
        $state = $this->load($id);
        if (($state['status'] ?? null) !== 'complete') {
            throw new \RuntimeException('Only a completed update session can be cleaned up.');
        }

        $filename = $state['filename'] ?? null;
        if (!\is_string($filename) || basename($filename) !== $filename) {
            throw new \RuntimeException('The update archive filename is invalid.');
        }

        $archive = $this->sessionDirectory($id) . '/' . $filename;
        if ((is_file($archive) || is_link($archive)) && !unlink($archive)) {
            throw new \RuntimeException('Unable to remove the completed update archive.');
        }

        $this->removeTree($this->stageRoot($id));
        $this->removeTree($this->rollbackRoot($id));
    }

    /**
     * Deletes abandoned non-critical sessions. Recovery data for an in-progress switch or migration
     * is deliberately retained without an expiry.
     */
    public function pruneExpired(?int $now = null): int
    {
        if (!is_dir($this->directory) || is_link($this->directory)) {
            return 0;
        }

        $entries = scandir($this->directory);
        if ($entries === false) {
            return 0;
        }

        $now     ??= time();
        $removed = 0;
        foreach ($entries as $id) {
            if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
                continue;
            }

            try {
                $state = $this->load($id);
            } catch (\Throwable) {
                continue;
            }

            if (!$this->isExpiredAndPrunable($state, $now)) {
                continue;
            }

            $lock = fopen($this->sessionDirectory($id) . '/lock', 'c+b');
            if ($lock === false) {
                continue;
            }

            $tombstone = rtrim($this->directory, '/\\')
                . '/.expired-' . $id . '-' . bin2hex(random_bytes(6));
            try {
                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    continue;
                }

                $state = $this->load($id);
                if (!$this->isExpiredAndPrunable($state, $now)) {
                    continue;
                }

                if (!rename($this->sessionDirectory($id), $tombstone)) {
                    continue;
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }

            $this->removeTree($tombstone);
            ++$removed;
        }

        return $removed;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function publicState(array $state): array
    {
        $allowed = [
            'id', 'filename', 'size', 'received', 'status', 'created_at', 'updated_at',
            'release_id', 'version', 'built_at', 'schema_from', 'schema_to', 'plan', 'backup', 'message',
        ];

        return array_intersect_key($state, array_flip($allowed));
    }

    /** @param array<string, mixed> $state */
    private function writeState(string $id, array $state): void
    {
        $this->assertId($id);
        $session = $this->sessionDirectory($id);
        if (!is_dir($session) || is_link($session)) {
            throw new \RuntimeException('The update session directory is missing or unsafe.');
        }

        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $temporary = $session . '/.state-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $json, LOCK_EX) !== \strlen($json)
            || (DIRECTORY_SEPARATOR !== '\\' && !chmod($temporary, 0600))
            || !rename($temporary, $session . '/state.json')
        ) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw new \RuntimeException('Unable to persist the update session state.');
        }
    }

    private function ensureDirectory(): void
    {
        if (is_link($this->directory)) {
            throw new \RuntimeException('The update storage directory must not be a symbolic link.');
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create the update storage directory.');
        }

        if (DIRECTORY_SEPARATOR !== '\\' && !chmod($this->directory, 0700)) {
            throw new \RuntimeException('Unable to secure the update storage directory.');
        }
    }

    private function sessionDirectory(string $id): string
    {
        return rtrim($this->directory, '/\\') . '/' . $id;
    }

    private function assertId(string $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('The update session ID is invalid.');
        }
    }

    /** @param array<string, mixed> $state */
    private function stateInt(array $state, string $key): int
    {
        $value = $state[$key] ?? null;
        if (!\is_int($value)) {
            throw new \RuntimeException('The update session numeric state is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $state */
    private function isExpiredAndPrunable(array $state, int $now): bool
    {
        $status    = $state['status'] ?? null;
        $updatedAt = $state['updated_at'] ?? null;
        if (!\is_string($status)
            || !\in_array($status, self::PRUNABLE_STATUSES, true)
            || !\is_string($updatedAt)
        ) {
            return false;
        }

        $updated = strtotime($updatedAt);

        return \is_int($updated) && $updated <= $now - self::SESSION_RETENTION_SECONDS;
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
