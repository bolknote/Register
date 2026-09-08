<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Asset;

use Codeception\Test\Unit;
use Register\Core\Asset\PublicAssetUrl;

final class PublicAssetUrlTest extends Unit
{
    private string $rootDirectory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->rootDirectory = sys_get_temp_dir() . '/register-public-asset-' . bin2hex(random_bytes(8));
        mkdir($this->rootDirectory . '/_assets/register', 0700, true);
        file_put_contents($this->rootDirectory . '/_assets/register/example.js', 'example');
        touch($this->rootDirectory . '/_assets/register/example.js', 1_700_000_000);
        clearstatcache(true, $this->rootDirectory . '/_assets/register/example.js');
    }

    #[\Override]
    protected function _after(): void
    {
        @unlink($this->rootDirectory . '/_assets/register/example.js');
        @rmdir($this->rootDirectory . '/_assets/register');
        @rmdir($this->rootDirectory . '/_assets');
        @rmdir($this->rootDirectory);
    }

    public function testAddsTheFileModificationTimeUnderTheConfiguredBasePath(): void
    {
        $assetUrl = new PublicAssetUrl($this->rootDirectory, '/blog/');

        self::assertSame(
            '/blog/_assets/register/example.js?v=1700000000',
            $assetUrl->versioned('/_assets/register/example.js'),
        );
    }

    public function testRejectsPathsOutsideThePublicRoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PublicAssetUrl($this->rootDirectory, ''))->versioned('/_assets/../config.local.php');
    }

    public function testReportsAMissingAsset(): void
    {
        $this->expectException(\LogicException::class);

        (new PublicAssetUrl($this->rootDirectory, ''))->versioned('/_assets/register/missing.js');
    }
}
