<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Asset;

use Codeception\Test\Unit;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use S2\Cms\Asset\AssetMerge;
use S2\Cms\HttpClient\HttpClient;
use S2\Cms\HttpClient\HttpClientException;
use S2\Cms\HttpClient\HttpResponse;

final class AssetMergeTest extends Unit
{
    private string $cacheDir = '';

    #[\Override]
    protected function _before(): void
    {
        $this->cacheDir = \sys_get_temp_dir() . '/s2_asset_merge_test_' . \bin2hex(\random_bytes(4)) . '/';
        \mkdir($this->cacheDir, 0777, true);
    }

    #[\Override]
    protected function _after(): void
    {
        $this->removeDir($this->cacheDir);
    }

    public function testExternalAssetFetchErrorIsLogged(): void
    {
        $logger = new RecordingLogger();
        $merge  = new AssetMerge(
            new FailingHttpClient(),
            $logger,
            $this->cacheDir,
            '/_cache/',
            'test_scripts',
            AssetMerge::TYPE_JS,
            false
        );

        $externalUrl = 'https://cdn.example.com/script.js';
        $merge->concat($externalUrl);

        $paths = $merge->getMergedPaths();

        self::assertSame($externalUrl, $paths[0]);
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        self::assertSame('Failed to fetch external asset.', $logger->records[0]['message']);
        self::assertSame($externalUrl, $logger->records[0]['context']['url']);
        self::assertInstanceOf(HttpClientException::class, $logger->records[0]['context']['exception']);
        self::assertStringContainsString('SSL certificate problem', $logger->records[0]['context']['exception']->getMessage());

        $generatedFiles = glob($this->cacheDir . 'test_scripts.*.js');
        $metadataFiles  = glob($this->cacheDir . 'test_scripts.*.js.meta.php');
        self::assertIsArray($generatedFiles);
        self::assertIsArray($metadataFiles);
        self::assertCount(1, $generatedFiles);
        self::assertCount(1, $metadataFiles);
        $generatedPermissions = fileperms($generatedFiles[0]);
        $metadataPermissions  = fileperms($metadataFiles[0]);
        self::assertIsInt($generatedPermissions);
        self::assertIsInt($metadataPermissions);
        self::assertSame(0644, $generatedPermissions & 0777);
        self::assertSame(0600, $metadataPermissions & 0777);

        $compressedFiles = glob($this->cacheDir . 'test_scripts.*.js.gz');
        self::assertIsArray($compressedFiles);
        if (\function_exists('gzencode')) {
            self::assertCount(1, $compressedFiles);
            $compressedPermissions = fileperms($compressedFiles[0]);
            self::assertIsInt($compressedPermissions);
            self::assertSame(0644, $compressedPermissions & 0777);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $files = \scandir($dir);
        if ($files === false) {
            throw new \RuntimeException('Unable to scan the temporary asset directory.');
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            if (\is_dir($path)) {
                $this->removeDir($path);
            } else {
                \unlink($path);
            }
        }

        \rmdir($dir);
    }
}

readonly class FailingHttpClient extends HttpClient
{
    #[\Override]
    public function fetch(string $url): HttpResponse
    {
        throw new HttpClientException('SSL certificate problem: unable to get local issuer certificate');
    }
}

class RecordingLogger extends AbstractLogger
{
    /**
     * @var array<mixed, array<string, mixed>>
     */
    public array $records = [];

    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => $level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }
}
