<?php
/**
 * Content reactions
 *
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Reactions;

use Register\Content\ContentSchema;
use Register\Module\BaseModuleInstallerInterface;
use Register\Module\VisitorIdentity\Manifest as VisitorIdentityManifest;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

final class Manifest implements BaseModuleInstallerInterface
{
    public const string TABLE_NAME = 'register_reaction';

    #[\Override]
    public function getTitle(): string
    {
        return 'Reactions';
    }

    #[\Override]
    public function getAuthor(): string
    {
        return 'Evgeny Stepanischev';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Browser-owned and imported aggregate emoji reactions for content and comments.';
    }

    #[\Override]
    public function getVersion(): string
    {
        return '1.2dev';
    }

    #[\Override]
    public function installFresh(DbLayer $dbLayer): void
    {
        $dbLayer->createTable(self::TABLE_NAME, static function (SchemaBuilderInterface $table): void {
            $table
                ->addInteger('content_id', true)
                ->addString('visitor_id', 32)
                ->addInteger('user_id', true, true, null)
                ->addString('reaction', 16)
                ->addInteger('created_at', true)
                ->addInteger('updated_at', true)
                ->setPrimaryKey(['content_id', 'visitor_id'])
                ->addIndex('content_reaction_idx', ['content_id', 'reaction'])
                ->addIndex('visitor_idx', ['visitor_id'])
                ->addIndex('user_content_idx', ['user_id', 'content_id'])
                ->addForeignKey(
                    'fk_content',
                    ['content_id'],
                    ContentSchema::TABLE_NAME,
                    ['id'],
                    'CASCADE',
                )
                ->addForeignKey(
                    'fk_visitor',
                    ['visitor_id'],
                    VisitorIdentityManifest::VISITOR_TABLE,
                    ['visitor_id'],
                    'CASCADE',
                )
                ->addForeignKey('fk_user', ['user_id'], 'users', ['id'], 'SET NULL')
            ;
        });

        ReactionAggregateSchema::create($dbLayer);
    }
}
