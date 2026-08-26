#!/usr/bin/env php
<?php
/**
 * Builds a ready-to-upload single-root shared-hosting package.
 *
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

use Register\Tools\Deployment\SharedHostingDistributionBuilder;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('The shared-hosting package can only be built from the command line.');
}

$projectRoot = dirname(__DIR__);
require $projectRoot . '/_vendor/autoload.php';
require $projectRoot . '/tools/deployment/SharedHostingDistributionBuilder.php';

$arguments = $_SERVER['argv'] ?? [];
if (\count($arguments) > 2) {
    fwrite(STDERR, "Usage: php tools/build-shared-hosting.php [output.zip]\n");
    exit(64);
}

$output = $arguments[1] ?? $projectRoot . '/dist/register-shared-hosting.zip';
if (!str_starts_with($output, DIRECTORY_SEPARATOR)) {
    $workingDirectory = getcwd();
    if ($workingDirectory === false) {
        throw new RuntimeException('Unable to determine the current working directory.');
    }
    $output = $workingDirectory . '/' . $output;
}

$outputDirectory = dirname($output);
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0755, true) && !is_dir($outputDirectory)) {
    throw new RuntimeException('Unable to create the output directory: ' . $outputDirectory);
}
if (file_exists($output) || is_link($output)) {
    fwrite(STDERR, "Refusing to overwrite the existing archive: {$output}\n");
    exit(73);
}

$temporaryRoot = sys_get_temp_dir() . '/register_shared_hosting_' . bin2hex(random_bytes(8));
$temporaryArchive = $output . '.' . bin2hex(random_bytes(6)) . '.tmp';
$builder = new SharedHostingDistributionBuilder($projectRoot);
$published = false;
$hash = null;

try {
    $builder->buildDirectory($temporaryRoot, includeInstalledVendor: is_dir($projectRoot . '/_vendor'));
    installProductionDependencies($temporaryRoot . '/' . SharedHostingDistributionBuilder::PUBLIC_DIRECTORY);
    $builder->validatePublicBoundary($temporaryRoot);
    $builder->createArchive($temporaryRoot, $temporaryArchive);

    $hash = hash_file('sha256', $temporaryArchive);
    if (!\is_string($hash)) {
        throw new RuntimeException('Unable to calculate the distribution checksum.');
    }
    if (!rename($temporaryArchive, $output)) {
        throw new RuntimeException('Unable to publish the completed distribution archive.');
    }
    $published = true;
    if (!chmod($output, 0644)) {
        throw new RuntimeException('Unable to set distribution archive permissions.');
    }
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Shared-hosting build failed: ' . $throwable->getMessage() . PHP_EOL);
    if ($published && is_file($output)) {
        unlink($output);
    }
    $hash = null;
} finally {
    removeTemporaryTree($temporaryRoot);
    if (is_file($temporaryArchive)) {
        unlink($temporaryArchive);
    }
}

if ($hash === null) {
    exit(1);
}
fwrite(STDOUT, $output . PHP_EOL . 'SHA-256: ' . $hash . PHP_EOL);

function installProductionDependencies(string $applicationRoot): void
{
    $composer = findComposerBinary();
    if ($composer === null) {
        throw new RuntimeException('Composer is required to prepare production dependencies.');
    }

    $command = [
        $composer,
        'install',
        '--working-dir=' . $applicationRoot,
        '--no-dev',
        '--prefer-dist',
        '--no-interaction',
        '--no-progress',
        '--no-plugins',
        '--no-scripts',
        '--optimize-autoloader',
    ];
    $pipes = [];
    $process = proc_open($command, [
        0 => ['file', 'php://stdin', 'r'],
        1 => ['file', 'php://stdout', 'w'],
        2 => ['file', 'php://stderr', 'w'],
    ], $pipes);
    if (!\is_resource($process)) {
        throw new RuntimeException('Unable to start Composer.');
    }

    $exitCode = proc_close($process);
    if ($exitCode !== 0 || !is_file($applicationRoot . '/_vendor/autoload.php')) {
        throw new RuntimeException('Composer failed to install the locked production dependencies.');
    }
}

function findComposerBinary(): ?string
{
    $path = getenv('PATH');
    if (!\is_string($path)) {
        return null;
    }

    foreach (explode(PATH_SEPARATOR, $path) as $directory) {
        $candidate = rtrim($directory, '/\\') . '/composer';
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function removeTemporaryTree(string $directory): void
{
    if (!is_dir($directory) || is_link($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo) {
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
