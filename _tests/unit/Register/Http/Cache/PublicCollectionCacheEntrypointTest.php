<?php

declare(strict_types = 1);

namespace unit\Register\Http\Cache;

use PHPUnit\Framework\TestCase;

final class PublicCollectionCacheEntrypointTest extends TestCase
{
    public function testPublicCollectionsUseEarlyCacheWithoutLosingPaginationPrivacyOrFeedReaders(): void
    {
        foreach (['', '/nested/blog'] as $basePath) {
            $this->assertEntrypoint($basePath);
        }
    }

    private function assertEntrypoint(string $basePath): void
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 5) . '/tools/benchmark-auth-cache.php',
            '--public-pages', '--requests=10', '--assert-fast', '--base-path=' . $basePath], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($output);
        self::assertIsString($errors);
        self::assertSame(0, proc_close($process), $errors . $output);
        $report = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertCount(10, $report['scenarios']);
        foreach ($report['scenarios'] as $scenario) {
            self::assertSame(0, $scenario['engine_loaded']);
            self::assertSame(10, $scenario['early_cache']['hit']);
        }

        foreach ($report['safety_checks'] as $name => $passed) {
            self::assertTrue($passed, $name);
        }
    }
}
