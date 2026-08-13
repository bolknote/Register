<?php
/**
 * Search
 *
 * Adds full-text search with English and Russian morphology.
 *
 * @copyright 2011-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   s2_search
 */

declare(strict_types = 1);

namespace s2_extensions\s2_search;

use S2\Cms\Extensions\ManifestInterface;
use S2\Cms\Extensions\ManifestTrait;
use S2\Cms\Framework\Container;
use S2\Cms\Pdo\DbLayer;
use S2\Rose\Storage\Database\PdoStorage;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use S2\Cms\Pdo\DbLayerException;

class Manifest implements ManifestInterface
{
    use ManifestTrait;

    #[\Override]
    public function getTitle(): string
    {
        return 'Search';
    }

    #[\Override]
    public function getAuthor(): string
    {
        return 'Roman Parpalak';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Full-text search with English and Russian morphology.';
    }

    #[\Override]
    public function getVersion(): string
    {
        return '2.0a1';
    }

    #[\Override]
    public function getInstallationNote(): ?string
    {
        return 'Do not forget to create search index after extension installation (Admin → Stats page).';
    }

    /**
     * @throws DbLayerException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    #[\Override]
    public function install(DbLayer $dbLayer, Container $container, ?string $currentVersion): void
    {
        $config = [
            'S2_SEARCH_QUICK'                 => '0',
            'S2_SEARCH_RECOMMENDATIONS_LIMIT' => '0',
        ];
        foreach ($config as $confName => $confValue) {
            $dbLayer
                ->insert('config')
                ->setValue('name', ':name')->setParameter('name', $confName)
                ->setValue('value', ':value')->setParameter('value', $confValue)
                ->onConflictDoNothing('name')
                ->execute()
            ;
        }

        // The extension is not installed yet, so we can't take the storage from the container directly
        $pdoStorage = Extension::PdoStorageFactory($container);
        $pdoStorage->erase();
    }

    /**
     * @throws DbLayerException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    #[\Override]
    public function uninstall(DbLayer $dbLayer, Container $container): void
    {
        if ($dbLayer->tableExists('config')) {
            $dbLayer->delete('config')
                ->where("name in ('S2_SEARCH_QUICK', 'S2_SEARCH_RECOMMENDATIONS_LIMIT')")
                ->execute()
            ;
        }

        $pdoStorage = $container->get(PdoStorage::class);
        $pdoStorage->drop();
    }
}
