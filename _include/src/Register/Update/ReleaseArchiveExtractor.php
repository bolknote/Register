<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update;

final readonly class ReleaseArchiveExtractor
{
    private const int MAX_MANIFEST_BYTES = 8 * 1024 * 1024;

    public function __construct(private ArchiveCapabilities $capabilities)
    {
    }

    public function manifest(string $archivePath): ReleaseManifest
    {
        $format = $this->capabilities->detect($archivePath);
        $this->validateMagic($archivePath, $format);

        $json = $format === ArchiveCapabilities::FORMAT_ZIP
            ? $this->zipManifest($archivePath)
            : $this->pharManifest($archivePath);

        $manifest = ReleaseManifest::fromJson($json);
        $this->assertArchiveCoverage($archivePath, $format, $manifest);

        return $manifest;
    }

    public function extract(string $archivePath, string $stageRoot, ReleaseManifest $manifest): void
    {
        if (file_exists($stageRoot) || is_link($stageRoot)) {
            throw new \RuntimeException('The update staging directory already exists.');
        }

        if (!mkdir($stageRoot . '/app', 0700, true) || !mkdir($stageRoot . '/public', 0700, true)) {
            throw new \RuntimeException('Unable to create the update staging directory.');
        }

        try {
            $format = $this->capabilities->detect($archivePath);
            if ($format === ArchiveCapabilities::FORMAT_ZIP) {
                $this->extractZip($archivePath, $stageRoot, $manifest);
            } else {
                $this->extractPhar($archivePath, $stageRoot, $manifest);
            }

            $manifestPath = $stageRoot . '/app/register-release.json';
            $json = $manifest->toJson();
            if (file_put_contents($manifestPath, $json, LOCK_EX) !== \strlen($json)
                || (DIRECTORY_SEPARATOR !== '\\' && !chmod($manifestPath, 0644))
            ) {
                throw new \RuntimeException('Unable to stage the release manifest.');
            }
        } catch (\Throwable $throwable) {
            $this->removeTree($stageRoot);
            throw $throwable;
        }
    }

    private function zipManifest(string $archivePath): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($archivePath, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Unable to open the release ZIP.');
        }

        try {
            $this->validateZipEntries($zip);
            $stat = $zip->statName(ReleaseManifest::ARCHIVE_PATH, \ZipArchive::FL_UNCHANGED);
            if (!\is_array($stat)
                || $stat['size'] < 1
                || $stat['size'] > self::MAX_MANIFEST_BYTES
            ) {
                throw new \RuntimeException('The release ZIP has no valid manifest.');
            }

            $json = $zip->getFromName(ReleaseManifest::ARCHIVE_PATH, self::MAX_MANIFEST_BYTES, \ZipArchive::FL_UNCHANGED);
            if (!\is_string($json) || \strlen($json) !== $stat['size']) {
                throw new \RuntimeException('Unable to read the release ZIP manifest.');
            }

            return $json;
        } finally {
            $zip->close();
        }
    }

    private function pharManifest(string $archivePath): string
    {
        try {
            $phar = new \PharData($archivePath);
            if (!isset($phar[ReleaseManifest::ARCHIVE_PATH])) {
                throw new \RuntimeException('The release tar archive has no manifest.');
            }

            $entry = $phar[ReleaseManifest::ARCHIVE_PATH];
            if ($entry->isLink() || !$entry->isFile() || $entry->getSize() > self::MAX_MANIFEST_BYTES) {
                throw new \RuntimeException('The release tar manifest is invalid.');
            }

            $json = file_get_contents($entry->getPathname());
            if (!\is_string($json) || $json === '') {
                throw new \RuntimeException('Unable to read the release tar manifest.');
            }

            return $json;
        } catch (\UnexpectedValueException $exception) {
            throw new \RuntimeException('Unable to open the release tar archive.', 0, $exception);
        }
    }

    private function extractZip(string $archivePath, string $stageRoot, ReleaseManifest $manifest): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($archivePath, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Unable to open the release ZIP.');
        }

        try {
            $entries = $this->validateZipEntries($zip);
            $this->assertExpectedEntries($entries, $manifest);
            foreach ($manifest->files as $file) {
                $archiveName = $file->archivePath();
                $index       = $entries[$archiveName] ?? null;
                if (!\is_int($index)) {
                    throw new \RuntimeException('A release file is missing from the ZIP: ' . $archiveName);
                }

                $stat = $zip->statIndex($index, \ZipArchive::FL_UNCHANGED);
                if (!\is_array($stat) || $stat['size'] !== $file->size) {
                    throw new \RuntimeException('A release ZIP entry has the wrong size: ' . $archiveName);
                }

                $this->assertZipRegularFile($zip, $index, $archiveName);
                $input = $zip->getStreamIndex($index, 0);
                if (!\is_resource($input)) {
                    throw new \RuntimeException('Unable to read a release ZIP entry: ' . $archiveName);
                }

                try {
                    $this->copyVerified($input, $this->stagePath($stageRoot, $file), $file);
                } finally {
                    fclose($input);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function extractPhar(string $archivePath, string $stageRoot, ReleaseManifest $manifest): void
    {
        try {
            $phar = new \PharData($archivePath);
            $entries = $this->validatePharEntries($phar);
            $this->assertExpectedEntries($entries, $manifest);
            foreach ($manifest->files as $file) {
                $archiveName = $file->archivePath();
                if (!isset($phar[$archiveName])) {
                    throw new \RuntimeException('A release file is missing from the tar archive: ' . $archiveName);
                }

                $entry = $phar[$archiveName];
                if ($entry->isLink() || !$entry->isFile() || $entry->getSize() !== $file->size) {
                    throw new \RuntimeException('A release tar entry is invalid: ' . $archiveName);
                }

                $input = fopen($entry->getPathname(), 'rb');
                if ($input === false) {
                    throw new \RuntimeException('Unable to read a release tar entry: ' . $archiveName);
                }

                try {
                    $this->copyVerified($input, $this->stagePath($stageRoot, $file), $file);
                } finally {
                    fclose($input);
                }
            }
        } catch (\UnexpectedValueException $exception) {
            throw new \RuntimeException('Unable to open the release tar archive.', 0, $exception);
        }
    }

    /** @return array<string, int> */
    private function validateZipEntries(\ZipArchive $zip): array
    {
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index, \ZipArchive::FL_UNCHANGED);
            $name = \is_array($stat) ? $stat['name'] : null;
            if (!\is_string($name) || !ReleaseFile::isSafeRelativePath(rtrim($name, '/'))) {
                throw new \RuntimeException('The release ZIP contains an invalid entry path.');
            }

            if (isset($entries[$name])) {
                throw new \RuntimeException('The release ZIP contains a duplicate entry: ' . $name);
            }

            $entries[$name] = $index;
        }

        return $entries;
    }

    /** @return array<string, true> */
    private function validatePharEntries(\PharData $phar): array
    {
        $prefix   = 'phar://' . str_replace('\\', '/', $phar->getPath()) . '/';
        $iterator = new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::LEAVES_ONLY);
        $entries  = [];
        foreach ($iterator as $key => $entry) {
            $archiveKey = str_replace('\\', '/', (string)$key);
            if (!str_starts_with($archiveKey, $prefix)) {
                throw new \RuntimeException('The release tar archive contains an invalid entry path.');
            }

            $name = substr($archiveKey, \strlen($prefix));
            if (!$entry instanceof \SplFileInfo
                || !ReleaseFile::isSafeRelativePath($name)
                || $entry->isLink()
                || !$entry->isFile()
            ) {
                throw new \RuntimeException('The release tar archive contains an invalid entry.');
            }

            if (isset($entries[$name])) {
                throw new \RuntimeException('The release tar archive contains a duplicate entry: ' . $name);
            }

            $entries[$name] = true;
        }

        return $entries;
    }

    /** @param array<string, int|true> $entries */
    private function assertExpectedEntries(array $entries, ReleaseManifest $manifest): void
    {
        $expected = [ReleaseManifest::ARCHIVE_PATH => true];
        foreach ($manifest->files as $file) {
            $expected[$file->archivePath()] = true;
        }

        $unexpected = array_diff_key($entries, $expected);
        if ($unexpected !== []) {
            throw new \RuntimeException(
                'The release archive contains a file not listed in its manifest: ' . array_key_first($unexpected),
            );
        }

        $missing = array_diff_key($expected, $entries);
        if ($missing !== []) {
            throw new \RuntimeException(
                'The release archive is missing a manifest file: ' . array_key_first($missing),
            );
        }
    }

    private function assertArchiveCoverage(
        string          $archivePath,
        string          $format,
        ReleaseManifest $manifest,
    ): void {
        if ($format === ArchiveCapabilities::FORMAT_ZIP) {
            $zip = new \ZipArchive();
            if ($zip->open($archivePath, \ZipArchive::RDONLY) !== true) {
                throw new \RuntimeException('Unable to open the release ZIP.');
            }

            try {
                $this->assertExpectedEntries($this->validateZipEntries($zip), $manifest);
            } finally {
                $zip->close();
            }

            return;
        }

        try {
            $phar = new \PharData($archivePath);
            $this->assertExpectedEntries($this->validatePharEntries($phar), $manifest);
        } catch (\UnexpectedValueException $exception) {
            throw new \RuntimeException('Unable to open the release tar archive.', 0, $exception);
        }
    }

    private function assertZipRegularFile(\ZipArchive $zip, int $index, string $name): void
    {
        $operatingSystem = 0;
        $attributes      = 0;
        if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes, \ZipArchive::FL_UNCHANGED)) {
            $type = ($attributes >> 16) & 0170000;
            if ($type !== 0 && $type !== 0100000) {
                throw new \RuntimeException('A release ZIP entry is not a regular file: ' . $name);
            }
        }
    }

    private function copyVerified(mixed $input, string $destination, ReleaseFile $file): void
    {
        if (!\is_resource($input)) {
            throw new \LogicException('A release archive entry must be an open stream.');
        }

        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create a release staging directory.');
        }

        $output = fopen($destination, 'xb');
        if ($output === false) {
            throw new \RuntimeException('Unable to create a staged release file: ' . $file->key());
        }

        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false || ($chunk === '' && !feof($input))) {
                    throw new \RuntimeException('Unable to read a release archive entry: ' . $file->key());
                }

                if ($chunk === '') {
                    continue;
                }

                $size += \strlen($chunk);
                if ($size > $file->size
                    || fwrite($output, $chunk) !== \strlen($chunk)
                ) {
                    throw new \RuntimeException('Unable to stage a release archive entry: ' . $file->key());
                }

                hash_update($hash, $chunk);
            }

            if (!fflush($output)) {
                throw new \RuntimeException('Unable to flush a staged release file.');
            }
        } finally {
            fclose($output);
        }

        if ($size !== $file->size || !hash_equals($file->sha256, hash_final($hash))) {
            unlink($destination);
            throw new \RuntimeException('A staged release file failed its SHA-256 check: ' . $file->key());
        }

        if (DIRECTORY_SEPARATOR !== '\\' && !chmod($destination, $file->mode)) {
            throw new \RuntimeException('Unable to set staged release file permissions.');
        }
    }

    private function stagePath(string $stageRoot, ReleaseFile $file): string
    {
        return $stageRoot . '/' . $file->target . '/' . $file->path;
    }

    private function validateMagic(string $archivePath, string $format): void
    {
        if (!is_file($archivePath) || is_link($archivePath)) {
            throw new \RuntimeException('The uploaded release archive is missing or unsafe.');
        }

        $handle = fopen($archivePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to read the uploaded release archive.');
        }

        $magic = fread($handle, 4);
        fclose($handle);
        $valid = match ($format) {
            ArchiveCapabilities::FORMAT_ZIP       => \is_string($magic) && str_starts_with($magic, "PK\x03\x04"),
            ArchiveCapabilities::FORMAT_TAR_GZIP  => \is_string($magic) && str_starts_with($magic, "\x1f\x8b"),
            ArchiveCapabilities::FORMAT_TAR_BZIP2 => \is_string($magic) && str_starts_with($magic, 'BZh'),
            default => false,
        };
        if (!$valid) {
            throw new \RuntimeException('The uploaded file does not match its release archive extension.');
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

            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($directory);
    }
}
