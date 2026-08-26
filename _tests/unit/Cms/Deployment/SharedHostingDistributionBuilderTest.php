<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Deployment;

use Codeception\Test\Unit;
use Register\Tools\Deployment\SharedHostingDistributionBuilder;
use Symfony\Component\Filesystem\Filesystem;

require_once \dirname(__DIR__, 4) . '/tools/deployment/SharedHostingDistributionBuilder.php';

final class SharedHostingDistributionBuilderTest extends Unit
{
    private string $temporaryRoot = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/register_distribution_test_' . bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function _after(): void
    {
        if ($this->temporaryRoot !== '') {
            (new Filesystem())->remove($this->temporaryRoot);
        }
    }

    public function testBuildsSingleDocumentRootAndArchive(): void
    {
        $projectRoot   = \dirname(__DIR__, 4);
        $distribution = $this->temporaryRoot . '/distribution';
        $publicRoot    = $distribution . '/public_html';
        $builder       = new SharedHostingDistributionBuilder($projectRoot);

        $builder->buildDirectory($distribution, includeInstalledVendor: false);
        $builder->validatePublicBoundary($distribution);

        self::assertDirectoryDoesNotExist($distribution . '/register-app');
        self::assertFileExists($publicRoot . '/_include/common.php');
        self::assertFileExists($publicRoot . '/_admin/templates/login.php.inc');
        self::assertFileExists($publicRoot . '/_styles/register/register.php');
        self::assertFileExists($publicRoot . '/composer.lock');
        self::assertDirectoryDoesNotExist($publicRoot . '/_tests');
        self::assertFileDoesNotExist($publicRoot . '/config.local.php');

        foreach ([
            'decrypt-backup.php',
            'generate-backup-keypair.php',
            'check-activitypub-interoperability.php',
            'restore-activitypub-identity.php',
        ] as $tool) {
            $filename = $publicRoot . '/tools/' . $tool;
            self::assertFileExists($filename);
            $permissions = fileperms($filename);
            self::assertIsInt($permissions);
            self::assertSame(0755, $permissions & 0777);
        }

        foreach ([
            'backups.md',
            'activitypub-operations.md',
            'activitypub-interoperability.md',
            'activitypub-protocol-profile.md',
            'secret-rotation.md',
            'self-update.md',
            'shared-hosting.md',
            'UPDATES.md',
        ] as $documentation) {
            self::assertFileExists($distribution . '/' . $documentation);
        }

        self::assertFileExists($publicRoot . '/index.php');
        self::assertSame(file_get_contents($projectRoot . '/index.php'), file_get_contents($publicRoot . '/index.php'));
        $frontController = file_get_contents($publicRoot . '/index.php');
        self::assertIsString($frontController);
        self::assertStringNotContainsString('REGISTER_APP_ROOT', $frontController);
        self::assertStringContainsString("__DIR__ . '/_include/common.php'", $frontController);

        self::assertFileExists($publicRoot . '/service-worker.js');
        $serviceWorker = file_get_contents($publicRoot . '/service-worker.js');
        self::assertIsString($serviceWorker);
        self::assertStringContainsString("const CACHE_NAME = 'register-offline-v3';", $serviceWorker);
        self::assertStringContainsString("new Request(request, {cache: 'no-cache'})", $serviceWorker);
        self::assertFileExists($publicRoot . '/_admin/js/webauthn.js');
        self::assertFileExists($publicRoot . '/_assets/register/syntax-highlighting/vendor/highlight.js/languages.json');
        self::assertFileExists($publicRoot . '/_styles/register/site.css');
        self::assertFileExists($publicRoot . '/_cache/.htaccess');
        self::assertFileExists($publicRoot . '/_pictures/.htaccess');
        self::assertFileExists($publicRoot . '/_assets/register/admin-yard/script.js');
        self::assertFileExists($publicRoot . '/_assets/register/admin-yard/style.css');

        self::assertFileExists($publicRoot . '/files/ttt/index.html');
        $ticTacToe = file_get_contents($publicRoot . '/files/ttt/index.html');
        self::assertIsString($ticTacToe);
        self::assertStringContainsString('.table:target, .table:last-child', $ticTacToe);
        self::assertStringContainsString('id="---------"', $ticTacToe);
        foreach ([
            'files/acid0/index.html',
            'files/demo-assets/player-test.mp4',
            'files/ie-player.html',
            'files/opera-cam-recog.html',
            'files/opera-mystery/bolk.css',
            'files/olimp/index.html',
            'files/webkit-mjpeg.html',
            'files/dogfight/index.html',
            'files/tank-1k-game-2/index.js',
            'files/places/index.html',
        ] as $demo) {
            self::assertFileExists($publicRoot . '/' . $demo);
        }

        $apachePolicy = file_get_contents($publicRoot . '/.htaccess');
        self::assertIsString($apachePolicy);
        self::assertStringContainsString('RewriteRule ^_vendor/ - [F,L,NC]', $apachePolicy);
        self::assertStringContainsString('Header always set X-Content-Type-Options "nosniff"', $apachePolicy);

        $archive = $this->temporaryRoot . '/register.zip';
        $builder->createArchive($distribution, $archive);
        $archiveContent = file_get_contents($archive);
        self::assertIsString($archiveContent);
        self::assertStringStartsWith("PK\x03\x04", $archiveContent);
        foreach ([
            'public_html/index.php',
            'public_html/_include/common.php',
            'public_html/tools/decrypt-backup.php',
            'public_html/files/ttt/index.html',
            'public_html/files/acid0/index.html',
            'activitypub-operations.md',
            'backups.md',
            'UPDATES.md',
        ] as $archivePath) {
            self::assertStringContainsString($archivePath, $archiveContent);
        }

        self::assertStringNotContainsString('register-app/', $archiveContent);
    }

    public function testRefusesToOverwriteAnExistingDestination(): void
    {
        mkdir($this->temporaryRoot, 0700, true);
        $builder = new SharedHostingDistributionBuilder(\dirname(__DIR__, 4));

        $this->expectException(\RuntimeException::class);
        $builder->buildDirectory($this->temporaryRoot, includeInstalledVendor: false);
    }

    public function testBoundaryValidationRejectsAnUnexpectedActiveDataFile(): void
    {
        $distribution = $this->temporaryRoot . '/distribution';
        $builder = new SharedHostingDistributionBuilder(\dirname(__DIR__, 4));
        $builder->buildDirectory($distribution, includeInstalledVendor: false);
        file_put_contents($distribution . '/public_html/_assets/shell.php', '<?php');

        $this->expectException(\RuntimeException::class);
        $builder->validatePublicBoundary($distribution);
    }

    public function testBoundaryValidationRejectsARewrittenEntrypoint(): void
    {
        $distribution = $this->temporaryRoot . '/distribution';
        $builder = new SharedHostingDistributionBuilder(\dirname(__DIR__, 4));
        $builder->buildDirectory($distribution, includeInstalledVendor: false);
        file_put_contents($distribution . '/public_html/index.php', '<?php');

        $this->expectException(\RuntimeException::class);
        $builder->validatePublicBoundary($distribution);
    }
}
