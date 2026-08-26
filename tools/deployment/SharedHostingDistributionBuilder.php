<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Tools\Deployment;

use Register\Backup\PortableZipWriter;

final readonly class SharedHostingDistributionBuilder
{
    public const string PUBLIC_DIRECTORY = 'public_html';

    private const array SOURCE_DIRECTORIES = [
        '_admin',
        '_assets',
        '_extensions',
        '_include',
        '_lang',
        '_styles',
    ];

    private const array OPTIONAL_SOURCE_DIRECTORIES = [
        'files',
    ];

    private const array SOURCE_FILES = [
        'composer.json',
        'composer.lock',
        'index.php',
        'LICENSE.md',
    ];

    private const array TOOL_FILES = [
        'check-activitypub-interoperability.php',
        'decrypt-backup.php',
        'generate-backup-keypair.php',
        'precompress-assets.php',
        'restore-activitypub-identity.php',
    ];

    private const array DISTRIBUTION_DOCUMENTATION_FILES = [
        'activitypub-interoperability.md',
        'activitypub-operations.md',
        'activitypub-protocol-profile.md',
        'backups.md',
        'image-optimization.md',
        'secret-rotation.md',
        'self-update.md',
        'shared-hosting.md',
    ];

    private const array ROOT_ENTRIES = [
        '.htaccess'             => true,
        '_admin'                => true,
        '_assets'               => true,
        '_cache'                => true,
        '_extensions'           => true,
        '_include'              => true,
        '_lang'                 => true,
        '_pictures'             => true,
        '_styles'               => true,
        '_vendor'               => true,
        'composer.json'         => true,
        'composer.lock'         => true,
        'favicon.ico'           => true,
        'files'                 => true,
        'index.php'             => true,
        'LICENSE.md'            => true,
        'register-build.json'   => true,
        'register-release.json' => true,
        'robots.txt'            => true,
        'service-worker.js'     => true,
        'site.webmanifest'      => true,
        'tools'                 => true,
    ];

    private const array ENTRYPOINTS = [
        'index.php',
        '_admin/ajax.php',
        '_admin/index.php',
        '_admin/install.php',
        '_admin/pictman.php',
    ];

    private const array DATA_DIRECTORIES = [
        '_assets'   => true,
        '_cache'    => true,
        '_pictures' => true,
        'files'     => true,
    ];

    private const array ACTIVE_EXTENSIONS = [
        'asp'   => true,
        'aspx'  => true,
        'cgi'   => true,
        'inc'   => true,
        'jsp'   => true,
        'phar'  => true,
        'php'   => true,
        'php2'  => true,
        'php3'  => true,
        'php4'  => true,
        'php5'  => true,
        'php6'  => true,
        'php7'  => true,
        'php8'  => true,
        'pht'   => true,
        'phtml' => true,
        'pl'    => true,
        'py'    => true,
        'sh'    => true,
        'shtml' => true,
    ];

    private string $sourceRoot;

    public function __construct(string $sourceRoot)
    {
        $resolvedRoot = realpath($sourceRoot);
        if ($resolvedRoot === false || !is_file($resolvedRoot . '/composer.lock')) {
            throw new \InvalidArgumentException('The Register source root is invalid: ' . $sourceRoot);
        }

        $this->sourceRoot = $resolvedRoot;
    }

    public function buildDirectory(string $destinationRoot, bool $includeInstalledVendor = true): void
    {
        if (file_exists($destinationRoot) || is_link($destinationRoot)) {
            throw new \RuntimeException('The distribution destination already exists: ' . $destinationRoot);
        }

        $this->createDirectory($destinationRoot, 0750);
        try {
            $publicRoot = $destinationRoot . '/' . self::PUBLIC_DIRECTORY;
            $this->createDirectory($publicRoot, 0755);
            $this->copySources($publicRoot);
            $this->createRuntimeDirectories($publicRoot);
            if ($includeInstalledVendor && is_dir($this->sourceRoot . '/_vendor')) {
                $this->copyTree($this->sourceRoot . '/_vendor', $publicRoot . '/_vendor', 0755, 0644);
            }

            $this->copyExactFile($this->sourceRoot . '/.htaccess', $publicRoot . '/.htaccess', 0644);
            $this->copyExactFile($this->sourceRoot . '/service-worker.js', $publicRoot . '/service-worker.js', 0644);
            $this->copyOptionalRootMetadata($publicRoot);
            $this->copyDocumentation($destinationRoot);
            $this->validatePublicBoundary($destinationRoot);
        } catch (\Throwable $throwable) {
            $this->removeTree($destinationRoot);
            throw $throwable;
        }
    }

    public function createArchive(string $distributionRoot, string $archivePath): void
    {
        $resolvedRoot = realpath($distributionRoot);
        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            throw new \InvalidArgumentException('The distribution root does not exist: ' . $distributionRoot);
        }

        if (file_exists($archivePath) || is_link($archivePath)) {
            throw new \RuntimeException('The distribution archive already exists: ' . $archivePath);
        }

        $files = $this->regularFiles($resolvedRoot);
        $writer = new PortableZipWriter($archivePath);
        try {
            foreach ($files as $relativePath => $sourcePath) {
                $modifiedAt = filemtime($sourcePath);
                $writer->addFile($relativePath, $sourcePath, $modifiedAt === false ? time() : $modifiedAt);
            }

            $writer->close();
            $this->setMode($archivePath, 0644);
        } catch (\Throwable $throwable) {
            $writer->abort();
            if (is_file($archivePath)) {
                unlink($archivePath);
            }

            throw $throwable;
        }
    }

    public function validatePublicBoundary(string $distributionRoot): void
    {
        $publicRoot = $distributionRoot . '/' . self::PUBLIC_DIRECTORY;
        if (!is_dir($publicRoot) || is_link($publicRoot)) {
            throw new \RuntimeException('The distribution has no safe public document root.');
        }

        $rootEntries = scandir($publicRoot);
        if ($rootEntries === false) {
            throw new \RuntimeException('Unable to inspect the public document root.');
        }

        foreach ($rootEntries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!isset(self::ROOT_ENTRIES[$entry])) {
                throw new \RuntimeException('Unexpected public document-root entry: ' . $entry);
            }
        }

        $files = $this->regularFiles($publicRoot);
        foreach (self::ENTRYPOINTS as $entrypoint) {
            $source = $this->sourceRoot . '/' . $entrypoint;
            $built  = $files[$entrypoint] ?? null;
            if (!\is_string($built) || !$this->filesMatch($source, $built)) {
                throw new \RuntimeException('A required application entrypoint is missing or changed: ' . $entrypoint);
            }
        }

        $policy = $files['.htaccess'] ?? null;
        if (!\is_string($policy) || !$this->filesMatch($this->sourceRoot . '/.htaccess', $policy)) {
            throw new \RuntimeException('The shared-hosting Apache policy is missing or changed.');
        }

        foreach (array_keys($files) as $relativePath) {
            $rootDirectory = strtok($relativePath, '/');
            $extension     = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
            if (\is_string($rootDirectory)
                && isset(self::DATA_DIRECTORIES[$rootDirectory], self::ACTIVE_EXTENSIONS[$extension])
            ) {
                throw new \RuntimeException('Unexpected active file in a public data directory: ' . $relativePath);
            }
        }
    }

    private function copySources(string $publicRoot): void
    {
        foreach (self::SOURCE_DIRECTORIES as $directory) {
            $this->copyTree($this->sourceRoot . '/' . $directory, $publicRoot . '/' . $directory, 0755, 0644);
        }

        foreach (self::OPTIONAL_SOURCE_DIRECTORIES as $directory) {
            if (is_dir($this->sourceRoot . '/' . $directory)) {
                $this->copyTree($this->sourceRoot . '/' . $directory, $publicRoot . '/' . $directory, 0755, 0644);
            }
        }

        foreach (self::SOURCE_FILES as $filename) {
            $this->copyExactFile($this->sourceRoot . '/' . $filename, $publicRoot . '/' . $filename, 0644);
        }

        foreach (self::TOOL_FILES as $filename) {
            $this->copyExactFile($this->sourceRoot . '/tools/' . $filename, $publicRoot . '/tools/' . $filename, 0755);
        }
    }

    private function createRuntimeDirectories(string $publicRoot): void
    {
        foreach (['_cache', '_pictures'] as $directory) {
            $runtimeDirectory = $publicRoot . '/' . $directory;
            $this->createDirectory($runtimeDirectory, 0755);
            foreach (['.htaccess', 'index.html'] as $filename) {
                $this->copyExactFile(
                    $this->sourceRoot . '/' . $directory . '/' . $filename,
                    $runtimeDirectory . '/' . $filename,
                    0644,
                );
            }
        }
    }

    private function copyDocumentation(string $destinationRoot): void
    {
        $this->copyExactFile(
            $this->sourceRoot . '/_doc/shared-hosting.md',
            $destinationRoot . '/DEPLOYMENT.md',
            0644,
        );
        foreach (self::DISTRIBUTION_DOCUMENTATION_FILES as $filename) {
            $this->copyExactFile(
                $this->sourceRoot . '/_doc/' . $filename,
                $destinationRoot . '/' . $filename,
                0644,
            );
        }

        $this->copyExactFile(
            $this->sourceRoot . '/_doc/self-update.md',
            $destinationRoot . '/UPDATES.md',
            0644,
        );
    }

    private function copyOptionalRootMetadata(string $publicRoot): void
    {
        foreach (['favicon.ico', 'robots.txt', 'site.webmanifest'] as $filename) {
            $source = $this->sourceRoot . '/' . $filename;
            if (is_file($source) && !is_link($source)) {
                $this->copyExactFile($source, $publicRoot . '/' . $filename, 0644);
            }
        }
    }

    private function filesMatch(string $left, string $right): bool
    {
        $leftHash  = hash_file('sha256', $left);
        $rightHash = hash_file('sha256', $right);

        return \is_string($leftHash) && \is_string($rightHash) && hash_equals($leftHash, $rightHash);
    }

    private function copyTree(string $source, string $destination, int $directoryMode, int $fileMode): void
    {
        if (!is_dir($source) || is_link($source)) {
            throw new \RuntimeException('A required source directory is missing or unsafe: ' . $source);
        }

        $this->createDirectory($destination, $directoryMode);
        $directoryIterator = new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS);
        $filterIterator = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            static fn(mixed $entry): bool => !$entry instanceof \SplFileInfo
                || !str_starts_with($entry->getFilename(), '.'),
        );
        $iterator = new \RecursiveIteratorIterator($filterIterator, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            $sourcePath   = $entry->getPathname();
            $relativePath = substr($sourcePath, \strlen($source) + 1);
            $targetPath   = $destination . '/' . $relativePath;
            if ($entry->isLink()) {
                throw new \RuntimeException('Symbolic links are not allowed in the distribution: ' . $sourcePath);
            }

            if ($entry->isDir()) {
                $this->createDirectory($targetPath, $directoryMode);
            } elseif ($entry->isFile()) {
                $this->copyExactFile($sourcePath, $targetPath, $fileMode);
            }
        }
    }

    private function copyExactFile(string $source, string $destination, int $mode): void
    {
        if (!is_file($source) || is_link($source)) {
            throw new \RuntimeException('A required source file is missing or unsafe: ' . $source);
        }

        $this->createDirectory(\dirname($destination), 0755);
        if (!copy($source, $destination)) {
            throw new \RuntimeException('Unable to copy distribution file: ' . $source);
        }

        $this->setMode($destination, $mode);
    }

    private function createDirectory(string $directory, int $mode): void
    {
        if (!is_dir($directory) && !mkdir($directory, $mode, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create distribution directory: ' . $directory);
        }

        $this->setMode($directory, $mode);
    }

    private function setMode(string $path, int $mode): void
    {
        if (!chmod($path, $mode)) {
            throw new \RuntimeException('Unable to set distribution permissions: ' . $path);
        }
    }

    /** @return array<string, string> */
    private function regularFiles(string $root): array
    {
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
                throw new \RuntimeException('Symbolic links are not allowed in the archive: ' . $entry->getPathname());
            }

            if (!$entry->isFile()) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($entry->getPathname(), \strlen($root) + 1));
            $files[$relativePath] = $entry->getPathname();
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
