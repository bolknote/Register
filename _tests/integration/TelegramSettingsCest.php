<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Config\DynamicSecretStore;
use Register\Core\Framework\Container;
use Register\Core\Pdo\DbLayer;
use Register\Import\Telegram\TelegramBotConfigController;
use Register\Import\Telegram\TelegramSettings;
use Register\Module\BaseModuleInstaller;
use Register\Schema\SchemaManager;
use Register\Schema\SchemaMigrator;
use Symfony\Component\HttpFoundation\Request;

final class TelegramSettingsCest
{
    private const string URL = 'https://localhost/_admin/index.php?entity=Config&action=list';

    private const string BOT_TOKEN = '123456:' . 'abcdefghijklmnopqrstuvwxyz123456789';

    private const string BRIDGE_TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function settingsAreEditableValidatedAndSecretsStayPrivate(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->amOnPage(self::URL);
        $I->see('Telegram', '#settings-telegram-config');
        foreach ([TelegramSettings::BOT_TOKEN => self::BOT_TOKEN, TelegramSettings::BRIDGE_TOKEN => self::BRIDGE_TOKEN, TelegramSettings::RELAY_TOKEN => str_repeat('b', 64)] as $key => $secret) {
            $I->amOnPage(self::URL);
            $I->submitForm($this->form($key), ['value' => $secret]);
            $I->seeResponseCodeIs(200);
            $I->see('{"success":true}');
            /** @var DbLayer $db */
            $db = $I->grabAdminService(DbLayer::class);
            $I->assertSame(DynamicSecretStore::DATABASE_PLACEHOLDER, $db->select('value')->from('config')
                ->where('name = :name')->setParameter('name', $key)->execute()->result());
            /** @var DynamicConfigProvider $provider */
            $provider = $I->grabAdminService(DynamicConfigProvider::class);
            $I->assertSame($secret, $provider->get($key));
            $I->amOnPage(self::URL);
            $I->dontSee($secret);
            $I->assertSame('', $I->grabValueFrom($this->form($key) . ' input[name="value"]'));
            $I->see('Key saved', '[data-config-key="' . $key . '"]');
        }

        $I->submitForm($this->form(TelegramSettings::RELAY_URL), ['value' => 'http://relay.example']);
        $I->seeResponseCodeIs(422);
        $I->amOnPage(self::URL);
        $I->submitForm($this->form(TelegramSettings::RELAY_URL), ['value' => 'https://relay.example:8443']);
        $I->seeResponseCodeIs(200);
        $I->amOnPage(self::URL);

        $I->submitForm($this->form(TelegramSettings::DISCUSSION_ID), ['value' => '-42']);
        $I->seeResponseCodeIs(422);
        $I->amOnPage(self::URL);
        $I->submitForm($this->form(TelegramSettings::DISCUSSION_ID), ['value' => '-1000000000123']);
        $I->seeResponseCodeIs(200);
        $I->amOnPage(self::URL);
        $I->submitForm($this->form(TelegramSettings::OWNER_TELEGRAM_ID), ['value' => '8890000001']);
        $I->seeResponseCodeIs(200);
        $I->amOnPage(self::URL);
        $I->assertSame('8890000001', $I->grabValueFrom($this->form(TelegramSettings::OWNER_TELEGRAM_ID) . ' input[name="value"]'));
        /** @var DbLayer $db */
        $db = $I->grabAdminService(DbLayer::class);
        $authorId = (string)$db->select('id')->from('users')->where("login = 'author'")->execute()->result();
        $I->submitForm($this->form(TelegramSettings::AUTHOR_ID), ['value' => $authorId]);
        $I->seeResponseCodeIs(200);
        $I->amOnPage(self::URL);
        $I->assertSame($authorId, $I->grabValueFrom($this->form(TelegramSettings::AUTHOR_ID) . ' select[name="value"]'));
        $I->sendAjaxPostRequest('https://localhost/_admin/index.php?entity=Config&action=patch&field=value&name=' . TelegramSettings::AUTHOR_ID, ['value' => '999999']);
        $I->seeResponseCodeIs(422);
    }

    public function onlyTheConnectedBotCanReadScopeAndRotatedBridgeKey(\IntegrationTester $I): void
    {
        $I->setConfigValue(TelegramSettings::BOT_TOKEN, self::BOT_TOKEN);
        $I->setConfigValue(TelegramSettings::BRIDGE_TOKEN, self::BRIDGE_TOKEN);
        $I->setConfigValue(TelegramSettings::DISCUSSION_ID, '-1000000000123');
        $I->setConfigValue(TelegramSettings::CHANNEL_ID, '-1000000000111');
        $I->setConfigValue(TelegramSettings::OWNER_TELEGRAM_ID, '22');
        $I->setConfigValue(TelegramSettings::ENABLED, '1');
        $I->amOnPage('http://register.localhost' . TelegramBotConfigController::PATH);
        $I->seeResponseCodeIs(401);
        $I->dontSee(self::BRIDGE_TOKEN);
        /** @var TelegramBotConfigController $controller */
        $controller = $I->grabService(TelegramBotConfigController::class);
        $request = Request::create(TelegramBotConfigController::PATH, 'GET', server: ['HTTP_X_REGISTER_TELEGRAM_BOT_TOKEN' => self::BOT_TOKEN]);
        $response = $controller->handle($request);
        $I->assertSame(200, $response->getStatusCode());
        $I->assertStringContainsString('no-store', (string)$response->headers->get('Cache-Control'));

        $body = json_decode((string)$response->getContent(), true, 32, JSON_THROW_ON_ERROR);
        $I->assertTrue($body['config']['enabled']);
        $I->assertSame(22, $body['config']['ownerUserId']);
        $I->assertSame(self::BRIDGE_TOKEN, $body['config']['token']);
        $I->assertStringNotContainsString(self::BOT_TOKEN, (string)$response->getContent());
        $I->setConfigValue(TelegramSettings::ENABLED, '0');
        $I->assertFalse(json_decode((string)$controller->handle($request)->getContent(), true, 32, JSON_THROW_ON_ERROR)['config']['enabled']);
    }

    public function upgradesAnExistingBridgeWithoutOverwritingSavedSettings(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabAdminService(DbLayer::class);
        foreach (array_keys(TelegramSettings::DEFAULTS) as $name) {
            $db->delete('config')->where('name = :name')->setParameter('name', $name)->execute();
        }

        $schema = new SchemaManager($db, new Container(['telegram_import' => [
            'token' => self::BRIDGE_TOKEN, 'discussion_chat_id' => -1_000_000_000_123,
            'channel_chat_id' => -1_000_000_000_111, 'owner_telegram_user_id' => 22,
        ]]), $I->grabAdminService(BaseModuleInstaller::class), $I->grabAdminService(SchemaMigrator::class));
        $I->assertTrue($schema->ensureCurrent());
        /** @var DynamicConfigProvider $provider */
        $provider = $I->grabAdminService(DynamicConfigProvider::class);
        $provider->regenerate();

        $I->assertSame(self::BRIDGE_TOKEN, $provider->get(TelegramSettings::BRIDGE_TOKEN));
        $I->assertSame('1', $provider->get(TelegramSettings::ENABLED));
        $I->assertSame('22', $provider->get(TelegramSettings::OWNER_TELEGRAM_ID));
        $I->setConfigValue(TelegramSettings::ENABLED, '0');
        $I->assertFalse($schema->ensureCurrent());
        $I->assertSame('0', $provider->get(TelegramSettings::ENABLED));
    }

    private function form(string $key): string
    {
        return 'form[action="?entity=Config&action=patch&field=value&name=' . $key . '"]';
    }
}
