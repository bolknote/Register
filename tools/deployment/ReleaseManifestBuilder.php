<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Tools\Deployment;

use Register\Update\ReleaseFile;
use Register\Update\ReleaseManifest;

final readonly class ReleaseManifestBuilder
{
    private const array EXCLUDED_PREFIXES = [
        'register-app/_cache/',
        'public_html/_cache/',
        'public_html/_pictures/',
    ];

    private const array MANAGED_RUNTIME_BOUNDARIES = [
        'register-app/_cache/.htaccess'    => true,
        'register-app/_cache/index.html'   => true,
        'public_html/_cache/.htaccess'     => true,
        'public_html/_cache/index.html'    => true,
        'public_html/_pictures/.htaccess'  => true,
        'public_html/_pictures/index.html' => true,
    ];

    public function build(
        string $distributionRoot,
        string $releaseId,
        string $version,
        string $baseVersion,
        string $channel,
        int    $buildNumber,
        string $builtAt,
        string $commit,
        string $minimumPhp,
        int    $schemaFrom,
        int    $schemaTo,
    ): ReleaseManifest {
        $resolvedRoot = realpath($distributionRoot);
        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            throw new \InvalidArgumentException('The release distribution root does not exist.');
        }

        $files = [];
        foreach ([
            ReleaseFile::TARGET_APPLICATION => SharedHostingDistributionBuilder::APPLICATION_DIRECTORY,
            ReleaseFile::TARGET_PUBLIC      => SharedHostingDistributionBuilder::PUBLIC_DIRECTORY,
        ] as $target => $directory) {
            $targetRoot = $resolvedRoot . '/' . $directory;
            foreach ($this->regularFiles($targetRoot) as $relativePath => $filename) {
                $archivePath = $directory . '/' . $relativePath;
                if ($archivePath === ReleaseManifest::ARCHIVE_PATH || $this->isExcluded($archivePath)) {
                    continue;
                }

                $size = filesize($filename);
                $hash = hash_file('sha256', $filename);
                $permissions = fileperms($filename);
                if (!\is_int($size) || !\is_string($hash) || !\is_int($permissions)) {
                    throw new \RuntimeException('Unable to inspect release file: ' . $archivePath);
                }

                $files[] = new ReleaseFile(
                    $target,
                    $relativePath,
                    $size,
                    $hash,
                    ($permissions & 0111) !== 0 ? 0755 : 0644,
                );
            }
        }

        usort($files, static fn(ReleaseFile $left, ReleaseFile $right): int => $left->key() <=> $right->key());

        return new ReleaseManifest(
            $releaseId,
            $version,
            $baseVersion,
            $channel,
            $buildNumber,
            $builtAt,
            $commit,
            $minimumPhp,
            $schemaFrom,
            $schemaTo,
            $files,
        );
    }

    private function isExcluded(string $archivePath): bool
    {
        if (isset(self::MANAGED_RUNTIME_BOUNDARIES[$archivePath])) {
            return false;
        }

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($archivePath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function regularFiles(string $root): array
    {
        if (!is_dir($root) || is_link($root)) {
            throw new \RuntimeException('A release target directory is missing or unsafe: ' . $root);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        $files = [];
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            if ($entry->isLink()) {
                throw new \RuntimeException('Symbolic links are not allowed in a release: ' . $entry->getPathname());
            }

            if (!$entry->isFile()) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($entry->getPathname(), \strlen($root) + 1));
            if (!ReleaseFile::isSafeRelativePath($relativePath)) {
                throw new \RuntimeException('A release contains an invalid path: ' . $relativePath);
            }

            $files[$relativePath] = $entry->getPathname();
        }

        ksort($files, SORT_STRING);

        return $files;
    }
}
