<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Ai\AiSettings;
use Register\Auth\PublicAuthSettings;
use Register\Module\BaseModuleInstaller;
use Register\Core\Framework\Container;
use Register\Core\Model\ExtensionCache;
use Register\Core\Pdo\DbLayer;

/**
 * Initializes a clean schema and applies only explicitly registered additive upgrades.
 * The same migration chain is used by the maintenance-mode release updater and by a manual
 * deployment that first reaches the normal application bootstrap.
 */
final readonly class SchemaManager
{
    public const string CONFIG_KEY = 'REGISTER_SCHEMA_GENERATION';

    public const int CURRENT_GENERATION = 19;

    /** Oldest installed generation accepted by the release updater. */
    public const int MINIMUM_UPGRADE_GENERATION = 15;

    private const array CONFIG_DEFAULTS = [
        'REGISTER_SITE_TAGLINE' => '',
        'REGISTER_SOCIAL_IMAGE' => '',
        AiSettings::PROVIDER_CONFIG_KEY => AiSettings::PROVIDER_DISABLED,
        AiSettings::API_KEY_CONFIG_KEY  => '',
        AiSettings::MODEL_CONFIG_KEY    => '',
        AiSettings::FOLDER_ID_CONFIG_KEY => '',
        AiSettings::CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY => '',
        AiSettings::GIGACHAT_SCOPE_CONFIG_KEY => AiSettings::GIGACHAT_SCOPE_PERSONAL,
        AiSettings::AUTO_ALT_CONFIG_KEY => '1',
        PublicAuthSettings::EMAIL_ENABLED_CONFIG_KEY => '0',
        PublicAuthSettings::VK_CLIENT_ID_CONFIG_KEY => '',
        PublicAuthSettings::YANDEX_CLIENT_ID_CONFIG_KEY => '',
        PublicAuthSettings::YANDEX_CLIENT_SECRET_CONFIG_KEY => '',
    ];

    public function __construct(
        private DbLayer             $dbLayer,
        private Container           $container,
        private BaseModuleInstaller $baseModuleInstaller,
        private SchemaMigrator      $schemaMigrator,
    ) {
    }

    /**
     * Initializes base modules for a fresh installation and upgrades a supported stale schema.
     *
     * @return bool Whether the fresh schema or its generation marker changed.
     */
    public function ensureCurrent(): bool
    {
        $currentGeneration = $this->currentGeneration();
        if ($currentGeneration === self::CURRENT_GENERATION) {
            return $this->ensureConfigDefaults();
        }

        if ($currentGeneration >= self::MINIMUM_UPGRADE_GENERATION
            && $currentGeneration < self::CURRENT_GENERATION
        ) {
            return $this->migrateTo(self::CURRENT_GENERATION);
        }

        if ($currentGeneration !== 0) {
            throw new \LogicException(\sprintf(
                'Register schema generation %d is incompatible with the supported generation %d; recreate the database or import its data explicitly.',
                $currentGeneration,
                self::CURRENT_GENERATION,
            ));
        }

        $this->baseModuleInstaller->installFresh($this->dbLayer, $this->container);
        $this->ensureConfigDefaults();
        $this->storeGeneration(self::CURRENT_GENERATION);

        $extensionCache = $this->container->getIfDefined(ExtensionCache::class);
        if ($extensionCache instanceof ExtensionCache) {
            $extensionCache->clearRoutesCache();
        }

        return true;
    }

    /** Applies the migration chain supplied by the newly installed release. */
    public function migrateTo(int $targetGeneration): bool
    {
        if ($targetGeneration !== self::CURRENT_GENERATION) {
            throw new \LogicException(\sprintf(
                'The running Register release can migrate only to schema generation %d.',
                self::CURRENT_GENERATION,
            ));
        }

        $currentGeneration = $this->currentGeneration();
        if ($currentGeneration === 0) {
            throw new \LogicException('A fresh database must be initialized by the installer, not the updater.');
        }

        if ($currentGeneration < self::MINIMUM_UPGRADE_GENERATION
            || $currentGeneration > self::CURRENT_GENERATION
        ) {
            throw new \LogicException(\sprintf(
                'Register schema generation %d is incompatible with the supported upgrade range %d-%d.',
                $currentGeneration,
                self::MINIMUM_UPGRADE_GENERATION,
                self::CURRENT_GENERATION,
            ));
        }

        $changed = $this->schemaMigrator->migrate(
            $currentGeneration,
            $targetGeneration,
            function (int $generation): void {
                $this->storeGeneration($generation);
            },
        );
        $changed = $this->ensureConfigDefaults() || $changed;
        if ($changed) {
            $extensionCache = $this->container->getIfDefined(ExtensionCache::class);
            if ($extensionCache instanceof ExtensionCache) {
                $extensionCache->clear();
            }
        }

        return $changed;
    }

    /** Adds newly introduced optional settings without changing the schema generation. */
    private function ensureConfigDefaults(): bool
    {
        $existingNames = array_flip($this->dbLayer
            ->select('name')
            ->from('config')
            ->execute()
            ->fetchColumn());
        $changed = false;

        foreach (self::CONFIG_DEFAULTS as $name => $value) {
            if (isset($existingNames[$name])) {
                continue;
            }

            $this->dbLayer
                ->insert('config')
                ->setValue('name', ':name')->setParameter('name', $name)
                ->setValue('value', ':value')->setParameter('value', $value)
                ->onConflictDoNothing('name')
                ->execute();
            $changed = true;
        }

        return $changed;
    }

    public function currentGeneration(): int
    {
        $result = $this->dbLayer
            ->select('value')
            ->from('config')
            ->where('name = :name')->setParameter('name', self::CONFIG_KEY)
            ->execute()
        ;
        $value = $result->result();

        if (!\is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new \UnexpectedValueException('Register schema generation is missing or invalid.');
        }

        return (int)$value;
    }

    private function storeGeneration(int $generation): void
    {
        $this->dbLayer
            ->upsert('config')
            ->setKey('name', ':name')->setParameter('name', self::CONFIG_KEY)
            ->setValue('value', ':value')->setParameter('value', (string)$generation)
            ->execute()
        ;
    }
}
