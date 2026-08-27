<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Content\ContentSchema;
use Register\Core\Pdo\DbLayer;

/** Adds the covering index used by the published-author multiplicity lookup. */
final readonly class ContentAuthorIndexSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 22;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 23;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        $dbLayer->addIndex(
            ContentSchema::TABLE_NAME,
            'type_publication_author_idx',
            ['content_type', 'published', 'author_id'],
        );
    }
}
