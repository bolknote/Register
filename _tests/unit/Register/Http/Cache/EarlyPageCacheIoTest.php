<?php

declare(strict_types = 1);

namespace unit\Register\Http\Cache;

use PHPUnit\Framework\TestCase;

final class EarlyPageCacheIoTest extends TestCase
{
    public function testPublishingAndCollectingDoNotReadTheBodiesOfUnrelatedCachedPages(): void
    {
        // Isolated function instrumentation measures bytes requested from the filesystem,
        // independently of the OS page cache and of the implementation's metadata format.
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/early-cache-io.php', dirname(__DIR__, 5)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string)$error);
        self::assertIsString($output);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($result['stored']);
        self::assertTrue($result['body_matches']);
        self::assertSame(0, $result['collect_removed']);
        self::assertLessThanOrEqual(32_768, $result['full_bucket_store']['snapshot_bytes'],
            'Storing one page must not reread the HTML of the other pages in its bucket.');
        self::assertLessThanOrEqual(32_768, $result['collect']['snapshot_bytes'],
            'Collecting a healthy bucket must not reread its cached HTML.');
    }
}
