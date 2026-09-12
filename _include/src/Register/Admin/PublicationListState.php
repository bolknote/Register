<?php

declare(strict_types = 1);

namespace Register\Admin;

/** Page publication state follows its published flag; the publication date is optional metadata. */
final class PublicationListState
{
    public static function sql(int $now): string
    {
        return "CASE WHEN published = 1 THEN 'published'"
            . " WHEN published = 0 AND scheduled_at > 0 AND scheduled_at <= {$now} THEN 'overdue'"
            . " WHEN published = 0 AND scheduled_at > {$now}"
            . " THEN 'scheduled' ELSE 'draft' END";
    }

    public static function dateSql(int $now): string
    {
        return "CASE WHEN (" . self::sql($now) . ") = 'draft' THEN NULL"
            . ' WHEN published = 0 THEN scheduled_at ELSE published_at END';
    }
}
