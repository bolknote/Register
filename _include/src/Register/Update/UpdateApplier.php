<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update;

final readonly class UpdateApplier
{
    private string $root;

    public function __construct(string $applicationRoot, string $publicRoot)
    {
        $resolvedApplicationRoot = realpath($applicationRoot);
        $resolvedPublicRoot      = realpath($publicRoot);
        if ($resolvedApplicationRoot === false
            || $resolvedPublicRoot === false
            || $resolvedApplicationRoot !== $resolvedPublicRoot
        ) {
            throw new \InvalidArgumentException('The update target must use one shared-hosting document root.');
        }

        $this->root = $resolvedApplicationRoot;
    }

    public function apply(
        string          $stageRoot,
        string          $rollbackRoot,
        ReleaseManifest $installed,
        ReleaseManifest $incoming,
        UpdatePlan      $plan,
    ): void {
        if (!$plan->canApply()) {
            throw new \RuntimeException('An update with file conflicts cannot be applied.');
        }

        if (!is_dir($stageRoot . '/root')) {
            throw new \RuntimeException('The staged release is incomplete.');
        }

        if (file_exists($rollbackRoot) || is_link($rollbackRoot)) {
            throw new \RuntimeException('The update rollback directory already exists.');
        }

        if (!mkdir($rollbackRoot, 0700, true)) {
            throw new \RuntimeException('Unable to create the update rollback directory.');
        }

        $installedFiles = $installed->filesByKey();
        $incomingFiles  = $incoming->filesByKey();
        $journal        = [];
        try {
            foreach ([...$plan->writes, ...$plan->deletes] as $key) {
                $file = $incomingFiles[$key] ?? $installedFiles[$key] ?? null;
                if (!$file instanceof ReleaseFile) {
                    throw new \LogicException('The update plan references an unknown file: ' . $key);
                }

                $journal[] = $this->backupDestination($rollbackRoot, $file);
            }

            $journal[] = $this->backupManifest($rollbackRoot);
            $this->writeJournal($rollbackRoot, $journal);

            foreach ($plan->writes as $key) {
                $file = $incomingFiles[$key] ?? null;
                if (!$file instanceof ReleaseFile) {
                    throw new \LogicException('The update write plan references an unknown file: ' . $key);
                }

                $source = $stageRoot . '/root/' . $file->path;
                $this->assertExpectedFile($source, $file);
                $this->installFile($source, $this->destination($file), $file->mode);
            }

            foreach ($plan->deletes as $key) {
                $file = $installedFiles[$key] ?? null;
                if (!$file instanceof ReleaseFile) {
                    throw new \LogicException('The update delete plan references an unknown file: ' . $key);
                }

                $destination = $this->destination($file);
                if ((is_file($destination) || is_link($destination)) && !unlink($destination)) {
                    throw new \RuntimeException('Unable to remove an obsolete release file: ' . $key);
                }

                $this->invalidateOpcode($destination);
            }

            $manifestSource = $stageRoot . '/root/register-release.json';
            if (!is_file($manifestSource) || is_link($manifestSource)) {
                throw new \RuntimeException('The staged release manifest is missing.');
            }

            $this->installFile($manifestSource, $this->root . '/register-release.json', 0644);
        } catch (\Throwable $throwable) {
            try {
                $this->restoreEntries($rollbackRoot, $journal);
                $this->removeTree($rollbackRoot);
            } catch (\Throwable $rollbackFailure) {
                throw new \RuntimeException(
                    'The update failed and its automatic file rollback also failed: ' . $rollbackFailure->getMessage(),
                    0,
                    $throwable,
                );
            }

            throw $throwable;
        }
    }

    public function rollback(string $rollbackRoot): void
    {
        $journalPath = $rollbackRoot . '/journal.json';
        if (is_link($journalPath)) {
            throw new \RuntimeException('The update rollback journal must not be a symbolic link.');
        }

        $json = file_get_contents($journalPath);
        if (!\is_string($json)) {
            throw new \RuntimeException('The update rollback journal is missing.');
        }

        try {
            $journal = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The update rollback journal is corrupt.', 0, $exception);
        }

        if (!\is_array($journal)) {
            throw new \RuntimeException('The update rollback journal is invalid.');
        }

        $this->restoreEntries($rollbackRoot, $journal);
        $this->removeTree($rollbackRoot);
    }

    /** Recovers a process that stopped before the normal applier catch block could finish. */
    public function rollbackInterrupted(string $rollbackRoot): void
    {
        if (!file_exists($rollbackRoot) && !is_link($rollbackRoot)) {
            return;
        }

        if (!is_dir($rollbackRoot) || is_link($rollbackRoot)) {
            throw new \RuntimeException('The interrupted update rollback directory is unsafe.');
        }

        if (is_file($rollbackRoot . '/journal.json') || is_link($rollbackRoot . '/journal.json')) {
            $this->rollback($rollbackRoot);

            return;
        }

        // Live writes begin only after the complete journal has been persisted.
        $this->removeTree($rollbackRoot);
    }

    /** @return array{target:string,path:string,existed:bool,mode:int} */
    private function backupDestination(string $rollbackRoot, ReleaseFile $file): array
    {
        return $this->backupPath($rollbackRoot, $file->target, $file->path, $this->destination($file));
    }

    /** @return array{target:string,path:string,existed:bool,mode:int} */
    private function backupManifest(string $rollbackRoot): array
    {
        return $this->backupPath(
            $rollbackRoot,
            ReleaseFile::TARGET_ROOT,
            'register-release.json',
            $this->root . '/register-release.json',
        );
    }

    /** @return array{target:string,path:string,existed:bool,mode:int} */
    private function backupPath(string $rollbackRoot, string $target, string $path, string $source): array
    {
        if (!file_exists($source) && !is_link($source)) {
            return ['target' => $target, 'path' => $path, 'existed' => false, 'mode' => 0644];
        }

        if (!is_file($source) || is_link($source)) {
            throw new \RuntimeException('An update destination is not a regular file: ' . $target . ':' . $path);
        }

        $permissions = fileperms($source);
        $mode        = \is_int($permissions) && ($permissions & 0111) !== 0 ? 0755 : 0644;
        $backup      = $rollbackRoot . '/files/' . $target . '/' . $path;
        $this->createParent($backup);
        if (!copy($source, $backup) || (DIRECTORY_SEPARATOR !== '\\' && !chmod($backup, 0600))) {
            throw new \RuntimeException('Unable to back up an update destination: ' . $target . ':' . $path);
        }

        return ['target' => $target, 'path' => $path, 'existed' => true, 'mode' => $mode];
    }

    /** @param list<array{target:string,path:string,existed:bool,mode:int}> $journal */
    private function writeJournal(string $rollbackRoot, array $journal): void
    {
        $json = json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $temporary = $rollbackRoot . '/.journal-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $json, LOCK_EX) !== \strlen($json)
            || (DIRECTORY_SEPARATOR !== '\\' && !chmod($temporary, 0600))
            || !rename($temporary, $rollbackRoot . '/journal.json')
        ) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw new \RuntimeException('Unable to write the update rollback journal.');
        }
    }

    /** @param array<mixed> $journal */
    private function restoreEntries(string $rollbackRoot, array $journal): void
    {
        foreach ($journal as $entry) {
            if (!\is_array($entry)
                || !\is_string($entry['target'] ?? null)
                || !\is_string($entry['path'] ?? null)
                || !\is_bool($entry['existed'] ?? null)
                || !\is_int($entry['mode'] ?? null)
                || !ReleaseFile::isSafeRelativePath($entry['path'])
                || $entry['target'] !== ReleaseFile::TARGET_ROOT
            ) {
                throw new \RuntimeException('The update rollback journal contains an invalid entry.');
            }

            $destination = $this->root($entry['target']) . '/' . $entry['path'];
            $this->removeInterruptedTemporaryFiles(dirname($destination));
            if (!$entry['existed']) {
                if ((is_file($destination) || is_link($destination)) && !unlink($destination)) {
                    throw new \RuntimeException('Unable to remove a newly installed file during rollback.');
                }

                $this->invalidateOpcode($destination);
                continue;
            }

            $backup = $rollbackRoot . '/files/' . $entry['target'] . '/' . $entry['path'];
            if (!is_file($backup) || is_link($backup)) {
                throw new \RuntimeException('An update rollback file is missing.');
            }

            $this->installFile($backup, $destination, $entry['mode']);
        }
    }

    private function assertExpectedFile(string $source, ReleaseFile $file): void
    {
        if (!is_file($source) || is_link($source) || filesize($source) !== $file->size) {
            throw new \RuntimeException('A staged update file is missing or changed: ' . $file->key());
        }

        $hash = hash_file('sha256', $source);
        if (!\is_string($hash) || !hash_equals($file->sha256, $hash)) {
            throw new \RuntimeException('A staged update file failed verification: ' . $file->key());
        }
    }

    private function installFile(string $source, string $destination, int $mode): void
    {
        if (!is_file($source) || is_link($source)) {
            throw new \RuntimeException('An update source file is missing or unsafe.');
        }

        $this->createParent($destination);
        if (is_link($destination) || (file_exists($destination) && !is_file($destination))) {
            throw new \RuntimeException('An update destination is unsafe: ' . $destination);
        }

        $temporary = dirname($destination) . '/.register-update-' . bin2hex(random_bytes(8));
        if (!copy($source, $temporary)
            || (DIRECTORY_SEPARATOR !== '\\' && !chmod($temporary, $mode))
            || !rename($temporary, $destination)
        ) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw new \RuntimeException('Unable to atomically install an update file: ' . $destination);
        }

        $this->invalidateOpcode($destination);
    }

    /** Removes a copy that can remain if PHP stops between copy() and the atomic rename(). */
    private function removeInterruptedTemporaryFiles(string $directory): void
    {
        $temporaryFiles = glob($directory . '/.register-update-*', GLOB_NOSORT);
        if ($temporaryFiles === false) {
            throw new \RuntimeException('Unable to inspect interrupted update files.');
        }

        foreach ($temporaryFiles as $temporaryFile) {
            if (preg_match('/^\.register-update-[a-f0-9]{16}$/D', basename($temporaryFile)) !== 1) {
                continue;
            }

            if ((is_file($temporaryFile) || is_link($temporaryFile)) && !unlink($temporaryFile)) {
                throw new \RuntimeException('Unable to remove an interrupted update file.');
            }
        }
    }

    private function createParent(string $filename): void
    {
        $directory = dirname($filename);
        if (is_link($directory)) {
            throw new \RuntimeException('An update directory must not be a symbolic link.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create an update destination directory.');
        }
    }

    private function destination(ReleaseFile $file): string
    {
        return $this->root($file->target) . '/' . $file->path;
    }

    private function root(string $target): string
    {
        if ($target !== ReleaseFile::TARGET_ROOT) {
            throw new \LogicException('The update references an unsupported release target.');
        }

        return $this->root;
    }

    private function invalidateOpcode(string $filename): void
    {
        if (\function_exists('opcache_invalidate')) {
            opcache_invalidate($filename, true);
        }
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
