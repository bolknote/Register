<?php
/**
 * Blog
 *
 * Provides the blog functionality for Register.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog;

use S2\Cms\Extensions\ManifestInterface;
use S2\Cms\Extensions\ManifestTrait;
use S2\Cms\Framework\Container;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\SchemaBuilderInterface;
use S2\Cms\Pdo\DbLayerException;

class Manifest implements ManifestInterface
{
    use ManifestTrait;

    #[\Override]
    public function getTitle(): string
    {
        return 'Blog';
    }

    #[\Override]
    public function getAuthor(): string
    {
        return 'Roman Parpalak';
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
    public function install(DbLayer $dbLayer, Container $container, ?string $currentVersion): void
    {
        // Setup posts table
        if (!$dbLayer->tableExists('s2_blog_posts')) {
            $dbLayer->createTable('s2_blog_posts', function (SchemaBuilderInterface $table): void {
                $table
                    ->addIdColumn()
                    ->addInteger('create_time', true)
                    ->addString('display_date', 255)
                    ->addInteger('modify_time', true)
                    ->addInteger('revision', true, default: 1)
                    ->addString('title', 255)
                    ->addLongText('text', nullable: false)
                    ->addBoolean('published')
                    ->addBoolean('favorite')
                    ->addBoolean('commented', default: true)
                    ->addString('label', 255)
                    ->addString('url', 255)
                    ->addInteger('user_id', true, nullable: true, default: null)
                    ->addForeignKey(
                        'fk_user',
                        ['user_id'],
                        'users',
                        ['id'],
                        'SET NULL',
                    )
                    ->addIndex('url_idx', ['url'])
                    ->addIndex('create_time_published_idx', ['create_time', 'published'])
                    ->addIndex('id_published_idx', ['id', 'published'])
                    ->addIndex('favorite_idx', ['favorite'])
                    ->addIndex('label_idx', ['label'])
                ;
            });
        }

        // Add extension options to the config table
        $config = [
            'S2_BLOG_URL'   => '',
            'S2_BLOG_TITLE' => 'My blog',
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
        $dbLayer->addField('tags', 's2_blog_important', SchemaBuilderInterface::TYPE_BOOLEAN, null, false, 0);

        $dbLayer->addIndex('tags', 's2_blog_important_idx', ['s2_blog_important']);

        unset($currentVersion);
    }

    /**
     * @throws DbLayerException
     */
    #[\Override]
    public function uninstall(DbLayer $dbLayer, Container $container): void
    {
        throw new \LogicException('The Blog module is part of Register and cannot be uninstalled.');
    }
}
