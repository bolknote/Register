<?php

declare(strict_types = 1);

namespace integration;

use Register\Core\Pdo\DbLayer;

final class PageEditorRecoveryCest
{
    public function pageRecoveryUsesTheCurrentAuthenticatedOwner(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $db = $I->grabAdminService(DbLayer::class);
        $pageId = (int)$db->select('id')->from('content')->where("content_type = 'page'")->execute()->result();
        foreach (['admin', 'editor'] as $login) {
            if ($login !== 'admin') {
                $I->logout();
                $I->login($login, $login);
            }

            $userId = (int)$db->select('id')->from('users')->where('login = :login')
                ->setParameter('login', $login)->execute()->result();
            $I->amOnPage('https://localhost/_admin/index.php?entity=Article&action=edit&id=' . $pageId);
            $I->seeResponseCodeIs(200);
            $config = json_decode((string)$I->grabAttributeFrom('[data-editor-config]', 'data-editor-config'), true, 512, JSON_THROW_ON_ERROR);
            $I->assertIsArray($config);
            $I->assertSame($userId, $config['recovery']['userId']);
            $I->assertSame('/', $config['recovery']['scope']);
        }
    }
}
