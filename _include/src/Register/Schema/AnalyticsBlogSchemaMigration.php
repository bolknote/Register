<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Core\Pdo\DbLayer;
use Register\Module\Analytics\AnalyticsSchema;

/** Adds compact blog-content, goal, technology, and Web Vitals projections. */
final readonly class AnalyticsBlogSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 26;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 27;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        AnalyticsSchema::createBlogStorage($dbLayer);
    }
}
