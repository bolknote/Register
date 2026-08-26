#!/usr/bin/env php
<?php
/**
 * Builds the three self-update release archives from one production distribution.
 *
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

use Register\Schema\SchemaManager;
use Register\Tools\Deployment\ProductionDependencyInstaller;
use Register\Tools\Deployment\ReleaseArchiveBuilder;
use Register\Tools\Deployment\ReleaseManifestBuilder;
use Register\Tools\Deployment\SharedHostingDistributionBuilder;
use Register\Update\BuildInfo;
use Register\Update\ReleaseManifest;
use Symfony\Component\Filesystem\Filesystem;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Release archives can only be built from the command line.');
}

$projectRoot = dirname(__DIR__);
require $projectRoot . '/_vendor/autoload.php';
foreach ([
    'SharedHostingDistributionBuilder.php',
    'ProductionDependencyInstaller.php',
    'ReleaseManifestBuilder.php',
    'ReleaseArchiveBuilder.php',
] as $deploymentFile) {
    require $projectRoot . '/tools/deployment/' . $deploymentFile;
}

$arguments = $_SERVER['argv'] ?? [];
if (\count($arguments) > 2) {
    fwrite(STDERR, "Usage: php tools/build-release.php [output-directory]\n");
    exit(64);
}

$outputDirectory = $arguments[1] ?? $projectRoot . '/dist/release';
if (!str_starts_with($outputDirectory, DIRECTORY_SEPARATOR)) {
    $workingDirectory = getcwd();
    if ($workingDirectory === false) {
        throw new RuntimeException('Unable to determine the current working directory.');
    }
    $outputDirectory = $workingDirectory . '/' . $outputDirectory;
}
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0755, true) && !is_dir($outputDirectory)) {
    throw new RuntimeException('Unable to create the release output directory.');
}

$commit       = releaseEnvironment('REGISTER_RELEASE_COMMIT', releaseCommit($projectRoot));
$buildNumber  = releasePositiveInt('REGISTER_RELEASE_BUILD', time());
$builtAt      = releaseEnvironment('REGISTER_RELEASE_BUILT_AT', gmdate('Y-m-d\TH:i:s+00:00'));
$sourceEpoch  = releasePositiveInt('SOURCE_DATE_EPOCH', time());
$timestamp    = gmdate('Ymd\THis\Z', $sourceEpoch);
$shortCommit  = substr($commit, 0, 8);
$releaseId    = releaseEnvironment('REGISTER_RELEASE_ID', $timestamp . '-' . $shortCommit);
$baseVersion  = releaseEnvironment('REGISTER_BASE_VERSION', '2.0.0');
$channel      = releaseEnvironment('REGISTER_RELEASE_CHANNEL', 'edge');
$version      = releaseEnvironment('REGISTER_RELEASE_VERSION', $baseVersion . '-' . $channel . '.' . gmdate('Ymd.His', $sourceEpoch));
$minimumPhp   = releaseEnvironment('REGISTER_MINIMUM_PHP', '8.3.0');
$archiveBase  = 'register-' . $releaseId;

$outputs = [
    $outputDirectory . '/' . $archiveBase . '.zip',
    $outputDirectory . '/' . $archiveBase . '.tar.gz',
    $outputDirectory . '/' . $archiveBase . '.tar.bz2',
    $outputDirectory . '/SHA256SUMS',
];
foreach ($outputs as $output) {
    if (file_exists($output) || is_link($output)) {
        throw new RuntimeException('Refusing to overwrite release output: ' . $output);
    }
}

$temporaryRoot = sys_get_temp_dir() . '/register_release_' . bin2hex(random_bytes(8));
$filesystem    = new Filesystem();

try {
    $distributionBuilder = new SharedHostingDistributionBuilder($projectRoot);
    $distributionBuilder->buildDirectory($temporaryRoot, includeInstalledVendor: false);
    (new ProductionDependencyInstaller())->install(
        $temporaryRoot . '/' . SharedHostingDistributionBuilder::PUBLIC_DIRECTORY,
    );
    $distributionBuilder->validatePublicBoundary($temporaryRoot);

    $buildInfo     = BuildInfo::toJson($releaseId, $version, $builtAt, $commit);
    $buildInfoPath = $temporaryRoot . '/' . SharedHostingDistributionBuilder::PUBLIC_DIRECTORY
        . '/' . BuildInfo::FILENAME;
    if (file_put_contents($buildInfoPath, $buildInfo, LOCK_EX) !== \strlen($buildInfo)
        || !chmod($buildInfoPath, 0644)
    ) {
        throw new RuntimeException('Unable to write the release build metadata.');
    }

    $manifest = (new ReleaseManifestBuilder())->build(
        $temporaryRoot,
        $releaseId,
        $version,
        $baseVersion,
        $channel,
        $buildNumber,
        $builtAt,
        $commit,
        $minimumPhp,
        SchemaManager::MINIMUM_UPGRADE_GENERATION,
        SchemaManager::CURRENT_GENERATION,
    );
    $manifestPath = $temporaryRoot . '/' . ReleaseManifest::ARCHIVE_PATH;
    if (file_put_contents($manifestPath, $manifest->toJson(), LOCK_EX) === false || !chmod($manifestPath, 0644)) {
        throw new RuntimeException('Unable to write the release manifest.');
    }
    $distributionBuilder->validatePublicBoundary($temporaryRoot);

    $archiveBuilder = new ReleaseArchiveBuilder();
    $archiveBuilder->createZip($temporaryRoot, $outputs[0], $sourceEpoch);
    $archiveBuilder->createTarGzip($temporaryRoot, $outputs[1], $sourceEpoch);
    $archiveBuilder->createTarBzip2($temporaryRoot, $outputs[2], $sourceEpoch);

    $checksumLines = [];
    foreach (array_slice($outputs, 0, 3) as $archive) {
        $hash = hash_file('sha256', $archive);
        if (!\is_string($hash)) {
            throw new RuntimeException('Unable to calculate a release archive checksum.');
        }
        $checksumLines[] = $hash . '  ' . basename($archive);
    }
    $checksumContent = implode("\n", $checksumLines) . "\n";
    if (file_put_contents($outputs[3], $checksumContent, LOCK_EX) !== \strlen($checksumContent)
        || !chmod($outputs[3], 0644)
    ) {
        throw new RuntimeException('Unable to write release archive checksums.');
    }
} catch (Throwable $throwable) {
    foreach ($outputs as $output) {
        if (is_file($output)) {
            unlink($output);
        }
    }
    fwrite(STDERR, 'Release build failed: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $filesystem->remove($temporaryRoot);
}

fwrite(STDOUT, $manifest->version . PHP_EOL);
foreach ($outputs as $output) {
    fwrite(STDOUT, $output . PHP_EOL);
}

function releaseEnvironment(string $name, string $fallback): string
{
    $value = getenv($name);

    return \is_string($value) && $value !== '' ? $value : $fallback;
}

function releasePositiveInt(string $name, int $fallback): int
{
    $value = getenv($name);
    if (!\is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
        return $fallback;
    }

    return (int)$value;
}

function releaseCommit(string $projectRoot): string
{
    $pipes   = [];
    $process = proc_open(['git', '-C', $projectRoot, 'rev-parse', 'HEAD'], [
        0 => ['file', 'php://stdin', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', 'php://stderr', 'w'],
    ], $pipes);
    if (!\is_resource($process)) {
        throw new RuntimeException('Unable to inspect the release commit.');
    }

    $commit = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || !\is_string($commit)) {
        throw new RuntimeException('Unable to inspect the release commit.');
    }

    return trim($commit);
}
