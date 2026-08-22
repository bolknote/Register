<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Update;

use Codeception\Test\Unit;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Register\Update\MaintenanceMode;
use Symfony\Component\Filesystem\Filesystem;

final class MaintenanceModeTest extends Unit
{
    private string $temporaryRoot = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/register_maintenance_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryRoot, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->temporaryRoot);
    }

    public function testEnteringSameUpdateSessionIsIdempotentAfterInterruptedRequest(): void
    {
        $maintenance = new MaintenanceMode($this->temporaryRoot);
        $maintenance->enter('release-one', str_repeat('a', 32));
        $maintenance->enter('release-one', str_repeat('a', 32));

        self::assertTrue($maintenance->active());
        $maintenance->leave(str_repeat('a', 32));
        self::assertFalse($maintenance->active());
    }

    public function testAnotherSessionCannotReuseActiveMaintenanceMode(): void
    {
        $maintenance = new MaintenanceMode($this->temporaryRoot);
        $maintenance->enter('release-one', str_repeat('a', 32));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('another update session');
        $maintenance->enter('release-one', str_repeat('b', 32));
    }

    public function testAnotherSessionCannotLeaveActiveMaintenanceMode(): void
    {
        $maintenance = new MaintenanceMode($this->temporaryRoot);
        $maintenance->enter('release-one', str_repeat('a', 32));

        try {
            $maintenance->leave(str_repeat('b', 32));
            self::fail('Another update session unexpectedly removed maintenance mode.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('another update session', $exception->getMessage());
        }

        self::assertTrue($maintenance->active());
        $maintenance->leave(str_repeat('a', 32));
        self::assertFalse($maintenance->active());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMaintenanceAllowsOnlyUpdaterAndRecoveryLoginRequests(): void
    {
        \define('REGISTER_ADMIN_MODE', true);

        self::assertTrue(MaintenanceMode::isUpdateRequest(
            ['SCRIPT_NAME' => '/_admin/index.php'],
            ['entity' => 'Update'],
            [],
        ));
        self::assertTrue(MaintenanceMode::isUpdateRequest(
            ['SCRIPT_NAME' => '/_admin/index.php'],
            ['action' => 'login'],
            [],
        ));
        self::assertTrue(MaintenanceMode::isUpdateRequest(
            ['SCRIPT_NAME' => '/_admin/index.php'],
            ['action' => 'webauthn_recovery_login'],
            [],
        ));
        self::assertTrue(MaintenanceMode::isUpdateRequest(
            ['SCRIPT_NAME' => '/_admin/ajax.php'],
            ['action' => 'register_update_finish'],
            [],
        ));
        self::assertFalse(MaintenanceMode::isUpdateRequest(
            ['SCRIPT_NAME' => '/_admin/index.php'],
            ['entity' => 'Dashboard'],
            [],
        ));
        self::assertFalse(MaintenanceMode::isUpdateRequest(
            ['SCRIPT_NAME' => '/_admin/ajax.php'],
            ['action' => 'register_backup_create'],
            [],
        ));
    }
}
