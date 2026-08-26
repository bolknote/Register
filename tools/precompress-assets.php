#!/usr/bin/env php
<?php
/**
 * Prepares encoded variants of generated CSS/JavaScript bundles outside HTTP requests.
 *
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

use Register\Http\CompressionCodecRegistry;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Asset precompression can only be run from the command line.');
}

$projectRoot = dirname(__DIR__);
require $projectRoot . '/_vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
if (\count($arguments) > 2) {
    fwrite(STDERR, "Usage: php tools/precompress-assets.php [public-cache-directory]\n");
    exit(64);
}

$cacheDirectory = $arguments[1] ?? $projectRoot . '/_cache';
$resolvedCacheDirectory = realpath($cacheDirectory);
if ($resolvedCacheDirectory === false || !is_dir($resolvedCacheDirectory)) {
    fwrite(STDERR, "The public cache directory does not exist: {$cacheDirectory}\n");
    exit(66);
}

$registry = CompressionCodecRegistry::fromEnvironment();
$encoders = [];
foreach ($registry->encodings() as $encoding) {
    $compressor = $registry->compressor($encoding);
    if ($compressor instanceof Closure) {
        $encoders[$encoding] = static fn(string $_filename, string $content): string|false => $compressor($content);
    }
}

$commands = [
    CompressionCodecRegistry::BROTLI => ['brotli', '--quality=3', '--stdout'],
    CompressionCodecRegistry::ZSTD   => ['zstd', '--quiet', '-3', '--stdout'],
    CompressionCodecRegistry::GZIP   => ['gzip', '-6', '--stdout'],
];
foreach ($commands as $encoding => $command) {
    if (isset($encoders[$encoding])) {
        continue;
    }

    $executable = findCompressionExecutable($command[0]);
    if ($executable === null) {
        continue;
    }

    $command[0] = $executable;
    $encoders[$encoding] = static fn(string $filename, string $_content): string|false => runCompressionCommand(
        [...$command, $filename],
    );
}

$suffixes = [
    CompressionCodecRegistry::BROTLI => '.br',
    CompressionCodecRegistry::ZSTD   => '.zst',
    CompressionCodecRegistry::GZIP   => '.gz',
];
$generated = array_fill_keys(array_keys($suffixes), 0);
$files = new FilesystemIterator($resolvedCacheDirectory, FilesystemIterator::SKIP_DOTS);
foreach ($files as $file) {
    if (!$file instanceof SplFileInfo
        || !$file->isFile()
        || preg_match('/^[a-z0-9_-]+\.[0-9a-f]+\.(?:css|js)$/Di', $file->getFilename()) !== 1
    ) {
        continue;
    }

    $content = file_get_contents($file->getPathname());
    if (!\is_string($content)) {
        fwrite(STDERR, 'Unable to read generated asset: ' . $file->getPathname() . PHP_EOL);
        exit(74);
    }

    foreach ($suffixes as $encoding => $suffix) {
        $encoder = $encoders[$encoding] ?? null;
        if (!$encoder instanceof Closure) {
            continue;
        }

        $target = $file->getPathname() . $suffix;
        $targetModifiedAt = is_file($target) ? filemtime($target) : false;
        if (\is_int($targetModifiedAt) && $targetModifiedAt >= $file->getMTime()) {
            continue;
        }

        $compressed = $encoder($file->getPathname(), $content);
        if (!\is_string($compressed)) {
            fwrite(STDERR, \sprintf("Unable to create %s variant of %s\n", $encoding, $file->getFilename()));
            exit(74);
        }

        writeCompressedAsset($target, $compressed);
        ++$generated[$encoding];
    }
}

$available = array_values(array_intersect(array_keys($suffixes), array_keys($encoders)));
fwrite(STDOUT, 'Available encoders: ' . ($available === [] ? 'none' : implode(', ', $available)) . PHP_EOL);
foreach ($available as $encoding) {
    fwrite(STDOUT, \sprintf('%s variants created: %d%s', $encoding, $generated[$encoding], PHP_EOL));
}

function findCompressionExecutable(string $name): ?string
{
    $path = getenv('PATH');
    if (!\is_string($path)) {
        return null;
    }

    foreach (explode(PATH_SEPARATOR, $path) as $directory) {
        if ($directory === '') {
            continue;
        }

        $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/** @param list<string> $command */
function runCompressionCommand(array $command): string|false
{
    $process = proc_open($command, [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!\is_resource($process)) {
        return false;
    }

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 && \is_string($errorOutput) && trim($errorOutput) !== '') {
        fwrite(STDERR, trim($errorOutput) . PHP_EOL);
    }

    return $exitCode === 0 && \is_string($output) ? $output : false;
}

function writeCompressedAsset(string $target, string $content): void
{
    $temporary = tempnam(dirname($target), '.register-compressed-');
    if ($temporary === false) {
        throw new RuntimeException('Unable to create a temporary compressed asset.');
    }

    try {
        if (file_put_contents($temporary, $content, LOCK_EX) === false || !chmod($temporary, 0644)) {
            throw new RuntimeException('Unable to write compressed asset: ' . $target);
        }
        if (!rename($temporary, $target)) {
            throw new RuntimeException('Unable to publish compressed asset: ' . $target);
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}
