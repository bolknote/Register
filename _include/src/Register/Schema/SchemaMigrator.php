<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Module\BaseModuleInstaller;
use Register\Module\BaseModuleRegistry;
use S2\Cms\Framework\Container;
use S2\Cms\Model\ExtensionCache;
use S2\Cms\Pdo\DbLayer;

/**
 * Owns the single schema revision for all mandatory Register modules.
 *
 * Optional modules keep their own migration state. The legacy base-module rows are consumed only
 * when an existing S2 database is adopted and are removed after the first Register migration.
 */
final readonly class SchemaMigrator
{
    public const string CONFIG_KEY = 'REGISTER_SCHEMA_REVISION';

    public const int LATEST_REVISION = 4;

    public function __construct(
        private DbLayer             $dbLayer,
        private Container           $container,
        private BaseModuleInstaller $baseModuleInstaller,
        private BaseModuleRegistry  $baseModuleRegistry,
    ) {
    }

    /**
     * @return bool Whether the database or its revision ledger changed.
     */
    public function migrate(): bool
    {
        $currentRevision = $this->currentRevision();
        if ($currentRevision < 0) {
            throw new \UnexpectedValueException('Register schema revision must be a non-negative integer.');
        }

        if ($currentRevision > self::LATEST_REVISION) {
            throw new \LogicException(\sprintf(
                'Register schema revision %d is newer than the supported revision %d.',
                $currentRevision,
                self::LATEST_REVISION,
            ));
        }

        $migrations = $this->migrations();

        $migrated = false;
        while ($currentRevision < self::LATEST_REVISION) {
            $nextRevision = $currentRevision + 1;
            $migration    = $migrations[$nextRevision]
                ?? throw new \LogicException(\sprintf('Unknown Register schema revision %d.', $nextRevision));
            $migration();
            $this->storeRevision($nextRevision);
            $currentRevision = $nextRevision;
            $migrated        = true;
        }

        if ($migrated) {
            $this->container->get(ExtensionCache::class)->clearRoutesCache();
        }

        return $migrated;
    }

    private function storeRevision(int $revision): void
    {
        $this->dbLayer
            ->upsert('config')
            ->setKey('name', ':name')->setParameter('name', self::CONFIG_KEY)
            ->setValue('value', ':value')->setParameter('value', (string)$revision)
            ->execute()
        ;
    }

    public function currentRevision(): int
    {
        $result = $this->dbLayer
            ->select('value')
            ->from('config')
            ->where('name = :name')->setParameter('name', self::CONFIG_KEY)
            ->execute()
        ;
        $value = $result->result();

        if (in_array($value, [false, null, ''], true)) {
            return 0;
        }

        if (!is_numeric($value) || (string)(int)$value !== (string)$value) {
            throw new \UnexpectedValueException('Register schema revision must be a non-negative integer.');
        }

        return (int)$value;
    }

    private function removeLegacyBaseModuleRows(): void
    {
        foreach ($this->baseModuleRegistry->ids() as $id) {
            $this->dbLayer
                ->delete('extensions')
                ->where('id = :id')->setParameter('id', $id)
                ->execute()
            ;
        }
    }

    private function migrateToRevisionOne(): void
    {
        $this->baseModuleInstaller->installFresh($this->dbLayer, $this->container);
        $this->removeLegacyBaseModuleRows();
    }

    private function migrateToRevisionTwo(): void
    {
        $manifestClass = $this->baseModuleRegistry->manifestClass(BaseModuleRegistry::ANALYTICS);
        (new $manifestClass())->install($this->dbLayer, $this->container, null);
    }

    private function migrateToRevisionThree(): void
    {
        // The search module moved into the Register namespace. The schema itself is unchanged;
        // advancing the ledger invalidates compiled routes that contain controller class names.
    }

    private function migrateToRevisionFour(): void
    {
        // The blog module moved into the Register namespace. The schema itself is unchanged;
        // advancing the ledger invalidates compiled routes that contain controller class names.
    }

    /** @return array<int, \Closure(): void> */
    private function migrations(): array
    {
        return [
            1 => function (): void {
                $this->migrateToRevisionOne();
            },
            2 => function (): void {
                $this->migrateToRevisionTwo();
            },
            3 => function (): void {
                $this->migrateToRevisionThree();
            },
            4 => function (): void {
                $this->migrateToRevisionFour();
            },
        ];
    }
}
