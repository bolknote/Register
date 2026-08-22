<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace integration;

use Register\Core\Config\DynamicSecretStore;
use Register\Core\Extensions\ExtensionManager;
use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\Infrastructure\ActivityPubSchema;
use Register\Extension\activitypub\Manifest;
use Register\Extension\activitypub\Security\ActivityPubSecret;

final class ActivityPubExtensionCest
{
    public function installsIdempotentlyWithoutPublishingAndPreservesIdentityData(\IntegrationTester $I): void
    {
        /** @var ExtensionManager $manager */
        $manager = $I->grabAdminService(ExtensionManager::class);
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabAdminService(DbLayer::class);

        $I->assertSame([], $manager->installExtension('activitypub'));
        $I->assertSame(
            Manifest::VERSION,
            $dbLayer->select('version')
                ->from('extensions')
                ->where('id = :id')->setParameter('id', 'activitypub')
                ->execute()
                ->result(),
        );
        foreach (ActivityPubSchema::tables() as $table) {
            $I->assertTrue($dbLayer->tableExists($table), 'Missing ActivityPub table: ' . $table);
        }

        $state = $dbLayer->select('*')
            ->from(ActivityPubSchema::STATE_TABLE)
            ->where('id = :id')->setParameter('id', 'installation')
            ->execute()
            ->fetchAssoc()
        ;
        $I->assertIsArray($state);
        $I->assertSame('installed', $state['lifecycle_state']);
        $I->assertNull($state['canonical_origin']);
        $I->assertNull($state['activated_at']);
        $I->assertSame(0, (int)$dbLayer->select('COUNT(*)')->from(ActivityPubSchema::ACTOR_TABLE)->execute()->result());
        $I->assertSame(0, (int)$dbLayer->select('COUNT(*)')->from(ActivityPubSchema::ACTIVITY_TABLE)->execute()->result());

        /** @var DynamicSecretStore $secretStore */
        $secretStore = $I->grabAdminService(DynamicSecretStore::class);
        $masterKey   = $secretStore->getExtensionPrivate(ActivityPubSecret::MASTER_KEY);
        $I->assertIsString($masterKey);
        $I->assertSame(43, \strlen($masterKey));
        $I->assertSame(
            0,
            (int)$dbLayer->select('COUNT(*)')
                ->from('config')
                ->where('name = :name')->setParameter('name', ActivityPubSecret::MASTER_KEY)
                ->execute()
                ->result(),
        );

        // Re-running the same installer neither resets state nor rotates identity material.
        $I->assertSame([], $manager->installExtension('activitypub'));
        $I->assertSame($masterKey, $secretStore->getExtensionPrivate(ActivityPubSecret::MASTER_KEY));
        $I->assertSame(1, (int)$dbLayer->select('COUNT(*)')->from(ActivityPubSchema::STATE_TABLE)->execute()->result());

        $dbLayer->update(ActivityPubSchema::STATE_TABLE)
            ->set('lifecycle_state', ':state')->setParameter('state', 'active')
            ->where('id = :id')->setParameter('id', 'installation')
            ->execute()
        ;
        $I->assertSame(
            'Active ActivityPub identity must be decommissioned before disabling the module.',
            $manager->flipExtension('activitypub'),
        );
        $I->expectThrowable(
            \RuntimeException::class,
            static fn() => $manager->uninstallExtension('activitypub'),
        );

        $dbLayer->update(ActivityPubSchema::STATE_TABLE)
            ->set('lifecycle_state', ':state')->setParameter('state', 'installed')
            ->where('id = :id')->setParameter('id', 'installation')
            ->execute()
        ;
        $I->assertNull($manager->flipExtension('activitypub'));
        $I->assertNull($manager->flipExtension('activitypub'));
        $I->assertNull($manager->uninstallExtension('activitypub'));
        $I->assertTrue($dbLayer->tableExists(ActivityPubSchema::ACTOR_KEY_TABLE));
        $I->assertSame($masterKey, $secretStore->getExtensionPrivate(ActivityPubSecret::MASTER_KEY));
        $I->assertSame(
            0,
            (int)$dbLayer->select('COUNT(*)')
                ->from('extensions')
                ->where('id = :id')->setParameter('id', 'activitypub')
                ->execute()
                ->result(),
        );
    }
}
