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

    public function testBuildsMinimalSplitDocumentRootAndArchive(): void
    {
        $projectRoot     = \dirname(__DIR__, 4);
        $distribution   = $this->temporaryRoot . '/distribution';
        $builder        = new SharedHostingDistributionBuilder($projectRoot);
        $applicationDir = $distribution . '/register-app';
        $publicDir      = $distribution . '/public_html';

        $builder->buildDirectory($distribution, includeInstalledVendor: false);
        $builder->validatePublicBoundary($distribution);

        self::assertFileExists($applicationDir . '/_include/common.php');
        self::assertFileExists($applicationDir . '/_admin/templates/login.php.inc');
        self::assertFileExists($applicationDir . '/_styles/register/register.php');
        self::assertFileExists($applicationDir . '/composer.lock');
        self::assertFileExists($applicationDir . '/tools/decrypt-backup.php');
        $decryptToolPermissions = fileperms($applicationDir . '/tools/decrypt-backup.php');
        self::assertIsInt($decryptToolPermissions);
        self::assertSame(0755, $decryptToolPermissions & 0777);
        self::assertFileExists($applicationDir . '/tools/generate-backup-keypair.php');
        $keyToolPermissions = fileperms($applicationDir . '/tools/generate-backup-keypair.php');
        self::assertIsInt($keyToolPermissions);
        self::assertSame(0755, $keyToolPermissions & 0777);
        self::assertFileExists($applicationDir . '/tools/check-activitypub-interoperability.php');
        self::assertFileExists($applicationDir . '/tools/restore-activitypub-identity.php');
        $activityPubRecoveryPermissions = fileperms(
            $applicationDir . '/tools/restore-activitypub-identity.php',
        );
        self::assertIsInt($activityPubRecoveryPermissions);
        self::assertSame(0755, $activityPubRecoveryPermissions & 0777);
        self::assertFileExists($distribution . '/backups.md');
        self::assertFileExists($distribution . '/activitypub-operations.md');
        self::assertFileExists($distribution . '/activitypub-interoperability.md');
        self::assertFileExists($distribution . '/activitypub-protocol-profile.md');
        self::assertFileExists($distribution . '/secret-rotation.md');
        self::assertFileExists($distribution . '/self-update.md');
        self::assertFileExists($distribution . '/shared-hosting.md');
        self::assertFileExists($distribution . '/UPDATES.md');
        self::assertDirectoryDoesNotExist($applicationDir . '/_tests');
        self::assertFileDoesNotExist($applicationDir . '/config.local.php');

        self::assertFileExists($publicDir . '/index.php');
        self::assertFileExists($publicDir . '/service-worker.js');
        $serviceWorker = file_get_contents($publicDir . '/service-worker.js');
        self::assertIsString($serviceWorker);
        self::assertStringContainsString("const CACHE_NAME = 'register-offline-v3';", $serviceWorker);
        self::assertStringContainsString("new Request(request, {cache: 'no-cache'})", $serviceWorker);
        self::assertFileExists($publicDir . '/_admin/index.php');
        self::assertFileExists($publicDir . '/_admin/js/webauthn.js');
        self::assertFileExists($publicDir . '/_assets/register/syntax-highlighting/vendor/highlight.js/languages.json');
        self::assertFileExists($publicDir . '/_styles/register/site.css');
        self::assertFileExists($publicDir . '/_cache/.htaccess');
        self::assertFileExists($publicDir . '/_pictures/.htaccess');
        self::assertFileExists($publicDir . '/files/ttt/index.html');
        $ticTacToe = file_get_contents($publicDir . '/files/ttt/index.html');
        self::assertIsString($ticTacToe);
        self::assertStringContainsString('.table:target, .table:last-child', $ticTacToe);
        self::assertStringContainsString('id="---------"', $ticTacToe);
        self::assertFileExists($publicDir . '/files/acid0/index.html');
        self::assertFileExists($publicDir . '/files/demo.css');
        self::assertFileExists($publicDir . '/files/demo-assets/player-test.mp4');
        self::assertFileExists($publicDir . '/files/ie-player.html');
        self::assertFileExists($publicDir . '/files/opera-cam-recog.html');
        self::assertFileExists($publicDir . '/files/opera-mystery/index.html');
        self::assertFileExists($publicDir . '/files/opera-mystery/bolk.css');
        self::assertFileExists($publicDir . '/files/olimp/index.html');
        self::assertFileExists($publicDir . '/files/olimp/logo.jpg');
        self::assertFileExists($publicDir . '/files/webkit-mjpeg.html');
        self::assertFileExists($publicDir . '/files/dogfight/bg.jpg');
        self::assertFileExists($publicDir . '/files/tank-1k-game/tank-game.js');
        self::assertFileExists($publicDir . '/files/tank-1k-game-2/index.js');
        self::assertFileExists($publicDir . '/files/exp-jscss/combined.css');
        self::assertFileExists($publicDir . '/files/exp-jscss/combined.js');
        self::assertFileExists($publicDir . '/files/places/index.html');
        self::assertFileExists($publicDir . '/files/webkit-purecss-image.html');
        self::assertFileDoesNotExist($publicDir . '/files/acid0/answer.php');
        self::assertFileDoesNotExist($publicDir . '/files/dogfight/sc-game.php');
        self::assertFileDoesNotExist($publicDir . '/composer.lock');
        self::assertDirectoryDoesNotExist($publicDir . '/_include');
        self::assertDirectoryDoesNotExist($publicDir . '/_tests');
        self::assertFileDoesNotExist($publicDir . '/_styles/register/register.php');
        self::assertFileDoesNotExist($publicDir . '/_styles/register/style.json');
        self::assertFileDoesNotExist($publicDir . '/_assets/register/syntax-highlighting/vendor/highlight.js/README.md');

        $apachePolicy = file_get_contents($publicDir . '/.htaccess');
        self::assertIsString($apachePolicy);
        self::assertStringContainsString('Header always set X-Content-Type-Options "nosniff"', $apachePolicy);
        self::assertStringContainsString(
            'Header always set Referrer-Policy "strict-origin-when-cross-origin"',
            $apachePolicy,
        );
        self::assertStringContainsString(
            'Header always set Permissions-Policy "camera=(self), microphone=(), geolocation=()"',
            $apachePolicy,
        );

        $publicPhpFiles = $this->phpFiles($publicDir);
        self::assertSame([
            '_admin/ajax.php',
            '_admin/index.php',
            '_admin/install.php',
            '_admin/pictman.php',
            'index.php',
        ], $publicPhpFiles);

        $frontController = file_get_contents($publicDir . '/index.php');
        self::assertIsString($frontController);
        self::assertStringContainsString("define('REGISTER_APP_ROOT', dirname(__DIR__) . '/register-app')", $frontController);
        self::assertStringContainsString("define('REGISTER_PUBLIC_ROOT', __DIR__)", $frontController);

        self::assertFileExists($publicDir . '/_assets/register/admin-yard/script.js');
        self::assertFileExists($publicDir . '/_assets/register/admin-yard/style.css');

        $archive = $this->temporaryRoot . '/register.zip';
        $builder->createArchive($distribution, $archive);
        $archiveContent = file_get_contents($archive);
        self::assertIsString($archiveContent);
        self::assertStringStartsWith("PK\x03\x04", $archiveContent);
        self::assertStringContainsString('public_html/index.php', $archiveContent);
        self::assertStringContainsString('public_html/service-worker.js', $archiveContent);
        self::assertStringContainsString('public_html/files/ttt/index.html', $archiveContent);
        self::assertStringContainsString('public_html/files/acid0/index.html', $archiveContent);
        self::assertStringContainsString('public_html/files/demo-assets/player-test.mp4', $archiveContent);
        self::assertStringContainsString('public_html/files/ie-player.html', $archiveContent);
        self::assertStringContainsString('public_html/files/opera-cam-recog.html', $archiveContent);
        self::assertStringContainsString('public_html/files/opera-mystery/bolk.css', $archiveContent);
        self::assertStringContainsString('public_html/files/olimp/index.html', $archiveContent);
        self::assertStringContainsString('public_html/files/webkit-mjpeg.html', $archiveContent);
        self::assertStringContainsString('public_html/files/dogfight/index.html', $archiveContent);
        self::assertStringContainsString('public_html/files/tank-1k-game-2/index.js', $archiveContent);
        self::assertStringContainsString('public_html/files/places/index.html', $archiveContent);
        self::assertStringContainsString('register-app/_include/common.php', $archiveContent);
        self::assertStringContainsString('register-app/tools/decrypt-backup.php', $archiveContent);
        self::assertStringContainsString('register-app/tools/generate-backup-keypair.php', $archiveContent);
        self::assertStringContainsString('register-app/tools/check-activitypub-interoperability.php', $archiveContent);
        self::assertStringContainsString('register-app/tools/restore-activitypub-identity.php', $archiveContent);
        self::assertStringContainsString('activitypub-operations.md', $archiveContent);
        self::assertStringContainsString('activitypub-interoperability.md', $archiveContent);
        self::assertStringContainsString('activitypub-protocol-profile.md', $archiveContent);
        self::assertStringContainsString('backups.md', $archiveContent);
        self::assertStringContainsString('secret-rotation.md', $archiveContent);
        self::assertStringContainsString('self-update.md', $archiveContent);
        self::assertStringContainsString('shared-hosting.md', $archiveContent);
        self::assertStringContainsString('UPDATES.md', $archiveContent);
    }

    public function testRefusesToOverwriteAnExistingDestination(): void
    {
        mkdir($this->temporaryRoot, 0700, true);
        $builder = new SharedHostingDistributionBuilder(\dirname(__DIR__, 4));

        $this->expectException(\RuntimeException::class);
        $builder->buildDirectory($this->temporaryRoot, includeInstalledVendor: false);
    }

    public function testPublicEntrypointBootsTheSiblingApplication(): void
    {
        $distribution = $this->temporaryRoot . '/distribution';
        $builder = new SharedHostingDistributionBuilder(\dirname(__DIR__, 4));
        $builder->buildDirectory($distribution, includeInstalledVendor: false);

        $fixture = <<<'PHP'
<?php
echo json_encode([
    'app' => constant('REGISTER_APP_ROOT'),
    'public' => constant('REGISTER_PUBLIC_ROOT'),
], JSON_THROW_ON_ERROR);
PHP;
        file_put_contents($distribution . '/register-app/index.php', $fixture);

        $result = json_decode(
            $this->runPhp($distribution . '/public_html/index.php'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $resolvedDistribution = realpath($distribution);
        self::assertIsString($resolvedDistribution);
        self::assertSame($resolvedDistribution . '/register-app', $result['app']);
        self::assertSame($resolvedDistribution . '/public_html', $result['public']);
    }

    public function testBoundaryValidationRejectsAnUnexpectedPhpFile(): void
    {
        $distribution = $this->temporaryRoot . '/distribution';
        $builder = new SharedHostingDistributionBuilder(\dirname(__DIR__, 4));
        $builder->buildDirectory($distribution, includeInstalledVendor: false);
        file_put_contents($distribution . '/public_html/_assets/shell.php', '<?php');

        $this->expectException(\RuntimeException::class);
        $builder->validatePublicBoundary($distribution);
    }

    /** @return list<string> */
    private function phpFiles(string $root): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        $files = [];
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                continue;
            }

            if (strtolower($entry->getExtension()) !== 'php') {
                continue;
            }

            $files[] = str_replace('\\', '/', substr($entry->getPathname(), \strlen($root) + 1));
        }

        sort($files, SORT_STRING);

        return $files;
    }

    private function runPhp(string $filename): string
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, $filename], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error  = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error === false ? '' : $error);
        self::assertIsString($output);

        return $output;
    }
}
