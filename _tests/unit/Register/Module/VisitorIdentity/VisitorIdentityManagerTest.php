<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\VisitorIdentity;

use Codeception\Test\Unit;
use Register\Module\VisitorIdentity\Manifest;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Register\Module\VisitorIdentity\VisitorIdentityRepository;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Pdo\DbLayer;

final class VisitorIdentityManagerTest extends Unit
{
    public function testRefreshesAStaleDynamicConfigCacheBeforeVerifyingToken(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $dbLayer = new DbLayer($pdo);
        $dbLayer->query('CREATE TABLE config (name TEXT PRIMARY KEY, value TEXT)');

        $secret = str_repeat('ab', 32);
        $dbLayer->query(
            'INSERT INTO config (name, value) VALUES (:name, :value)',
            [':name' => Manifest::SECRET_CONFIG_KEY, ':value' => $secret],
        );

        $configFile = tempnam(sys_get_temp_dir(), 'register_visitor_config_');
        if ($configFile === false) {
            throw new \RuntimeException('Unable to allocate a temporary configuration file.');
        }

        try {
            file_put_contents($configFile, "<?php return ['S2_SITE_NAME' => 'Stale cache'];");
            $provider = new DynamicConfigProvider($dbLayer, $configFile, false);
            self::assertSame('Stale cache', $provider->get('S2_SITE_NAME'));

            $manager   = new VisitorIdentityManager(new VisitorIdentityRepository($dbLayer), $provider, 'test', '');
            $visitorId = str_repeat('1', 32);
            $signature = hash_hmac('sha256', "register-visitor\0" . $visitorId, $secret);

            self::assertSame($visitorId, $manager->visitorIdFromToken($visitorId . '.' . $signature));
            self::assertSame($secret, $provider->get(Manifest::SECRET_CONFIG_KEY));
        } finally {
            @unlink($configFile);
        }
    }
}
