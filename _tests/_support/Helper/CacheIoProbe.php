<?php

declare(strict_types = 1);

namespace Helper;

/** Logical file I/O counters for isolated cache regression processes. */
final class CacheIoProbe
{
    public static int $reads = 0;

    public static int $readBytes = 0;

    public static int $writes = 0;

    public static int $writeBytes = 0;

    public static int $snapshotReads = 0;

    public static int $snapshotBytes = 0;

    public static function reset(): void
    {
        self::$reads = 0;
        self::$readBytes = 0;
        self::$writes = 0;
        self::$writeBytes = 0;
        self::$snapshotReads = 0;
        self::$snapshotBytes = 0;
    }

    /** @return array<string, int> */
    public static function result(): array
    {
        return [
            'reads' => self::$reads, 'read_bytes' => self::$readBytes,
            'writes' => self::$writes, 'write_bytes' => self::$writeBytes,
            'snapshot_reads' => self::$snapshotReads, 'snapshot_bytes' => self::$snapshotBytes,
        ];
    }
}
