<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

final readonly class RemoteActor
{
    /** @param list<string> $alsoKnownAs */
    public function __construct(
        public int     $id,
        public string  $actorUrl,
        public string  $actorType,
        public string  $preferredUsername,
        public string  $displayName,
        public string  $inboxUrl,
        public ?string $sharedInboxUrl,
        public string  $publicKeyId,
        public string  $publicKeyPem,
        public array          $alsoKnownAs,
        public string  $state,
        public int     $failureCount,
        public int     $fetchedAt,
        public int     $expiresAt,
        public ?string $movedToUrl = null,
        public ?int    $movedAt = null,
        public ?string $avatarUrl = null,
        public ?string $featuredUrl = null,
    ) {
        if ($id < 1
            || !\in_array($actorType, ['Person', 'Service', 'Organization', 'Application', 'Group'], true)
            || $preferredUsername === ''
            || \strlen($preferredUsername) > 255
            || \strlen($displayName) > 255
            || !str_starts_with($actorUrl, 'https://')
            || !str_starts_with($inboxUrl, 'https://')
            || ($sharedInboxUrl !== null && !str_starts_with($sharedInboxUrl, 'https://'))
            || !str_starts_with($publicKeyId, 'https://')
            || !str_starts_with($publicKeyPem, '-----BEGIN PUBLIC KEY-----')
            || !\in_array($state, ['active', 'moved', 'gone', 'blocked'], true)
            || ($movedToUrl !== null && (!str_starts_with($movedToUrl, 'https://') || $movedToUrl === $actorUrl))
            || ($avatarUrl !== null && !$this->validAvatarUrl($avatarUrl))
            || ($featuredUrl !== null && !$this->validCollectionUrl($featuredUrl))
            || ($state === 'moved' && $movedToUrl === null)
            || (($movedToUrl === null) !== ($movedAt === null))
            || ($movedAt !== null && $movedAt < 1)
            || $failureCount < 0
            || $fetchedAt < 1
            || $expiresAt < 1
        ) {
            throw new \InvalidArgumentException('A cached remote ActivityPub actor is invalid.');
        }
    }

    public function cacheIsFresh(int $now): bool
    {
        return \in_array($this->state, ['active', 'moved'], true) && $this->expiresAt > $now;
    }

    private function validAvatarUrl(string $url): bool
    {
        $parts = parse_url($url);

        return \strlen($url) <= 2_048
            && \is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && \is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment'])
            && !str_contains($url, '\\')
            && preg_match('/[\x00-\x20\x7f]/', $url) !== 1;
    }

    private function validCollectionUrl(string $url): bool
    {
        $parts = parse_url($url);

        return \strlen($url) <= 2_048
            && \is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && \is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !str_contains($url, '\\')
            && preg_match('/[\x00-\x20\x7f]/', $url) !== 1;
    }
}
