<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Reactions;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

/** Adds authenticated-user attribution to browser-owned reactions. */
final class ReactionUserSchema
{
    public static function create(DbLayer $dbLayer): void
    {
        $dbLayer->addField(
            Manifest::TABLE_NAME,
            'user_id',
            SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER,
            null,
            true,
        );
        $dbLayer->addIndex(Manifest::TABLE_NAME, 'user_content_idx', ['user_id', 'content_id']);
        $dbLayer->addForeignKey(
            Manifest::TABLE_NAME,
            'fk_user',
            ['user_id'],
            'users',
            ['id'],
            'SET NULL',
        );
    }
}
