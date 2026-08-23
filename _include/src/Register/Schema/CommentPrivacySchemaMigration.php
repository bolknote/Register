<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Auth\PublicAuthSchema;
use Register\Comment\CommentSchema;
use Register\Core\Pdo\DbLayer;

/** Removes the obsolete public-email flags in schema generation 20. */
final readonly class CommentPrivacySchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 19;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 20;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        if ($dbLayer->fieldExists(CommentSchema::TABLE_NAME, 'show_email')) {
            $dbLayer->dropField(CommentSchema::TABLE_NAME, 'show_email');
        }

        if ($dbLayer->fieldExists(PublicAuthSchema::MAGIC_LINKS_TABLE, 'show_email')) {
            $dbLayer->dropField(PublicAuthSchema::MAGIC_LINKS_TABLE, 'show_email');
        }
    }
}
