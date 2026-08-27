<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Content\ContentId;
use Register\Content\ContentType;

/** Keeps frequently changing view totals outside deterministic page snapshots. */
final class DeferredViewCount
{
    private const string PREFIX = '<!-- register-deferred-view-count:';

    private const string PATTERN = '/<!-- register-deferred-view-count:(post|page):([1-9][0-9]*) -->/D';

    private function __construct()
    {
        throw new \LogicException('Static deferred-counter helper cannot be instantiated.');
    }

    public static function placeholder(ContentId $contentId): string
    {
        return self::PREFIX . (string)$contentId . ' -->';
    }

    public static function existsIn(string $content): bool
    {
        return str_contains($content, self::PREFIX);
    }

    /** @return list<ContentId> */
    public static function contentIds(string $content): array
    {
        $matched = preg_match_all(self::PATTERN, $content, $matches, PREG_SET_ORDER);
        if ($matched === false) {
            throw new \RuntimeException('Unable to inspect deferred view counters.');
        }

        $contentIds = [];
        foreach ($matches as $match) {
            $contentId = new ContentId(ContentType::from($match[1]), (int)$match[2]);
            $contentIds[(string)$contentId] = $contentId;
        }

        return array_values($contentIds);
    }

    /** @param callable(ContentId): string $renderer */
    public static function replace(string $content, callable $renderer): ?string
    {
        return preg_replace_callback(
            self::PATTERN,
            static fn(array $match): string => $renderer(
                new ContentId(ContentType::from($match[1]), (int)$match[2]),
            ),
            $content,
        );
    }
}
