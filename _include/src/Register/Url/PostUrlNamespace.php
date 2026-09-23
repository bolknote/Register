<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Url;

/** Keeps every blog post below the stable /all/ public namespace. */
final class PostUrlNamespace
{
    public const string PREFIX = 'all/';

    public static function canonicalSlug(string $slug): string
    {
        return str_starts_with($slug, self::PREFIX) ? $slug : self::PREFIX . $slug;
    }

    public static function isCanonical(string $slug): bool
    {
        return str_starts_with($slug, self::PREFIX) && $slug !== self::PREFIX;
    }

    public static function localSlug(string $slug): ?string
    {
        if (!self::isCanonical($slug)) {
            return null;
        }

        return substr($slug, strlen(self::PREFIX));
    }
}
