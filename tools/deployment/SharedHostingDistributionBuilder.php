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
    public const string APPLICATION_DIRECTORY = 'register-app';

    public const string PUBLIC_DIRECTORY = 'public_html';

    private const array APPLICATION_SOURCE_DIRECTORIES = [
        '_admin',
        '_assets',
        '_extensions',
        '_include',
        '_lang',
        '_styles',
    ];

    private const array APPLICATION_SOURCE_FILES = [
        'composer.json',
        'composer.lock',
        'index.php',
        'LICENSE.md',
    ];

    private const array APPLICATION_TOOL_FILES = [
        'check-activitypub-interoperability.php',
        'decrypt-backup.php',
        'generate-backup-keypair.php',
        'restore-activitypub-identity.php',
    ];

    private const array DISTRIBUTION_DOCUMENTATION_FILES = [
        'activitypub-interoperability.md',
        'activitypub-operations.md',
        'backups.md',
    ];

    private const array PUBLIC_SOURCE_DIRECTORIES = [
        '_admin',
        '_assets',
        '_extensions',
        '_styles',
    ];

    private const array PUBLIC_EXTENSIONS = [
        'avif'        => true,
        'br'          => true,
        'css'         => true,
        'eot'         => true,
        'flac'        => true,
        'gif'         => true,
        'gz'          => true,
        'htm'         => true,
        'html'        => true,
        'ico'         => true,
        'jpeg'        => true,
        'jpg'         => true,
        'js'          => true,
        'json'        => true,
        'm4a'         => true,
        'mjs'         => true,
        'mp3'         => true,
        'mp4'         => true,
        'ogg'         => true,
        'otf'         => true,
        'pdf'         => true,
        'png'         => true,
        'svg'         => true,
        'svgz'        => true,
        'ttf'         => true,
        'wasm'        => true,
        'wav'         => true,
        'webm'        => true,
        'webmanifest' => true,
        'webp'        => true,
        'woff'        => true,
        'woff2'       => true,
        'xml'         => true,
        'xsl'         => true,
        'xslt'        => true,
    ];

    private const array PUBLIC_ENTRYPOINTS = [
        'index.php'          => 'index.php',
        '_admin/ajax.php'    => '_admin/ajax.php',
        '_admin/index.php'   => '_admin/index.php',
        '_admin/install.php' => '_admin/install.php',
        '_admin/pictman.php' => '_admin/pictman.php',
    ];

    private const array PUBLIC_ROOT_ENTRIES = [
        '.htaccess'         => true,
        '_admin'            => true,
        '_assets'           => true,
        '_cache'            => true,
        '_extensions'       => true,
        '_pictures'         => true,
        '_styles'           => true,
        '_vendor'           => true,
        'favicon.ico'       => true,
        'index.php'         => true,
        'robots.txt'        => true,
        'service-worker.js' => true,
        'site.webmanifest'  => true,
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
            $applicationRoot = $destinationRoot . '/' . self::APPLICATION_DIRECTORY;
            $publicRoot      = $destinationRoot . '/' . self::PUBLIC_DIRECTORY;
            $this->createDirectory($applicationRoot, 0750);
            $this->createDirectory($publicRoot, 0755);

            $this->copyApplicationSources($applicationRoot);
            $this->createRuntimeDirectories($applicationRoot, $publicRoot);
            if ($includeInstalledVendor && is_dir($this->sourceRoot . '/_vendor')) {
                $this->copyTree($this->sourceRoot . '/_vendor', $applicationRoot . '/_vendor', 0755, 0644);
            }

            $this->copyPublicSources($publicRoot);
            $this->writeEntrypoints($publicRoot);
            $this->copyExactFile($this->sourceRoot . '/.htaccess', $publicRoot . '/.htaccess', 0644);
            $this->copyExactFile($this->sourceRoot . '/service-worker.js', $publicRoot . '/service-worker.js', 0644);
            $this->copyOptionalRootMetadata($publicRoot);
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

            if (is_dir($applicationRoot . '/_vendor')) {
                $this->syncPublicVendorAssets($destinationRoot);
            }

            $this->validatePublicBoundary($destinationRoot);
        } catch (\Throwable $throwable) {
            $this->removeTree($destinationRoot);
            throw $throwable;
        }
    }

    public function syncPublicVendorAssets(string $distributionRoot): void
    {
        $applicationDemo = $distributionRoot . '/' . self::APPLICATION_DIRECTORY
            . '/_vendor/s2/admin-yard/demo';
        $publicDemo = $distributionRoot . '/' . self::PUBLIC_DIRECTORY
            . '/_vendor/s2/admin-yard/demo';

        foreach (['script.js', 'style.css'] as $filename) {
            $this->copyExactFile($applicationDemo . '/' . $filename, $publicDemo . '/' . $filename, 0644);
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

            if (!isset(self::PUBLIC_ROOT_ENTRIES[$entry])) {
                throw new \RuntimeException('Unexpected public document-root entry: ' . $entry);
            }
        }

        $files = $this->regularFiles($publicRoot);
        foreach (array_keys($files) as $relativePath) {
            $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
            if (isset(self::ACTIVE_EXTENSIONS[$extension]) && !isset(self::PUBLIC_ENTRYPOINTS[$relativePath])) {
                throw new \RuntimeException('Unexpected active file below the document root: ' . $relativePath);
            }
        }

        foreach (array_keys(self::PUBLIC_ENTRYPOINTS) as $entrypoint) {
            if (!isset($files[$entrypoint])) {
                throw new \RuntimeException('A required public entrypoint is missing: ' . $entrypoint);
            }
        }
    }

    private function copyApplicationSources(string $applicationRoot): void
    {
        foreach (self::APPLICATION_SOURCE_DIRECTORIES as $directory) {
            $this->copyTree(
                $this->sourceRoot . '/' . $directory,
                $applicationRoot . '/' . $directory,
                0755,
                0644,
            );
        }

        foreach (self::APPLICATION_SOURCE_FILES as $filename) {
            $this->copyExactFile(
                $this->sourceRoot . '/' . $filename,
                $applicationRoot . '/' . $filename,
                0644,
            );
        }

        foreach (self::APPLICATION_TOOL_FILES as $filename) {
            $this->copyExactFile(
                $this->sourceRoot . '/tools/' . $filename,
                $applicationRoot . '/tools/' . $filename,
                0755,
            );
        }
    }

    private function createRuntimeDirectories(string $applicationRoot, string $publicRoot): void
    {
        $privateCache = $applicationRoot . '/_cache';
        $publicCache  = $publicRoot . '/_cache';
        $pictures     = $publicRoot . '/_pictures';
        $this->createDirectory($privateCache, 0750);
        $this->createDirectory($publicCache, 0755);
        $this->createDirectory($pictures, 0755);

        foreach (['.htaccess', 'index.html'] as $filename) {
            $this->copyExactFile($this->sourceRoot . '/_cache/' . $filename, $privateCache . '/' . $filename, 0644);
            $this->copyExactFile($this->sourceRoot . '/_cache/' . $filename, $publicCache . '/' . $filename, 0644);
            $this->copyExactFile($this->sourceRoot . '/_pictures/' . $filename, $pictures . '/' . $filename, 0644);
        }
    }

    private function copyPublicSources(string $publicRoot): void
    {
        foreach (self::PUBLIC_SOURCE_DIRECTORIES as $directory) {
            $this->copyTree(
                $this->sourceRoot . '/' . $directory,
                $publicRoot . '/' . $directory,
                0755,
                0644,
                fn(string $relativePath): bool => $this->isPublicAsset($directory, $relativePath),
            );
        }
    }

    private function writeEntrypoints(string $publicRoot): void
    {
        foreach (self::PUBLIC_ENTRYPOINTS as $publicPath => $applicationPath) {
            $isAdmin = str_starts_with($publicPath, '_admin/');
            $applicationRootExpression = $isAdmin ? 'dirname(__DIR__, 2)' : 'dirname(__DIR__)';
            $publicRootExpression      = $isAdmin ? 'dirname(__DIR__)' : '__DIR__';
            $content = <<<PHP
<?php
/**
 * Public shared-hosting entrypoint generated by Register's distribution builder.
 */

declare(strict_types = 1);

define('REGISTER_APP_ROOT', {$applicationRootExpression} . '/register-app');
define('REGISTER_PUBLIC_ROOT', {$publicRootExpression});

require constant('REGISTER_APP_ROOT') . '/{$applicationPath}';
PHP;
            $this->writeFile($publicRoot . '/' . $publicPath, $content . "\n", 0644);
        }
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

    private function isPublicAsset(string $sourceDirectory, string $relativePath): bool
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (!isset(self::PUBLIC_EXTENSIONS[$extension])) {
            return false;
        }

        if ($extension === 'json' && !\in_array($sourceDirectory, ['_assets', '_extensions'], true)) {
            return false;
        }

        return !\in_array($extension, ['htm', 'html'], true)
            || \in_array($sourceDirectory, ['_assets', '_extensions'], true);
    }

    /** @param null|callable(string): bool $fileFilter */
    private function copyTree(
        string $source,
        string $destination,
        int $directoryMode,
        int $fileMode,
        ?callable $fileFilter = null,
    ): void {
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
                continue;
            }

            if (!$entry->isFile()) {
                continue;
            }

            if ($fileFilter !== null && !\call_user_func($fileFilter, $relativePath)) {
                continue;
            }

            $this->copyExactFile($sourcePath, $targetPath, $fileMode);
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

    private function writeFile(string $filename, string $content, int $mode): void
    {
        $this->createDirectory(\dirname($filename), 0755);
        if (file_put_contents($filename, $content, LOCK_EX) !== \strlen($content)) {
            throw new \RuntimeException('Unable to write distribution file: ' . $filename);
        }

        $this->setMode($filename, $mode);
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

            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($directory);
    }
}
