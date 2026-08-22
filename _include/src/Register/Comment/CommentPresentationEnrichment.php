<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

/** Safe, extension-provided presentation metadata for one comment. */
final readonly class CommentPresentationEnrichment
{
    public function __construct(
        public int     $commentId,
        public ?string $localAvatarPath = null,
        public ?string $authorUrl = null,
        public ?string $sourceUrl = null,
        public string  $sourceLabel = '',
    ) {
        if ($commentId < 1) {
            throw new \InvalidArgumentException('A comment presentation identifier must be positive.');
        }

        if ($localAvatarPath !== null) {
            $this->validateLocalPath($localAvatarPath);
        }

        if ($authorUrl !== null) {
            $this->validateHttpsUrl($authorUrl, 'author');
        }

        if ($sourceUrl !== null) {
            $this->validateHttpsUrl($sourceUrl, 'source');
            if ($sourceLabel === '') {
                throw new \InvalidArgumentException('A comment source URL requires a label.');
            }
        } elseif ($sourceLabel !== '') {
            throw new \InvalidArgumentException('A comment source label requires a URL.');
        }

        if (\strlen($sourceLabel) > 40 || preg_match('/[\x00-\x1f\x7f]/', $sourceLabel) === 1) {
            throw new \InvalidArgumentException('A comment source label is invalid.');
        }
    }

    private function validateLocalPath(string $path): void
    {
        $parts = parse_url($path);
        $segments = explode('/', ltrim($path, '/'));
        $hasUnsafeSegment = false;
        foreach ($segments as $segment) {
            if ($segment === ''
                || preg_match('/%(?![0-9a-f]{2})/i', $segment) === 1
                || \in_array(strtolower(rawurldecode($segment)), ['.', '..'], true)
            ) {
                $hasUnsafeSegment = true;
                break;
            }
        }

        if (\strlen($path) > 2_048
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || !\is_array($parts)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $path) === 1
            || $hasUnsafeSegment
        ) {
            throw new \InvalidArgumentException('A comment avatar must use a bounded local application path.');
        }
    }

    private function validateHttpsUrl(string $url, string $kind): void
    {
        $parts = parse_url($url);
        if (\strlen($url) > 2_048
            || !\is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || str_contains($url, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
        ) {
            throw new \InvalidArgumentException('A comment ' . $kind . ' URL must be bounded credential-free HTTPS.');
        }
    }
}
