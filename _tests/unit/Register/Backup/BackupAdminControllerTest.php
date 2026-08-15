<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Backup;

use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use Register\Backup\Admin\BackupAdminController;
use Register\Backup\Admin\BackupToken;
use Register\Backup\BackupEncryptionKeyProvider;
use Register\Backup\BackupEncryptor;
use Register\Backup\BackupManager;
use Register\Backup\DatabaseSnapshotter;
use S2\AdminYard\SettingStorage\SettingStorageInterface;
use S2\AdminYard\Translator;
use S2\Cms\Comment\Antispam\SpamIdentityHasher;
use S2\Cms\Model\AuthManager;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Security\Audit\SecurityAuditLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class BackupAdminControllerTest extends Unit
{
    private string $directory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->directory = sys_get_temp_dir() . '/register_backup_admin_' . bin2hex(random_bytes(8));
    }

    #[\Override]
    protected function _after(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->directory);
    }

    public function testDownloadRejectsGetBeforeReadingTheArchive(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects(self::never())->method('verifyCurrentPassword');

        [$controller] = $this->controller($authManager);
        $response = $controller->downloadLatest(Request::create('/backup', Request::METHOD_GET));

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
        self::assertSame(Request::METHOD_POST, $response->headers->get('Allow'));
    }

    public function testDownloadRequiresAValidCsrfTokenBeforePasswordVerification(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects(self::never())->method('verifyCurrentPassword');

        [$controller] = $this->controller($authManager);
        $response = $controller->downloadLatest(Request::create(
            '/backup',
            Request::METHOD_POST,
            ['csrf_token' => 'wrong', 'password' => 'correct horse battery staple'],
        ));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('The backup request has expired. Reload the dashboard and try again.', $response->getContent());
    }

    public function testManualBackupOperationsRequireTheCurrentPassword(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager
            ->expects(self::exactly(2))
            ->method('verifyCurrentPassword')
            ->willReturn(false);

        [$controller, $token] = $this->controller($authManager);
        $createResponse = $controller->create(Request::create(
            '/backup',
            Request::METHOD_POST,
            [
                'csrf_token' => $token->value(),
                'password'   => 'wrong-secret-password',
            ],
        ));
        $downloadResponse = $controller->downloadLatest(Request::create(
            '/backup',
            Request::METHOD_POST,
            [
                'csrf_token' => $token->value(),
                'password'   => 'wrong-secret-password',
            ],
        ));

        foreach ([$createResponse, $downloadResponse] as $response) {
            self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
            self::assertSame('The current password is incorrect.', $response->getContent());
        }

        $audit = file_get_contents($this->directory . '/security-audit.jsonl');
        self::assertIsString($audit);
        self::assertStringNotContainsString('wrong-secret-password', $audit);
        self::assertSame(2, substr_count($audit, '"outcome":"denied"'));
    }

    public function testCreatedBackupIsDownloadedAsAnEncryptedAttachment(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects(self::once())->method('verifyCurrentPassword')->willReturn(true);
        [$controller, $token] = $this->controller($authManager);

        $response = $controller->create(Request::create(
            '/backup',
            Request::METHOD_POST,
            [
                'csrf_token' => $token->value(),
                'password'   => 'correct horse battery staple',
            ],
        ));

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('.zip.enc', (string)$response->headers->get('Content-Disposition'));
        $path = $response->getFile()->getPathname();
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertStringStartsWith("REGISTER-BACKUP\0\x01", $contents);
    }

    /** @return array{0:BackupAdminController,1:BackupToken} */
    private function controller(AuthManager $authManager): array
    {
        if (!mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create a temporary backup test directory.');
        }

        $pdo = new \PDO('sqlite::memory:');
        $auditLogger = new SecurityAuditLogger(
            $this->directory . '/security-audit.jsonl',
            new SpamIdentityHasher(str_repeat('a', 32)),
        );
        $backupManager = new BackupManager(
            new DatabaseSnapshotter($pdo, 'sqlite', '', ':memory:', '', ''),
            new BackupEncryptor(new BackupEncryptionKeyProvider(str_repeat('b', 32))),
            new NullLogger(),
            $auditLogger,
            $this->directory . '/backups',
            $this->directory . '/media',
            1,
            'test-version',
        );
        $token = new BackupToken(new BackupAdminSettingStorage([
            'main_csrf_token' => str_repeat('b', 32),
        ]));
        $permissionChecker = new PermissionChecker();
        $permissionChecker->setUser([
            'id'         => 7,
            'login'      => 'admin',
            'edit_users' => true,
        ]);
        $translations = require \dirname(__DIR__, 4) . '/_admin/lang/en/admin.php';

        return [
            new BackupAdminController(
                $backupManager,
                $token,
                $permissionChecker,
                $authManager,
                new Translator($translations, 'en'),
                new NullLogger(),
                $auditLogger,
            ),
            $token,
        ];
    }
}

/** @internal */
final class BackupAdminSettingStorage implements SettingStorageInterface
{
    /** @param array<string, array<mixed>|string|int|float|bool|null> $values */
    public function __construct(private array $values)
    {
    }

    #[\Override]
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** @return array<mixed>|string|int|float|bool|null */
    #[\Override]
    public function get(string $key): array|string|int|float|bool|null
    {
        return $this->values[$key] ?? null;
    }

    /** @param array<mixed>|string|int|float|bool|null $data */
    #[\Override]
    public function set(string $key, array|string|int|float|bool|null $data): void
    {
        $this->values[$key] = $data;
    }

    #[\Override]
    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }
}
