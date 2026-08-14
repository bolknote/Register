<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup;

/** Writes portable, uncompressed ZIP archives without requiring ext-zip or ext-zlib. */
final class PortableZipWriter
{
    private const int MAX_UINT16 = 0xffff;

    private const int MAX_UINT32 = 0xffffffff;

    /** @var resource|null */
    private $stream;

    /**
     * @var list<array{
     *     name:string,
     *     crc:int,
     *     size:int,
     *     offset:int,
     *     time:int,
     *     date:int
     * }>
     */
    private array $entries = [];

    /** @var array<string, true> */
    private array $entryNames = [];

    private bool $finalized = false;

    public function __construct(private readonly string $path)
    {
        $stream = fopen($path, 'x+b');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create the backup archive.');
        }

        $this->stream = $stream;
    }

    public function __destruct()
    {
        $this->abort();
    }

    public function addFile(string $entryName, string $sourcePath, int $timestamp): int
    {
        if (!is_file($sourcePath) || is_link($sourcePath)) {
            throw new \RuntimeException('A backup entry source is not a regular file: ' . $sourcePath);
        }

        $source = fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new \RuntimeException('Unable to read a backup entry: ' . $sourcePath);
        }

        try {
            return $this->addStream($entryName, $source, $timestamp);
        } finally {
            fclose($source);
        }
    }

    public function addString(string $entryName, string $content, int $timestamp): int
    {
        $source = fopen('php://temp', 'w+b');
        if ($source === false) {
            throw new \RuntimeException('Unable to allocate memory for a backup entry.');
        }

        try {
            $length = \strlen($content);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($source, substr($content, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('Unable to prepare a backup entry.');
                }
                $offset += $written;
            }

            if (!rewind($source)) {
                throw new \RuntimeException('Unable to prepare a backup entry.');
            }

            return $this->addStream($entryName, $source, $timestamp);
        } finally {
            fclose($source);
        }
    }

    public function close(): void
    {
        $stream = $this->writableStream();
        $centralOffset = $this->position();

        foreach ($this->entries as $entry) {
            $header = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                0x0314,
                20,
                0x0808,
                0,
                $entry['time'],
                $entry['date'],
                $entry['crc'],
                $entry['size'],
                $entry['size'],
                \strlen($entry['name']),
                0,
                0,
                0,
                0,
                0x81a40000,
                $entry['offset'],
            );
            $this->write($header . $entry['name']);
        }

        $centralSize = $this->position() - $centralOffset;
        self::assertUint32($centralOffset, 'ZIP central-directory offset');
        self::assertUint32($centralSize, 'ZIP central-directory size');
        $entryCount = \count($this->entries);
        if ($entryCount > self::MAX_UINT16) {
            throw new \RuntimeException('The backup has too many files for a portable ZIP archive.');
        }

        $this->write(pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $entryCount,
            $entryCount,
            $centralSize,
            $centralOffset,
            0,
        ));

        if (!fflush($stream)) {
            throw new \RuntimeException('Unable to flush the backup archive.');
        }
        fclose($stream);
        $this->stream    = null;
        $this->finalized = true;
    }

    public function abort(): void
    {
        if (\is_resource($this->stream)) {
            $stream = $this->stream;
            $this->stream = null;
            fclose($stream);
        }
        $this->stream = null;
    }

    /** @param resource $source */
    private function addStream(string $entryName, $source, int $timestamp): int
    {
        $this->writableStream();
        $entryName = $this->normalizeEntryName($entryName);
        if (isset($this->entryNames[$entryName])) {
            throw new \RuntimeException('Duplicate backup archive entry: ' . $entryName);
        }
        if (\count($this->entries) >= self::MAX_UINT16) {
            throw new \RuntimeException('The backup has too many files for a portable ZIP archive.');
        }

        [$dosTime, $dosDate] = self::dosDateTime($timestamp);
        $offset = $this->position();
        self::assertUint32($offset, 'ZIP file offset');

        $this->write(pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0x0808,
            0,
            $dosTime,
            $dosDate,
            0,
            0,
            0,
            \strlen($entryName),
            0,
        ) . $entryName);

        $hash = hash_init('crc32b');
        $size = 0;
        while (!feof($source)) {
            $chunk = fread($source, 1024 * 1024);
            if ($chunk === false || ($chunk === '' && !feof($source))) {
                throw new \RuntimeException('Unable to read data while building a backup archive.');
            }
            if ($chunk === '') {
                continue;
            }

            hash_update($hash, $chunk);
            $this->write($chunk);
            $size += \strlen($chunk);
            self::assertUint32($size, 'ZIP entry size');
        }

        $crc = (int)hexdec(hash_final($hash));
        $this->write(pack('VVVV', 0x08074b50, $crc, $size, $size));

        $this->entries[] = [
            'name'   => $entryName,
            'crc'    => $crc,
            'size'   => $size,
            'offset' => $offset,
            'time'   => $dosTime,
            'date'   => $dosDate,
        ];
        $this->entryNames[$entryName] = true;

        return $size;
    }

    private function normalizeEntryName(string $entryName): string
    {
        $entryName = str_replace('\\', '/', $entryName);
        if (
            $entryName === ''
            || str_starts_with($entryName, '/')
            || str_contains($entryName, "\0")
            || preg_match('#(?:^|/)\.\.?(?:/|$)#D', $entryName) === 1
            || \strlen($entryName) > self::MAX_UINT16
        ) {
            throw new \InvalidArgumentException('Unsafe ZIP entry name: ' . $entryName);
        }

        return $entryName;
    }

    /** @return array{0:int,1:int} */
    private static function dosDateTime(int $timestamp): array
    {
        $date = getdate(max(315532800, min(4354819199, $timestamp)));
        $year = max(1980, min(2107, $date['year']));

        return [
            ($date['hours'] << 11) | ($date['minutes'] << 5) | intdiv($date['seconds'], 2),
            ($year - 1980 << 9) | ($date['mon'] << 5) | $date['mday'],
        ];
    }

    /** @return resource */
    private function writableStream()
    {
        if ($this->finalized || !\is_resource($this->stream)) {
            throw new \LogicException('The backup archive is already closed.');
        }

        return $this->stream;
    }

    private function position(): int
    {
        $position = ftell($this->writableStream());
        if ($position === false) {
            throw new \RuntimeException('Unable to determine the backup archive position.');
        }

        return $position;
    }

    private function write(string $data): void
    {
        $stream = $this->writableStream();
        $length = \strlen($data);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Unable to write the backup archive.');
            }
            $offset += $written;
        }
    }

    private static function assertUint32(int $value, string $description): void
    {
        if ($value < 0 || $value > self::MAX_UINT32) {
            throw new \RuntimeException($description . ' exceeds the portable ZIP limit.');
        }
    }
}
