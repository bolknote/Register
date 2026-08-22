<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Ai\AiSettings;
use Register\Module\VisitorIdentity\Manifest as VisitorIdentityManifest;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Config\DynamicSecretStore;
use Register\Core\Pdo\DbLayer;

final class SecretConfigCest
{
    public function testManagedSecretsLeaveDatabaseAndCache(\IntegrationTester $I): void
    {
        $secretFile = \dirname(__DIR__) . '/_output/config.secrets.php';
        $cacheFile  = \dirname(__DIR__, 2) . '/_cache/test/register_config.php';
        $formSelector = 'form[action="?entity=Config&action=patch&field=value&name='
            . AiSettings::API_KEY_CONFIG_KEY . '"]';

        try {
            $I->login('admin', 'admin');
            $configUrl = 'https://localhost/_admin/index.php?entity=Config&action=list';
            $I->amOnPage($configUrl);
            $I->dontSeeElement($formSelector . ' input[name="current_password"]');

            /** @var DynamicConfigProvider $provider */
            $provider = $I->grabAdminService(DynamicConfigProvider::class);
            /** @var DbLayer $dbLayer */
            $dbLayer = $I->grabAdminService(DbLayer::class);
            $persistentSecrets = include $secretFile;
            $I->assertIsArray($persistentSecrets);
            $persistentParameters = ['REGISTER_ANTISPAM_SECRET', VisitorIdentityManifest::SECRET_CONFIG_KEY];
            foreach ($persistentParameters as $parameter) {
                $I->assertArrayHasKey($parameter, $persistentSecrets);
                $I->assertSame(
                    DynamicSecretStore::DATABASE_PLACEHOLDER,
                    $dbLayer->select('value')
                        ->from('config')
                        ->where('name = :name')->setParameter('name', $parameter)
                        ->execute()
                        ->result(),
                );
            }

            $I->submitForm($formSelector, [
                'value' => 'integration-api-secret',
            ]);
            $I->seeResponseCodeIs(200);
            $I->see('{"success":true}');

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
            foreach ($persistentParameters as $parameter) {
                $I->assertSame(DynamicSecretStore::DATABASE_PLACEHOLDER, $cache[$parameter] ?? null);
            }

            $I->assertSame(
                DynamicSecretStore::DATABASE_PLACEHOLDER,
                $cache[AiSettings::API_KEY_CONFIG_KEY] ?? null,
            );

            $I->amOnPage($configUrl);
            $I->see('Key saved', '[data-config-key="' . AiSettings::API_KEY_CONFIG_KEY . '"]');

            $I->submitForm($formSelector, [
                'value' => '',
            ]);
            $I->seeResponseCodeIs(200);
            $I->assertSame('', $provider->get(AiSettings::API_KEY_CONFIG_KEY));
            $remainingSecrets = include $secretFile;
            $I->assertIsArray($remainingSecrets);
            $I->assertArrayNotHasKey(AiSettings::API_KEY_CONFIG_KEY, $remainingSecrets);
            $I->assertSame($persistentSecrets, $remainingSecrets);
        } finally {
            if (is_file($secretFile)) {
                chmod($secretFile, 0600);
            }
        }
    }
}
