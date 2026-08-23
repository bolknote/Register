<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Content\ContentSchema;
use Register\Content\ContentViewSchema;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

/** Adds social-card covers and privacy-preserving content counters in generation 19. */
final readonly class SocialEngagementSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 18;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 19;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        $dbLayer->addField(
            ContentSchema::TABLE_NAME,
            'social_image',
            SchemaBuilderInterface::TYPE_STRING,
            2048,
            false,
            '',
            'meta_description',
        );
        ContentViewSchema::create($dbLayer);
    }
}
