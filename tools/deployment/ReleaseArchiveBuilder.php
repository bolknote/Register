<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Tools\Deployment;

use Register\Update\ReleaseFile;
use Register\Update\ReleaseManifest;

final readonly class ReleaseArchiveBuilder
{
    public function createZip(string $distributionRoot, string $archivePath, int $modifiedAt): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The Zip extension is required to build a compressed release ZIP.');
        }

        $this->assertNewOutput($archivePath);

        $zip = new \ZipArchive();
        $result = $zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::EXCL);
        if ($result !== true) {
            throw new \RuntimeException('Unable to create the release ZIP.');
        }

        try {
            foreach ($this->regularFiles($distributionRoot) as $relativePath => $filename) {
                if (!$zip->addFile($filename, $relativePath)
                    || !$zip->setCompressionName($relativePath, \ZipArchive::CM_DEFLATE, 9)
                    || !$zip->setMtimeName($relativePath, $modifiedAt)
                ) {
                    throw new \RuntimeException('Unable to add a file to the release ZIP: ' . $relativePath);
                }
            }

            if (!$zip->close()) {
                throw new \RuntimeException('Unable to finish the release ZIP.');
            }

            $this->secureOutput($archivePath);
        } catch (\Throwable $throwable) {
            $zip->unchangeAll();
            $zip->close();
            if (is_file($archivePath)) {
                unlink($archivePath);
            }

            throw $throwable;
        }
    }

    public function createTarGzip(string $distributionRoot, string $archivePath, int $modifiedAt): void
    {
        if (!\function_exists('gzopen')) {
            throw new \RuntimeException('The Zlib extension is required to build a tar.gz release.');
        }

        $this->createCompressedTar(
            $distributionRoot,
            $archivePath,
            $modifiedAt,
            static fn(string $filename): mixed => gzopen($filename, 'wb9'),
            static fn(mixed $stream, string $bytes): bool => gzwrite($stream, $bytes) === \strlen($bytes),
            static fn(mixed $stream): bool => gzclose($stream),
        );
    }

    public function createTarBzip2(string $distributionRoot, string $archivePath, int $modifiedAt): void
    {
        if (!\function_exists('bzopen')) {
            throw new \RuntimeException('The Bzip2 extension is required to build a tar.bz2 release.');
        }

        $this->createCompressedTar(
            $distributionRoot,
            $archivePath,
            $modifiedAt,
            static fn(string $filename): mixed => bzopen($filename, 'w'),
            static fn(mixed $stream, string $bytes): bool => bzwrite($stream, $bytes) === \strlen($bytes),
            static fn(mixed $stream): bool => bzclose($stream),
        );
    }

    /**
     * @param callable(string): mixed        $open
     * @param callable(mixed, string): bool $write
     * @param callable(mixed): bool         $close
     */
    private function createCompressedTar(
        string   $distributionRoot,
        string   $archivePath,
        int      $modifiedAt,
        callable $open,
        callable $write,
        callable $close,
    ): void {
        $this->assertNewOutput($archivePath);
        $stream = $open($archivePath);
        if (!\is_resource($stream)) {
            throw new \RuntimeException('Unable to create a compressed release archive.');
        }

        try {
            foreach ($this->regularFiles($distributionRoot) as $relativePath => $filename) {
                $permissions = fileperms($filename);
                $size        = filesize($filename);
                if (!\is_int($permissions) || !\is_int($size)) {
                    throw new \RuntimeException('Unable to inspect a release file: ' . $relativePath);
                }

                $header = $this->tarHeader(
                    $relativePath,
                    ($permissions & 0111) !== 0 ? 0755 : 0644,
                    $size,
                    $modifiedAt,
                );
                if (!$write($stream, $header)) {
                    throw new \RuntimeException('Unable to write a release tar header.');
                }

                $input = fopen($filename, 'rb');
                if ($input === false) {
                    throw new \RuntimeException('Unable to read a release file: ' . $relativePath);
                }

                try {
                    while (!feof($input)) {
                        $chunk = fread($input, 1024 * 1024);
                        if ($chunk === false || ($chunk === '' && !feof($input))) {
                            throw new \RuntimeException('Unable to read a release file: ' . $relativePath);
                        }

                        if ($chunk !== '' && !$write($stream, $chunk)) {
                            throw new \RuntimeException('Unable to write a compressed release archive.');
                        }
                    }
                } finally {
                    fclose($input);
                }

                $padding = (512 - ($size % 512)) % 512;
                if ($padding > 0 && !$write($stream, str_repeat("\0", $padding))) {
                    throw new \RuntimeException('Unable to pad a release tar entry.');
                }
            }

            if (!$write($stream, str_repeat("\0", 1024))) {
                throw new \RuntimeException('Unable to finish a compressed release archive.');
            }
        } catch (\Throwable $throwable) {
            $close($stream);

            if (is_file($archivePath)) {
                unlink($archivePath);
            }

            throw $throwable;
        }

        if (!$close($stream)) {
            if (is_file($archivePath)) {
                unlink($archivePath);
            }

            throw new \RuntimeException('Unable to finish a compressed release archive.');
        }

        try {
            $this->secureOutput($archivePath);
        } catch (\Throwable $throwable) {
            if (is_file($archivePath)) {
                unlink($archivePath);
            }

            throw $throwable;
        }
    }

    private function tarHeader(string $path, int $mode, int $size, int $modifiedAt): string
    {
        [$name, $prefix] = $this->splitTarPath($path);
        $header = str_pad($name, 100, "\0")
            . $this->octal($mode, 8)
            . $this->octal(0, 8)
            . $this->octal(0, 8)
            . $this->octal($size, 12)
            . $this->octal($modifiedAt, 12)
            . str_repeat(' ', 8)
            . '0'
            . str_repeat("\0", 100)
            . "ustar\0"
            . '00'
            . str_repeat("\0", 32)
            . str_repeat("\0", 32)
            . $this->octal(0, 8)
            . $this->octal(0, 8)
            . str_pad($prefix, 155, "\0")
            . str_repeat("\0", 12);
        if (\strlen($header) !== 512) {
            throw new \LogicException('The generated tar header has an invalid length.');
        }

        $headerBytes = unpack('C*', $header);
        if (!\is_array($headerBytes)) {
            throw new \RuntimeException('Unable to calculate a release tar header checksum.');
        }

        $checksum = array_sum($headerBytes);

        return substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
    }

    /** @return array{string, string} */
    private function splitTarPath(string $path): array
    {
        if (\strlen($path) <= 100) {
            return [$path, ''];
        }

        $offset = \strlen($path);
        while (($offset = strrpos(substr($path, 0, $offset), '/')) !== false) {
            $prefix = substr($path, 0, $offset);
            $name   = substr($path, $offset + 1);
            if (\strlen($prefix) <= 155 && \strlen($name) <= 100) {
                return [$name, $prefix];
            }
        }

        throw new \RuntimeException('A release path cannot be represented in a portable tar archive: ' . $path);
    }

    private function octal(int $value, int $length): string
    {
        $octal = decoct($value);
        if (\strlen($octal) > $length - 1) {
            throw new \RuntimeException('A release value cannot be represented in a portable tar archive.');
        }

        return str_pad($octal, $length - 1, '0', STR_PAD_LEFT) . "\0";
    }

    /** @return array<string, string> */
    private function regularFiles(string $root): array
    {
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false || !is_dir($resolvedRoot) || is_link($root)) {
            throw new \InvalidArgumentException('The release distribution root is invalid.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolvedRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        $files = [];
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            if ($entry->isLink()) {
                throw new \RuntimeException('Symbolic links are not allowed in a release archive.');
            }

            if (!$entry->isFile()) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($entry->getPathname(), \strlen($resolvedRoot) + 1));
            if (!ReleaseFile::isSafeRelativePath($relativePath)) {
                throw new \RuntimeException('A release archive contains an invalid path: ' . $relativePath);
            }

            $files[$relativePath] = $entry->getPathname();
        }

        ksort($files, SORT_STRING);

        $manifestPath = $files[ReleaseManifest::ARCHIVE_PATH] ?? null;
        if (!\is_string($manifestPath)) {
            throw new \RuntimeException('The release distribution has no manifest.');
        }

        $manifest     = ReleaseManifest::fromFile($manifestPath);
        $releaseFiles = [ReleaseManifest::ARCHIVE_PATH => $manifestPath];
        foreach ($manifest->files as $file) {
            $archivePath = $file->archivePath();
            $filename    = $files[$archivePath] ?? null;
            if (!\is_string($filename)) {
                throw new \RuntimeException('A manifest file is missing from the release distribution: ' . $archivePath);
            }

            $size        = filesize($filename);
            $hash        = hash_file('sha256', $filename);
            $permissions = fileperms($filename);
            $mode        = \is_int($permissions) && ($permissions & 0111) !== 0 ? 0755 : 0644;
            if (!\is_int($size)
                || !\is_string($hash)
                || $size !== $file->size
                || !hash_equals($file->sha256, $hash)
                || $mode !== $file->mode
            ) {
                throw new \RuntimeException('A release distribution file differs from its manifest: ' . $archivePath);
            }

            $releaseFiles[$archivePath] = $filename;
        }

        ksort($releaseFiles, SORT_STRING);

        return $releaseFiles;
    }

    private function assertNewOutput(string $archivePath): void
    {
        if (file_exists($archivePath) || is_link($archivePath)) {
            throw new \RuntimeException('Refusing to overwrite a release archive: ' . $archivePath);
        }
    }

    private function secureOutput(string $archivePath): void
    {
        if (!is_file($archivePath) || !chmod($archivePath, 0644)) {
            throw new \RuntimeException('Unable to secure a release archive: ' . $archivePath);
        }
    }
}
