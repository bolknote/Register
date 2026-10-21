<?php

declare(strict_types = 1);

namespace unit\Register\Http\Cache;

use PHPUnit\Framework\TestCase;

/** Exercises the real front controller, not just the plain-data cache class. */
final class EarlyPageCacheEntrypointTest extends TestCase
{
    public function testTheHttpEntrypointPreservesPrivacyVariantsViewsAndInvalidation(): void
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 5) . '/tools/benchmark-early-cache.php', '--requests=10'], [
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
        self::assertCount(11, $report['safety_checks']);
        foreach ($report['safety_checks'] as $name => $passed) {
            self::assertTrue($passed, $name);
        }

        foreach ($report['scenarios'] as $scenario) {
            self::assertSame(10, $scenario['early']['early_hits']);
            self::assertSame(0, $scenario['early']['engine_loaded']);
        }
    }
}
