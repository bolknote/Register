<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

/** Parameterized placeholders for cross-post fragments outside cached page shells. */
final class DeferredPostPageContext
{
    public const string AUTHOR = 'author';

    public const string SEE_ALSO = 'see-also';

    public const string BACK_FORWARD = 'back-forward';

    public const string HEAD_LINKS = 'head-links';

    public const string CALENDAR = 'calendar';

    private const string PREFIX = '<!-- register-deferred-post-context-v1:';

    private const string PATTERN = '#<!-- register-deferred-post-context-v1:(author|see-also|back-forward|head-links|calendar):([1-9][0-9]*) -->#';

    /** @var list<string> */
    private const array SLOTS = [
        self::AUTHOR,
        self::SEE_ALSO,
        self::BACK_FORWARD,
        self::HEAD_LINKS,
        self::CALENDAR,
    ];

    public static function placeholder(string $slot, int $postId): string
    {
        if (!\in_array($slot, self::SLOTS, true)) {
            throw new \InvalidArgumentException('Unknown deferred post-context slot.');
        }

        if ($postId <= 0) {
            throw new \InvalidArgumentException('A deferred post-context placeholder requires a positive post ID.');
        }

        return self::PREFIX . $slot . ':' . $postId . ' -->';
    }

    public static function existsIn(string $content): bool
    {
        return str_contains($content, self::PREFIX);
    }

    /** @param callable(string, int): string $renderer */
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

                return $renderer($match[1], (int)$match[2]);
            },
            $content,
        );

        if (!\is_string($result)) {
            throw new \RuntimeException('Unable to hydrate deferred post context.');
        }

        return $changed ? $result : null;
    }
}
