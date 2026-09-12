<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Admin;

/** Publication states shared by the list, its filters and counts. */
final class BlogPostListState
{
    public static function sql(int $now): string
    {
        return "CASE WHEN published = 1 AND published_at <= {$now} THEN 'published'"
            . " WHEN (published = 0 AND scheduled_at > 0) OR (published = 1 AND published_at > {$now})"
            . " THEN 'scheduled' ELSE 'draft' END";
    }

    public static function dateSql(int $now): string
    {
        return "CASE WHEN (" . self::sql($now) . ") = 'draft' THEN COALESCE(NULLIF(updated_at, 0), created_at)"
            . ' ELSE COALESCE(NULLIF(scheduled_at, 0), published_at, created_at) END';
    }

    public static function filterSql(int $now): string
    {
        return '((' . self::sql($now) . ') = %1$s)'
            . " OR (%1\$s = 'unpublished' AND published = 0)"
            . " OR (%1\$s = 'overdue' AND published = 0 AND scheduled_at > 0 AND scheduled_at <= {$now})";
    }

    public static function filterValue(mixed $value): ?string
    {
        return \is_string($value) && \in_array($value, ['draft', 'scheduled', 'published', 'unpublished', 'overdue'], true)
            ? $value
            : null;
    }
}
