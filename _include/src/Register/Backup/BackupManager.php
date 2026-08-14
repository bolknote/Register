<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup;

use Psr\Log\LoggerInterface;

final readonly class BackupManager
{
    public const int AUTOMATIC_INTERVAL_SECONDS = 24 * 60 * 60;

    private const string FILE_PATTERN = '/^register-backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.zip$/D';

    public function __construct(
        private DatabaseSnapshotter $databaseSnapshotter,
        private LoggerInterface     $logger,
        private string              $backupDirectory,
        private string              $imageDirectory,
        private int                 $retention,
        private string              $registerVersion,
    ) {
        if ($retention < 1) {
            throw new \InvalidArgumentException('At least one backup must be retained.');
        }
    }

    public function createNow(?int $now = null): BackupFile
    {
        $result = $this->withLock(fn(): BackupFile => $this->createLocked($now ?? time()));
        if (!$result instanceof BackupFile) {
            throw new \LogicException('A forced backup did not produce an archive.');
        }

        return $result;
    }

    public function createIfDue(?int $now = null): ?BackupFile
    {
        $now ??= time();

        return $this->withLock(function () use ($now): ?BackupFile {
            $latestCreatedAt = $this->latestUnlocked()?->createdAt;
            if ($latestCreatedAt !== null && $latestCreatedAt > $now - self::AUTOMATIC_INTERVAL_SECONDS) {
                return null;
            }

            return $this->createLocked($now);
        });
    }

    public function latest(): ?BackupFile
    {
        return $this->latestUnlocked();
    }

    public function retention(): int
    {
        return $this->retention;
    }

    private function withLock(callable $callback): ?BackupFile
    {
        $this->ensureDirectory();
        $lock = fopen($this->backupDirectory . '/.backup.lock', 'c+b');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open the backup lock.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire the backup lock.');
            }

            $result = $callback();
            if ($result !== null && !$result instanceof BackupFile) {
                throw new \LogicException('The backup lock callback returned an invalid result.');
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function createLocked(int $now): BackupFile
    {
        $token       = bin2hex(random_bytes(8));
        $snapshot    = null;
        $archivePath = $this->backupDirectory . '/.' . $token . '.zip';
        $writer      = null;

        try {
            $snapshot = $this->databaseSnapshotter->create($this->backupDirectory . '/.' . $token . '-database');
            $writer   = new PortableZipWriter($archivePath);

            $databaseBytes = $writer->addFile($snapshot->archiveName, $snapshot->path, $now);
            $databaseHash  = hash_file('sha256', $snapshot->path);
            if ($databaseHash === false) {
                throw new \RuntimeException('Unable to hash the database snapshot.');
            }

            $mediaFiles = 0;
            $mediaBytes = 0;
            foreach ($this->mediaFiles() as $mediaFile) {
                $mediaBytes += $writer->addFile($mediaFile['archive'], $mediaFile['path'], $now);
                ++$mediaFiles;
            }

            $manifest = json_encode([
                'format'         => 'register-backup',
                'format_version' => 1,
                'created_at'     => gmdate(\DateTimeInterface::ATOM, $now),
                'engine'         => [
                    'name'    => 'Register',
                    'version' => $this->registerVersion,
                ],
                'database' => [
                    'driver' => $snapshot->driver,
                    'file'   => $snapshot->archiveName,
                    'bytes'  => $databaseBytes,
                    'sha256' => $databaseHash,
                ],
                'media' => [
                    'directory' => 'media/',
                    'files'     => $mediaFiles,
                    'bytes'     => $mediaBytes,
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $writer->addString('manifest.json', $manifest . "\n", $now);
            $writer->addString('RESTORE.txt', $this->restoreInstructions($snapshot), $now);
            $writer->close();

            $name = 'register-backup-' . gmdate('Ymd-His', $now) . '-' . substr($token, 0, 8) . '.zip';
            $finalPath = $this->backupDirectory . '/' . $name;
            if (!touch($archivePath, $now) || !rename($archivePath, $finalPath)) {
                throw new \RuntimeException('Unable to publish the completed backup archive.');
            }

            s2_call_without_warnings(static fn(): bool => chmod($finalPath, 0600));

            $size = filesize($finalPath);
            if ($size === false) {
                throw new \RuntimeException('Unable to determine the completed backup size.');
            }

            $backup = new BackupFile($finalPath, $name, $now, $size);
            $this->prune($backup);
            $this->logger->info('Register backup created.', [
                'file'        => $name,
                'bytes'       => $size,
                'media_files' => $mediaFiles,
            ]);

            return $backup;
        } finally {
            if ($writer instanceof PortableZipWriter) {
                $writer->abort();
            }

            if (is_file($archivePath)) {
                s2_call_without_warnings(static fn(): bool => unlink($archivePath));
            }

            if ($snapshot instanceof DatabaseSnapshot && is_file($snapshot->path)) {
                s2_call_without_warnings(static fn(): bool => unlink($snapshot->path));
            }
        }
    }

    /** @return list<array{archive:string,path:string}> */
    private function mediaFiles(): array
    {
        if (!is_dir($this->imageDirectory)) {
            return [];
        }

        $root = rtrim($this->imageDirectory, '/\\');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        $files = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->isLink() || !$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $relativePath = substr($path, \strlen($root) + 1);
            if ($relativePath === '') {
                continue;
            }

            $files[] = [
                'archive' => 'media/' . str_replace('\\', '/', $relativePath),
                'path'    => $path,
            ];
        }

        usort($files, static fn(array $left, array $right): int => $left['archive'] <=> $right['archive']);

        return $files;
    }

    private function restoreInstructions(DatabaseSnapshot $snapshot): string
    {
        $databaseInstruction = match ($snapshot->driver) {
            'sqlite' => 'With Register stopped, replace the configured SQLite database with database.sqlite.',
            'mysql'  => 'Import database.sql with the mysql client into an empty configured database.',
            'pgsql'  => 'Import database.sql with psql into the configured database.',
            default  => throw new \LogicException('Unsupported database snapshot driver.'),
        };

        return <<<TEXT
Register backup
===============

1. Keep this archive private: the database contains password hashes and private editorial data.
2. Stop writes to Register before restoring.
3. {$databaseInstruction}
4. Copy the contents of media/ into Register's configured media directory.
5. Clear Register's cache and run the normal queue/cron command.

config.php is intentionally not included because it contains deployment credentials.
See the Register backup documentation for database-specific commands and verification steps.
TEXT;
    }

    private function prune(BackupFile $createdBackup): void
    {
        $backups = $this->backups();
        while (\count($backups) > $this->retention) {
            $candidateIndex = null;
            foreach ($backups as $index => $backup) {
                if ($backup->path !== $createdBackup->path) {
                    $candidateIndex = $index;
                    break;
                }
            }

            if ($candidateIndex === null) {
                return;
            }

            $oldest = $backups[$candidateIndex];
            array_splice($backups, $candidateIndex, 1);
            if (!s2_call_without_warnings(static fn(): bool => unlink($oldest->path))) {
                $this->logger->warning('Unable to remove an expired Register backup.', ['file' => $oldest->name]);
            }
        }
    }

    private function latestUnlocked(): ?BackupFile
    {
        $backups = $this->backups();

        return $backups === [] ? null : $backups[array_key_last($backups)];
    }

    /** @return list<BackupFile> */
    private function backups(): array
    {
        if (!is_dir($this->backupDirectory)) {
            return [];
        }

        $paths = glob($this->backupDirectory . '/register-backup-*.zip', GLOB_NOSORT);
        if ($paths === false) {
            return [];
        }

        $backups = [];
        foreach ($paths as $path) {
            $name = basename($path);
            if (preg_match(self::FILE_PATTERN, $name) !== 1 || !is_file($path) || is_link($path)) {
                continue;
            }

            $createdAt = filemtime($path);
            $size      = filesize($path);
            if ($createdAt === false || $size === false) {
                continue;
            }

            $backups[] = new BackupFile($path, $name, $createdAt, $size);
        }

        usort($backups, static fn(BackupFile $left, BackupFile $right): int => [$left->createdAt, $left->name] <=> [$right->createdAt, $right->name]);

        return $backups;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->backupDirectory) && !mkdir($this->backupDirectory, 0700, true) && !is_dir($this->backupDirectory)) {
            throw new \RuntimeException('Unable to create the private backup directory.');
        }

        if (!is_writable($this->backupDirectory)) {
            throw new \RuntimeException('The backup directory is not writable.');
        }
    }
}
