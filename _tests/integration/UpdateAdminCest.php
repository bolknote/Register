<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

final class UpdateAdminCest
{
    public function rendersBootstrapInstructionsAndProtectsUploadActions(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Update');

        $I->seeResponseCodeIs(200);
        $I->see('Software update');
        $I->see('Self-update is unavailable');
        $I->see('Install the first updater-capable release manually.');
        $I->seeElement('[data-register-update]');
        $I->seeElement('script[src="/_admin/js/update.js"]');
        $I->seeElement('link[href="/_admin/css/update.css"]');

        $I->sendPost('https://localhost/_admin/ajax.php?action=register_update_start', [
            'csrf_token' => 'invalid',
            'filename'   => 'register-release.zip',
            'size'       => 100,
        ]);
        $I->seeResponseCodeIs(403);
        $I->see('The update request has expired.');
    }
}
