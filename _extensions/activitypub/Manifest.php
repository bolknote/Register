<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub;

use Register\Core\Config\DynamicSecretParameterRegistry;
use Register\Core\Config\DynamicSecretStore;
use Register\Core\Extensions\ManifestInterface;
use Register\Core\Extensions\ExtensionDisableGuardInterface;
use Register\Core\Framework\Container;
use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Infrastructure\ActivityPubSchema;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Security\ActivityPubSecret;

final class Manifest implements ExtensionDisableGuardInterface, ManifestInterface
{
    public const string VERSION = '0.1.0';

    public const string PROTOCOL_PROFILE = 'register-activitypub-v1';

    private const int DECOMMISSION_RETENTION_SECONDS = 90 * 24 * 60 * 60;

    #[\Override]
    public function getTitle(): string
    {
        return 'ActivityPub federation';
    }

    #[\Override]
    public function getAuthor(): string
    {
        return 'Evgeny Stepanischev';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'First-party ActivityPub actors, delivery, inbox processing, and moderation.';
    }

    #[\Override]
    public function getVersion(): string
    {
        return self::VERSION;
    }

    /** @return list<string> */
    #[\Override]
    public function getDependencies(): array
    {
        return [];
    }

    #[\Override]
    public function getInstallationNote(): string
    {
        return 'Installation creates private federation storage but does not publish an actor. Activation is a separate verified operation.';
    }

    #[\Override]
    public function install(DbLayer $dbLayer, Container $container, ?string $currentVersion): void
    {
        if ($currentVersion !== null && version_compare($currentVersion, self::VERSION, '>')) {
            throw new \RuntimeException('Downgrading the ActivityPub storage format is not supported.');
        }

        ActivityPubSchema::install($dbLayer);
        $this->registerMasterSecret($container);
        $container->get(DynamicSecretStore::class)->getOrCreateExtensionPrivate(ActivityPubSecret::MASTER_KEY);
    }

    #[\Override]
    public function getUninstallationNote(): string
    {
        return 'Uninstalling keeps federation identities, tombstones, queues, and private keys. Active federation must be decommissioned first.';
    }

    #[\Override]
    public function uninstall(DbLayer $dbLayer, Container $container): void
    {
        $reason = $this->getDisableBlockReason($dbLayer, $container);
        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }

        // Data and key material are intentionally preserved. Permanent erasure is a separately
        // authenticated operation and cannot be triggered by the generic extension manager.
    }

    /** @suppress PhanUnusedPublicFinalMethodParameter Required by the disable-guard contract. */
    #[\Override]
    public function getDisableBlockReason(DbLayer $dbLayer, Container $container): ?string
    {
        unset($container);

        $stateRepository = new FederationStateRepository($dbLayer);
        $state           = $stateRepository->lifecycleState();
        if (in_array($state, [FederationLifecycleState::ACTIVE, FederationLifecycleState::PAUSED, FederationLifecycleState::DECOMMISSIONING], true)
        ) {
            return 'Active ActivityPub identity must be decommissioned before disabling the module.';
        }

        if ($state !== FederationLifecycleState::DECOMMISSIONED) {
            return null;
        }

        $decommissionedAt = $stateRepository->decommissionedAt();
        if ($decommissionedAt === null || $decommissionedAt > time() - self::DECOMMISSION_RETENTION_SECONDS) {
            return 'ActivityPub tombstone retention has not elapsed after decommissioning.';
        }

        return null;
    }

    private function registerMasterSecret(Container $container): void
    {
        $container->get(DynamicSecretParameterRegistry::class)
            ->registerExtensionPrivate(ActivityPubSecret::MASTER_KEY)
        ;
    }
}
