<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Ai\AiSettings;
use Register\Module\BaseModuleInstaller;
use S2\Cms\Framework\Container;
use S2\Cms\Model\ExtensionCache;
use S2\Cms\Pdo\DbLayer;

/**
 * Guards the one clean schema generation supported by this pre-release product.
 *
 * Register deliberately has no in-place product migrations yet. Importing data from S2 or an
 * earlier Register generation is a separate, explicit operation.
 */
final readonly class SchemaManager
{
    public const string CONFIG_KEY = 'REGISTER_SCHEMA_GENERATION';

    public const int CURRENT_GENERATION = 9;

    private const array CONFIG_DEFAULTS = [
        AiSettings::PROVIDER_CONFIG_KEY => AiSettings::PROVIDER_DISABLED,
        AiSettings::API_KEY_CONFIG_KEY  => '',
        AiSettings::MODEL_CONFIG_KEY    => '',
    ];

    public function __construct(
        private DbLayer             $dbLayer,
        private Container           $container,
        private BaseModuleInstaller $baseModuleInstaller,
    ) {
    }

    /**
     * Initializes base modules for a fresh installation and rejects every stale schema.
     *
     * @return bool Whether the fresh schema or its generation marker changed.
     */
    public function ensureCurrent(): bool
    {
        $currentGeneration = $this->currentGeneration();
        if ($currentGeneration === self::CURRENT_GENERATION) {
            return $this->ensureConfigDefaults();
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
