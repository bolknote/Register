<?php
/**
 * @copyright 2023-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Asset;

use MatthiasMullie\Minify\CSS;
use MatthiasMullie\Minify\JS;
use MatthiasMullie\Minify\Minify;
use Psr\Log\LoggerInterface;
use Register\Core\HttpClient\HttpClient;
use Register\Core\Http\CompressionCodecRegistry;
use Symfony\Component\Filesystem\Filesystem;

class AssetMerge implements AssetMergeInterface
{
    public const string TYPE_CSS = 'css';

    public const string TYPE_JS = 'js';

    private const string META_KEY_FAILED_FILES = 'failed_files';

    private const string META_KEY_CONTENT_HASH = 'hash';

    /**
     * @var string[]
     */
    private array $filesToMerge = [];

    /**
     * @var string[]
     */
    private array $failedExternalFiles = [];

    private string $mergedHash = '';

    private ?Filesystem $filesystem = null;

    private readonly CompressionCodecRegistry $compressionCodecs;

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string     $publicCacheDir,
        private readonly string     $publicCachePath,
        private readonly string     $cacheFilenamePrefix,
        private readonly string     $type,
        private readonly bool       $devEnv,
        ?CompressionCodecRegistry   $compressionCodecs = null,
    ) {
        $this->compressionCodecs = $compressionCodecs ?? CompressionCodecRegistry::fromEnvironment();
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function concat(string $fileName): void
    {
        $this->filesToMerge[] = $fileName;
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function getMergedPaths(): array
    {
        if ($this->needToDump() || !$this->readMetadata()) {
            $this->dumpContent();
        }

        $result   = $this->failedExternalFiles;
        $result[] = \sprintf('%s%s.asset?v=%s', $this->publicCachePath, $this->getFilename(), $this->mergedHash);
        return $result;
    }

    private function minifyFiles(Minify $minifier): string
    {
        $this->failedExternalFiles = [];

        foreach ($this->filesToMerge as $fileToMerge) {
            $parsedUrl = parse_url($fileToMerge);
            if (isset($parsedUrl['host'])) {
                // file is external
                try {
                    $response = $this->httpClient->fetch($fileToMerge);
                    if (!$response->isSuccessful()) {
                        throw new \RuntimeException('Failed to fetch ' . $fileToMerge);
                    }

                    if ($response->content !== null) {
                        $minifier->add($response->content);
                    }
                } catch (\Throwable $e) {
                    // Store failed file and continue with next one
                    $this->failedExternalFiles[] = $fileToMerge;
                    $this->logger->warning('Failed to fetch external asset.', [
                        'url'       => $fileToMerge,
                        'exception' => $e,
                    ]);
                }
            } else {
                $minifier->add($fileToMerge);
            }
        }

        /**
         * Using a "fake" temp filename to dump.
         * 1. It is constructed using a realpath(). Otherwise, the minifier converts relative paths in CSS with errors.
         * 2. Minifier allows file corruptions on race condition. We do not trust the resulted file and ignore it.
         * The file will be dumped again later with an atomic operation.
         */
        return $minifier->minify($this->getDumpTempFilename());
    }

    private function dumpContent(): void
    {
        if ($this->type === self::TYPE_CSS) {
            $minifier = new CSS();
            $minifier->setMaxImportSize(4);
            $content = $this->minifyFiles($minifier);
        } elseif ($this->type === self::TYPE_JS) {
            $minifier = new JS();
            $content  = $this->minifyFiles($minifier);
        } else {
            $content = $this->getConcatenatedContent();
        }

        $this->fileSystem()->dumpFile($this->getDumpFilename(), $content);
        $this->setFileMode($this->getDumpFilename(), 0644);
        $this->dumpEncodedVariants($content);

        $this->fileSystem()->remove($this->getDumpTempFilename());
        $this->mergedHash = md5($content);
        $this->fileSystem()->dumpFile($this->getMetadataFilename(), '<?php return ' . var_export([
                self::META_KEY_CONTENT_HASH => $this->mergedHash,
                self::META_KEY_FAILED_FILES => $this->failedExternalFiles,
            ], true) . ';');
        $this->setFileMode($this->getMetadataFilename(), 0600);
        if (\function_exists('opcache_invalidate')) {
            opcache_invalidate($this->getMetadataFilename(), true);
        }
    }

    private function needToDump(): bool
    {
        if (!file_exists($this->getDumpFilename())) {
            return true;
        }

        if ($this->devEnv) {
            // TODO add tracking of modified images that are embedded in CSS
            $dumpModifiedAt = filemtime($this->getDumpFilename());
            if ($dumpModifiedAt === false) {
                return true;
            }

            foreach ($this->filesToMerge as $fileToMerge) {
                $parsedUrl = parse_url($fileToMerge);
                if (isset($parsedUrl['host'])) {
                    // file is external
                    continue;
                }

                $fileModifiedAt = filemtime($fileToMerge);
                if ($fileModifiedAt === false || $fileModifiedAt > $dumpModifiedAt) {
                    return true;
                }
            }
        }

        return false;
    }

    private function getDumpFilename(): string
    {
        return \sprintf('%s%s', $this->publicCacheDir, $this->getFilename());
    }

    private function getDumpTempFilename(): string
    {
        $realCacheDir = realpath($this->publicCacheDir);
        if ($realCacheDir === false) {
            throw new \RuntimeException('Asset cache directory does not exist: ' . $this->publicCacheDir);
        }

        return \sprintf('%s/%s', $realCacheDir, $this->getFilename('tmp'));
    }

    private function getMetadataFilename(): string
    {
        return \sprintf('%s%s.meta.php', $this->publicCacheDir, $this->getFilename());
    }

    private function getFilename(?string $postfix = null): string
    {
        return \sprintf(
            '%s.%x.%s',
            $this->cacheFilenamePrefix,
            crc32(serialize($this->filesToMerge)),
            ($postfix !== null ? $postfix . '.' : '') . $this->type
        );
    }

    private function fileSystem(): Filesystem
    {
        return $this->filesystem ?? $this->filesystem = new Filesystem();
    }

    private function readMetadata(): bool
    {
        $metadataFilename = $this->getMetadataFilename();
        if (!file_exists($metadataFilename)) {
            return false;
        }

        $result = register_call_without_warnings(static fn(): mixed => include $metadataFilename);
        if (!\is_array($result)) {
            return false;
        }

        if (!isset($result[self::META_KEY_CONTENT_HASH]) || !\is_string($result[self::META_KEY_CONTENT_HASH])) {
            return false;
        }

        if (!isset($result[self::META_KEY_FAILED_FILES]) || !\is_array($result[self::META_KEY_FAILED_FILES])) {
            return false;
        }

        foreach ($result[self::META_KEY_FAILED_FILES] as $failedFile) {
            if (!\is_string($failedFile)) {
                return false;
            }
        }

        $this->failedExternalFiles = $result[self::META_KEY_FAILED_FILES];
        $this->mergedHash          = $result[self::META_KEY_CONTENT_HASH];

        return true;
    }

    private function getConcatenatedContent(): string
    {
        $content = '';
        foreach ($this->filesToMerge as $fileToMerge) {
            if ($content !== '') {
                $content .= "\n";
            }

            $fileContent = file_get_contents($fileToMerge);
            if ($fileContent === false) {
                throw new \RuntimeException('Unable to read asset: ' . $fileToMerge);
            }

            $content .= $fileContent;
        }

        return $content;
    }

    private function setFileMode(string $filename, int $mode): void
    {
        if (!chmod($filename, $mode)) {
            throw new \RuntimeException('Unable to set safe permissions on generated asset: ' . $filename);
        }
    }

    private function dumpEncodedVariants(string $content): void
    {
        $suffixes = [
            CompressionCodecRegistry::BROTLI => '.br',
            CompressionCodecRegistry::ZSTD   => '.zst',
            CompressionCodecRegistry::GZIP   => '.gz',
        ];
        $this->fileSystem()->remove(array_map(
            fn(string $suffix): string => $this->getDumpFilename() . $suffix,
            array_values($suffixes),
        ));

        foreach ($suffixes as $encoding => $suffix) {
            $compressor = $this->compressionCodecs->compressor($encoding);
            if (!$compressor instanceof \Closure) {
                continue;
            }

            $compressedContent = $compressor($content);
            if (!\is_string($compressedContent)) {
                $this->logger->warning('Unable to compress generated asset.', ['encoding' => $encoding]);
                continue;
            }

            $filename = $this->getDumpFilename() . $suffix;
            $this->fileSystem()->dumpFile($filename, $compressedContent);
            $this->setFileMode($filename, 0644);
        }
    }
}
