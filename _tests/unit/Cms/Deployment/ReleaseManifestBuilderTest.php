<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Deployment;

use Codeception\Test\Unit;
use Register\Tools\Deployment\ReleaseManifestBuilder;
use Symfony\Component\Filesystem\Filesystem;

require_once \dirname(__DIR__, 4) . '/tools/deployment/SharedHostingDistributionBuilder.php';
require_once \dirname(__DIR__, 4) . '/tools/deployment/ReleaseManifestBuilder.php';

final class ReleaseManifestBuilderTest extends Unit
{
    private string $temporaryRoot = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/register_manifest_builder_' . bin2hex(random_bytes(6));
        foreach ([
            'register-app/_cache',
            'public_html/_cache',
            'public_html/_pictures',
        ] as $directory) {
            mkdir($this->temporaryRoot . '/' . $directory, 0700, true);
        }
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->temporaryRoot);
    }

    public function testManagesBoundaryFilesAndExcludesRuntimeState(): void
    {
        file_put_contents($this->temporaryRoot . '/register-app/file.php', '<?php');
        file_put_contents($this->temporaryRoot . '/register-app/_cache/.htaccess', 'deny');
        file_put_contents($this->temporaryRoot . '/register-app/_cache/index.html', '');
        file_put_contents($this->temporaryRoot . '/register-app/_cache/site.sqlite', 'database');
        file_put_contents($this->temporaryRoot . '/public_html/_cache/.htaccess', 'cache policy');
        file_put_contents($this->temporaryRoot . '/public_html/_cache/index.html', '');
        file_put_contents($this->temporaryRoot . '/public_html/_cache/generated.css', 'generated');
        file_put_contents($this->temporaryRoot . '/public_html/_pictures/.htaccess', 'media policy');
        file_put_contents($this->temporaryRoot . '/public_html/_pictures/index.html', '');
        file_put_contents($this->temporaryRoot . '/public_html/_pictures/photo.jpg', 'user media');

        $manifest = (new ReleaseManifestBuilder())->build(
            $this->temporaryRoot,
            '20260822T000000Z-01234567',
            '2.0.0-edge.20260822.000000',
            '2.0.0',
            'edge',
            20,
            '2026-08-22T00:00:00+00:00',
            str_repeat('a', 40),
            '8.3.0',
            15,
            15,
        );
        $files = $manifest->filesByKey();

        self::assertArrayHasKey('app:_cache/.htaccess', $files);
        self::assertArrayHasKey('app:_cache/index.html', $files);
        self::assertArrayHasKey('public:_cache/.htaccess', $files);
        self::assertArrayHasKey('public:_cache/index.html', $files);
        self::assertArrayHasKey('public:_pictures/.htaccess', $files);
        self::assertArrayHasKey('public:_pictures/index.html', $files);
        self::assertArrayNotHasKey('app:_cache/site.sqlite', $files);
        self::assertArrayNotHasKey('public:_cache/generated.css', $files);
        self::assertArrayNotHasKey('public:_pictures/photo.jpg', $files);
    }
}
