<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

final class BackupSecurityCest
{
    public function testManualBackupRequiresPostCsrfAndCurrentPassword(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->seeResponseCodeIs(200);

        $I->amOnPage('https://localhost/_admin/index.php?entity=SystemStatus');
        $I->seeElement('form.backup-actions[method="post"] input[name="password"]');
        $csrfToken = $I->grabValueFrom('form.backup-actions input[name="csrf_token"]');
        $I->assertIsString($csrfToken);
        $I->assertNotSame('', $csrfToken);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=register_backup_download');
        $I->seeResponseCodeIs(405);

        $I->sendPost('https://localhost/_admin/ajax.php?action=register_backup_download', [
            'csrf_token' => $csrfToken,
            'password'   => 'incorrect-current-password',
        ]);
        $I->seeResponseCodeIs(403);
        $I->see('The current password is incorrect.');
    }
}
