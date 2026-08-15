<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Ai\AiSettings;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Config\DynamicSecretStore;
use S2\Cms\Pdo\DbLayer;

final class SecretConfigCest
{
    public function testApiKeyLeavesDatabaseAndCacheAfterAdminSave(\IntegrationTester $I): void
    {
        $secretFile = \dirname(__DIR__) . '/_output/config.secrets.php';
        $cacheFile  = \dirname(__DIR__, 2) . '/_cache/test/cache_config.php';
        $formSelector = 'form[action="?entity=Config&action=patch&field=value&name='
            . AiSettings::API_KEY_CONFIG_KEY . '"]';

        try {
            $I->login('admin', 'admin');
            $I->amOnPage('https://localhost/_admin/index.php?entity=Config&action=list');
            $I->submitForm($formSelector, ['value' => 'integration-api-secret']);
            $I->seeResponseCodeIs(200);
            $I->see('{"success":true}');

            /** @var DynamicConfigProvider $provider */
            $provider = $I->grabAdminService(DynamicConfigProvider::class);
            /** @var DbLayer $dbLayer */
            $dbLayer = $I->grabAdminService(DbLayer::class);

            $I->assertSame('integration-api-secret', $provider->get(AiSettings::API_KEY_CONFIG_KEY));
            $I->assertSame(
                DynamicSecretStore::DATABASE_PLACEHOLDER,
                $dbLayer->select('value')
                    ->from('config')
                    ->where('name = :name')->setParameter('name', AiSettings::API_KEY_CONFIG_KEY)
                    ->execute()
                    ->result(),
            );

            $secrets = include $secretFile;
            $I->assertIsArray($secrets);
            $I->assertSame('integration-api-secret', $secrets[AiSettings::API_KEY_CONFIG_KEY] ?? null);
            $cache = include $cacheFile;
            $I->assertIsArray($cache);
            $I->assertSame(
                DynamicSecretStore::DATABASE_PLACEHOLDER,
                $cache[AiSettings::API_KEY_CONFIG_KEY] ?? null,
            );

            $I->amOnPage('https://localhost/_admin/index.php?entity=Config&action=list');
            $I->see('Key saved', '[data-config-key="' . AiSettings::API_KEY_CONFIG_KEY . '"]');

            $I->submitForm($formSelector, ['value' => '']);
            $I->seeResponseCodeIs(200);
            $I->assertSame('', $provider->get(AiSettings::API_KEY_CONFIG_KEY));
            $I->assertSame([], include $secretFile);
        } finally {
            if (is_file($secretFile)) {
                unlink($secretFile);
            }
        }
    }
}
