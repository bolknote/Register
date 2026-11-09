<?php

declare(strict_types = 1);

namespace unit\Register\Http\Cache;

use PHPUnit\Framework\TestCase;

final class PublicAuthCacheEntrypointTest extends TestCase
{
    public function testDifferentReturnPathsUseTheEarlyCacheWithoutChangingAuthentication(): void
    {
        foreach (['', '/nested/blog'] as $basePath) {
            $this->assertEntrypoint($basePath);
        }
    }

    private function assertEntrypoint(string $basePath): void
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 5) . '/tools/benchmark-auth-cache.php', '--requests=20', '--assert-fast', '--base-path=' . $basePath], [
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
        self::assertCount(9, $report['scenarios']);
        self::assertCount(15, $report['safety_checks']);
        foreach ($report['scenarios'] as $scenario) {
            self::assertSame(20, $scenario['early_cache']['hit']);
            self::assertSame(0, $scenario['engine_loaded']);
        }

        foreach ($report['safety_checks'] as $check => $passed) {
            self::assertTrue($passed, $check);
        }
    }
}
