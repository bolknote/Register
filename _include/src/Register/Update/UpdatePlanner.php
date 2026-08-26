<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update;

final readonly class UpdatePlanner
{
    public function __construct(
        private string $applicationRoot,
        private string $publicRoot,
    ) {
    }

    public function plan(ReleaseManifest $installed, ReleaseManifest $incoming, int $schemaGeneration): UpdatePlan
    {
        $this->assertEnvironment($installed, $incoming, $schemaGeneration);

        $installedFiles = $installed->filesByKey();
        $incomingFiles  = $incoming->filesByKey();
        $writes         = [];
        $deletes        = [];
        $unchanged      = [];
        $conflicts      = [];
        $writeBytes     = 0;

        foreach ($incomingFiles as $key => $incomingFile) {
            $installedFile = $installedFiles[$key] ?? null;
            $liveHash      = $this->liveHash($incomingFile);
            if ($liveHash === $incomingFile->sha256) {
                if ($this->liveModeMatches($incomingFile)) {
                    $unchanged[] = $key;
                } else {
                    $writes[] = $key;
                    $writeBytes += $incomingFile->size;
                }

                continue;
            }

            if (!$installedFile instanceof ReleaseFile) {
                if ($liveHash !== null) {
                    $conflicts[] = $key . ' already exists but was not managed by the installed release';
                    continue;
                }
            } elseif ($liveHash !== null && $liveHash !== $installedFile->sha256) {
                $conflicts[] = $key . ' differs from the installed release';
                continue;
            }

            $writes[] = $key;
            $writeBytes += $incomingFile->size;
        }

        foreach ($installedFiles as $key => $installedFile) {
            if (isset($incomingFiles[$key])) {
                continue;
            }

            $liveHash = $this->liveHash($installedFile);
            if ($liveHash === null) {
                $unchanged[] = $key;
            } elseif ($liveHash === $installedFile->sha256) {
                $deletes[] = $key;
            } else {
                $conflicts[] = $key . ' was modified and is absent from the incoming release';
            }
        }

        sort($writes, SORT_STRING);
        sort($deletes, SORT_STRING);
        sort($unchanged, SORT_STRING);
        sort($conflicts, SORT_STRING);

        return new UpdatePlan($writes, $deletes, $unchanged, $conflicts, $writeBytes);
    }

    private function assertEnvironment(
        ReleaseManifest $installed,
        ReleaseManifest $incoming,
        int $schemaGeneration,
    ): void {
        $applicationRoot = realpath($this->applicationRoot);
        $publicRoot      = realpath($this->publicRoot);
        if ($applicationRoot === false || $publicRoot === false || $applicationRoot !== $publicRoot) {
            throw new \RuntimeException('Self-update requires the single-root shared-hosting layout.');
        }

        if (!$incoming->isNewerThan($installed)) {
            throw new \RuntimeException('The uploaded release is not newer than the installed release.');
        }

        if (version_compare(PHP_VERSION, $incoming->minimumPhp, '<')) {
            throw new \RuntimeException('The uploaded release requires PHP ' . $incoming->minimumPhp . ' or newer.');
        }

        if ($schemaGeneration < $incoming->schemaFrom || $schemaGeneration > $incoming->schemaTo) {
            throw new \RuntimeException(sprintf(
                'Database generation %d cannot be upgraded by this release (supported range %d-%d).',
                $schemaGeneration,
                $incoming->schemaFrom,
                $incoming->schemaTo,
            ));
        }
    }

    private function liveHash(ReleaseFile $file): ?string
    {
        $filename = $this->livePath($file);
        if (!file_exists($filename) && !is_link($filename)) {
            return null;
        }

        if (!is_file($filename) || is_link($filename)) {
            return 'unsafe';
        }

        $hash = hash_file('sha256', $filename);
        if (!\is_string($hash)) {
            throw new \RuntimeException('Unable to hash an installed file: ' . $file->key());
        }

        return $hash;
    }

    private function liveModeMatches(ReleaseFile $file): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return true;
        }

        $permissions = fileperms($this->livePath($file));
        if (!\is_int($permissions)) {
            throw new \RuntimeException('Unable to inspect an installed file mode: ' . $file->key());
        }

        return (($permissions & 0111) !== 0 ? 0755 : 0644) === $file->mode;
    }

    private function livePath(ReleaseFile $file): string
    {
        return rtrim($this->applicationRoot, '/\\') . '/' . $file->path;
    }
}
