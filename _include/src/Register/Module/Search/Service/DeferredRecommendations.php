<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Service;

use Register\Content\ContentId;
use Register\Content\ContentType;

/** Keeps recommendation output outside complete content-response snapshots. */
final class DeferredRecommendations
{
    private const string PREFIX = '<!-- register-deferred-recommendations-v1:';

    private const string PATTERN = '#<!-- register-deferred-recommendations-v1:(post|page):([1-9][0-9]*) -->#';

    public static function placeholder(ContentId $contentId): string
    {
        if (!\in_array($contentId->type, [ContentType::POST, ContentType::PAGE], true)) {
            throw new \InvalidArgumentException('Recommendations require a post or page identifier.');
        }

        return self::PREFIX . (string)$contentId . ' -->';
    }

    public static function existsIn(string $content): bool
    {
        return str_contains($content, self::PREFIX);
    }

    /** @param callable(ContentId): string $renderer */
    public static function replace(string $content, callable $renderer): ?string
    {
        if (!self::existsIn($content)) {
            return null;
        }

        $changed = false;
        $result = preg_replace_callback(
            self::PATTERN,
            static function (array $match) use ($renderer, &$changed): string {
                $changed = true;

                return $renderer(ContentId::fromString($match[1] . ':' . $match[2]));
            },
            $content,
        );

        if (!\is_string($result)) {
            throw new \RuntimeException('Unable to hydrate deferred recommendations.');
        }

        return $changed ? $result : null;
    }
}
