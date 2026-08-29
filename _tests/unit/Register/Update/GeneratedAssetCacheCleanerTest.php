<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Update;

use Codeception\Test\Unit;
use Register\Update\GeneratedAssetCacheCleaner;
use Symfony\Component\Filesystem\Filesystem;

final class GeneratedAssetCacheCleanerTest extends Unit
{
    private string $temporaryRoot = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/register_asset_cache_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryRoot . '/_cache/nested', 0700, true);
        mkdir($this->temporaryRoot . '/_cache/pages', 0700, true);
        mkdir($this->temporaryRoot . '/_cache/recommendations', 0700, true);
        mkdir($this->temporaryRoot . '/_cache/register-updates/session', 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->temporaryRoot);
    }

    public function testRemovesGeneratedAssetsAndKeepsBoundaryFiles(): void
    {
        file_put_contents($this->temporaryRoot . '/_cache/.htaccess', 'deny');
        file_put_contents($this->temporaryRoot . '/_cache/index.html', '');
        file_put_contents($this->temporaryRoot . '/_cache/site.css', 'old');
        file_put_contents($this->temporaryRoot . '/_cache/site.css.meta.php', 'old');
        file_put_contents($this->temporaryRoot . '/_cache/nested/site.js', 'old');
        file_put_contents($this->temporaryRoot . '/_cache/pages/item', 'rendered page');
        file_put_contents($this->temporaryRoot . '/_cache/recommendations/item', 'expensive result');
        file_put_contents($this->temporaryRoot . '/_cache/register-updates/session/state.json', '{"status":"migrating"}');
        file_put_contents($this->temporaryRoot . '/_cache/performance.jsonl', '{"duration_ms":1200}');
        file_put_contents($this->temporaryRoot . '/_cache/query-profiler.jsonl', '{"query_count":1}');
        file_put_contents($this->temporaryRoot . '/_cache/query-profiler-state.json', '{"expires_at":1}');
        file_put_contents($this->temporaryRoot . '/_cache/app.log', 'diagnostic');
        file_put_contents($this->temporaryRoot . '/_cache/picture-upload-quota.lock', 'lock');

        (new GeneratedAssetCacheCleaner($this->temporaryRoot))->clear();

        self::assertFileExists($this->temporaryRoot . '/_cache/.htaccess');
        self::assertFileExists($this->temporaryRoot . '/_cache/index.html');
        self::assertFileDoesNotExist($this->temporaryRoot . '/_cache/site.css');
        self::assertFileDoesNotExist($this->temporaryRoot . '/_cache/site.css.meta.php');
        self::assertDirectoryDoesNotExist($this->temporaryRoot . '/_cache/nested');
        self::assertDirectoryDoesNotExist($this->temporaryRoot . '/_cache/pages');
        self::assertFileExists($this->temporaryRoot . '/_cache/recommendations/item');
        self::assertFileExists($this->temporaryRoot . '/_cache/register-updates/session/state.json');
        self::assertFileExists($this->temporaryRoot . '/_cache/performance.jsonl');
        self::assertFileExists($this->temporaryRoot . '/_cache/query-profiler.jsonl');
        self::assertFileExists($this->temporaryRoot . '/_cache/query-profiler-state.json');
        self::assertFileExists($this->temporaryRoot . '/_cache/app.log');
        self::assertFileExists($this->temporaryRoot . '/_cache/picture-upload-quota.lock');
    }
}
