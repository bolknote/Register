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

        (new GeneratedAssetCacheCleaner($this->temporaryRoot))->clear();

        self::assertFileExists($this->temporaryRoot . '/_cache/.htaccess');
        self::assertFileExists($this->temporaryRoot . '/_cache/index.html');
        self::assertFileDoesNotExist($this->temporaryRoot . '/_cache/site.css');
        self::assertFileDoesNotExist($this->temporaryRoot . '/_cache/site.css.meta.php');
        self::assertDirectoryDoesNotExist($this->temporaryRoot . '/_cache/nested');
    }
}
