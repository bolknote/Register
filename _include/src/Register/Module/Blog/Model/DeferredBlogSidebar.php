<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

/** Leaves site-wide sidebar blocks outside complete page-cache snapshots. */
final class DeferredBlogSidebar
{
    public const string RECENT_COMMENTS = 'recent-comments';

    public const string RECENT_DISCUSSIONS = 'recent-discussions';

    private const string PREFIX = '<!-- register-deferred-blog-sidebar-v1:';

    /** @var list<string> */
    private const array SLOTS = [self::RECENT_COMMENTS, self::RECENT_DISCUSSIONS];

    public static function placeholder(string $slot): string
    {
        self::validateSlot($slot);

        return self::PREFIX . $slot . ' -->';
    }

    public static function existsIn(string $content): bool
    {
        return str_contains($content, self::PREFIX);
    }

    /** @param callable(string): string $renderer */
    public static function replace(string $content, callable $renderer): ?string
    {
        if (!self::existsIn($content)) {
            return null;
        }

        $changed = false;
        foreach (self::SLOTS as $slot) {
            $placeholder = self::placeholder($slot);
            if (!str_contains($content, $placeholder)) {
                continue;
            }

            $content = str_replace($placeholder, $renderer($slot), $content);
            $changed = true;
        }

        return $changed ? $content : null;
    }

    private static function validateSlot(string $slot): void
    {
        if (!\in_array($slot, self::SLOTS, true)) {
            throw new \InvalidArgumentException('Unknown deferred blog sidebar slot.');
        }
    }
}
