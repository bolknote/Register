<?php
/**
 * Search
 *
 * Adds full-text search with English and Russian morphology.
 *
 * @copyright 2011-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search;

use Register\Module\ContainerAwareBaseModuleInstallerInterface;
use S2\Cms\Framework\Container;
use S2\Cms\Pdo\DbLayer;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use S2\Cms\Pdo\DbLayerException;

final class Manifest implements ContainerAwareBaseModuleInstallerInterface
{
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

    /**
     * @throws DbLayerException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    #[\Override]
    public function installFresh(DbLayer $dbLayer, Container $container): void
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
        $pdoStorage = Module::createPdoStorage($container);
        $pdoStorage->erase();
    }

}
