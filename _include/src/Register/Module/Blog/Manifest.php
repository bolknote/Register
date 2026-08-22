<?php
/**
 * Blog
 *
 * Provides the blog functionality for Register.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog;

use Register\Module\BaseModuleInstallerInterface;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;
use Register\Core\Pdo\DbLayerException;

class Manifest implements BaseModuleInstallerInterface
{
    #[\Override]
    public function getTitle(): string
    {
        return 'Blog';
    }

    #[\Override]
    public function getAuthor(): string
    {
        return 'Roman Parpalak and Evgeny Stepanischev';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Adds posts, archives, tags, RSS, and comments.';
    }

    #[\Override]
    public function getVersion(): string
    {
        return '2.0a3';
    }

    /**
     * @throws DbLayerException
     */
    #[\Override]
    public function installFresh(DbLayer $dbLayer): void
    {
        // Add extension options to the config table
        $config = [
            'REGISTER_BLOG_TITLE' => 'My blog',
            'REGISTER_SITE_TAGLINE' => '',
        ];

        foreach ($config as $confName => $confValue) {
            $dbLayer->insert('config')
                ->setValue('name', ':name')->setParameter('name', $confName)
                ->setValue('value', ':value')->setParameter('value', $confValue)
                ->onConflictDoNothing('name')
                ->execute()
            ;
        }

        // A field in tags table for important tags displaying
        $dbLayer->addField('tags', 'register_blog_important', SchemaBuilderInterface::TYPE_BOOLEAN, null, false, 0);

        $dbLayer->addIndex('tags', 'register_blog_important_idx', ['register_blog_important']);
    }
}
