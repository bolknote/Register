<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment;

/** Encodes a safe, cacheable placeholder for a request-bound comment form. */
final class DeferredCommentForm
{
    private const string PREFIX = '<!-- register-deferred-comment-form-v1:';

    private const string PATTERN = '#<!-- register-deferred-comment-form-v1:([A-Za-z0-9_-]{1,342}) -->#';

    public static function placeholder(string $contentId): string
    {
        if ($contentId === '' || \strlen($contentId) > 256 || str_contains($contentId, "\0")) {
            throw new \InvalidArgumentException('A deferred comment form requires a valid content identifier.');
        }

        return self::PREFIX . rtrim(strtr(base64_encode($contentId), '+/', '-_'), '=') . ' -->';
    }

    public static function existsIn(string $content): bool
    {
        return str_contains($content, self::PREFIX);
    }

    /**
     * @param callable(string): string $renderer
     */
    public static function replace(string $content, callable $renderer): ?string
    {
        if (!self::existsIn($content)) {
            return null;
        }

        $count = 0;
        $result = preg_replace_callback(
            self::PATTERN,
            static function (array $matches) use ($renderer): string {
                $encoded = $matches[1];
                $padding = (4 - \strlen($encoded) % 4) % 4;
                $contentId = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), true);
                if (
                    $contentId === false
                    || $contentId === ''
                    || \strlen($contentId) > 256
                    || str_contains($contentId, "\0")
                ) {
                    throw new \UnexpectedValueException('A deferred comment form contains an invalid identifier.');
                }

                return $renderer($contentId);
            },
            $content,
            -1,
            $count,
        );
        if ($result === null) {
            throw new \RuntimeException('Unable to hydrate a deferred comment form.');
        }

        return $count > 0 ? $result : null;
    }
}
