<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

final readonly class FetchedRemoteActor
{
    /** @param list<string> $alsoKnownAs */
    public function __construct(
        public string  $actorUrl,
        public string  $actorType,
        public string  $preferredUsername,
        public string  $displayName,
        public string  $inboxUrl,
        public ?string $sharedInboxUrl,
        public string  $publicKeyId,
        public string  $publicKeyPem,
        public array          $alsoKnownAs,
        public string  $snapshotJson,
        public string  $snapshotHash,
        public int     $fetchedAt,
        public int     $expiresAt,
        public ?string $movedToUrl = null,
        public ?string $avatarUrl = null,
        public ?string $featuredUrl = null,
    ) {
        if (!str_starts_with($actorUrl, 'https://')
            || !\in_array($actorType, ['Person', 'Service', 'Organization', 'Application', 'Group'], true)
            || $preferredUsername === ''
            || \strlen($preferredUsername) > 255
            || \strlen($displayName) > 255
            || !str_starts_with($inboxUrl, 'https://')
            || ($sharedInboxUrl !== null && !str_starts_with($sharedInboxUrl, 'https://'))
            || !str_starts_with($publicKeyId, 'https://')
            || !str_starts_with($publicKeyPem, '-----BEGIN PUBLIC KEY-----')
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotHash) !== 1
            || ($movedToUrl !== null && (!str_starts_with($movedToUrl, 'https://') || $movedToUrl === $actorUrl))
            || ($avatarUrl !== null && !$this->validAvatarUrl($avatarUrl))
            || ($featuredUrl !== null && !$this->validFeaturedUrl($featuredUrl))
            || $fetchedAt < 1
            || $expiresAt <= $fetchedAt
        ) {
            throw new \InvalidArgumentException('A fetched remote ActivityPub actor is invalid.');
        }
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

    private function validFeaturedUrl(string $url): bool
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
