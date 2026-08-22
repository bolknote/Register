<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update;

final readonly class ArchiveCapabilities
{
    public const string FORMAT_ZIP = 'zip';

    public const string FORMAT_TAR_GZIP = 'tar.gz';

    public const string FORMAT_TAR_BZIP2 = 'tar.bz2';

    /** @return array<string, array{extension: string, available: bool, requirement: string}> */
    public function formats(): array
    {
        return [
            self::FORMAT_ZIP => [
                'extension'   => '.zip',
                'available'   => class_exists(\ZipArchive::class),
                'requirement' => 'PHP ZipArchive',
            ],
            self::FORMAT_TAR_GZIP => [
                'extension'   => '.tar.gz',
                'available'   => class_exists(\PharData::class) && \extension_loaded('zlib'),
                'requirement' => 'PHP Phar and Zlib',
            ],
            self::FORMAT_TAR_BZIP2 => [
                'extension'   => '.tar.bz2',
                'available'   => class_exists(\PharData::class) && \extension_loaded('bz2'),
                'requirement' => 'PHP Phar and Bzip2',
            ],
        ];
    }

    public function preferredFormat(): ?string
    {
        foreach ([self::FORMAT_TAR_GZIP, self::FORMAT_ZIP, self::FORMAT_TAR_BZIP2] as $format) {
            if ($this->formats()[$format]['available']) {
                return $format;
            }
        }

        return null;
    }

    public function detect(string $filename): string
    {
        $lower = strtolower($filename);
        $format = match (true) {
            str_ends_with($lower, '.tar.gz')  => self::FORMAT_TAR_GZIP,
            str_ends_with($lower, '.tar.bz2') => self::FORMAT_TAR_BZIP2,
            str_ends_with($lower, '.zip')     => self::FORMAT_ZIP,
            default => throw new \InvalidArgumentException('Only .zip, .tar.gz, and .tar.bz2 releases are supported.'),
        };
        if (!$this->formats()[$format]['available']) {
            throw new \RuntimeException('This server cannot unpack ' . $format . ' releases.');
        }

        return $format;
    }
}
