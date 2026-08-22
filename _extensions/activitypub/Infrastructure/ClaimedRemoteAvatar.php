<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

final readonly class ClaimedRemoteAvatar
{
    /** @param list<string> $redirectChain */
    public function __construct(
        public int     $id,
        public string  $publicId,
        public int     $remoteActorId,
        public string  $sourceUrl,
        public string  $sourceUrlHash,
        public string  $publishedSourceHash,
        public string  $requestUrl,
        public int     $redirectCount,
        public array          $redirectChain,
        public int     $attemptCount,
        public int     $giveUpAt,
        public string  $claimToken,
        public string  $storageKey,
        public string  $contentHash,
        public int     $byteSize,
        public ?string $etag,
        public ?string $lastModified,
    ) {
        if ($id < 1
            || preg_match('/^[A-Za-z0-9_-]{22}$/D', $publicId) !== 1
            || $remoteActorId < 1
            || preg_match('/^[a-f0-9]{64}$/D', $sourceUrlHash) !== 1
            || ($publishedSourceHash !== '' && preg_match('/^[a-f0-9]{64}$/D', $publishedSourceHash) !== 1)
            || !str_starts_with($sourceUrl, 'https://')
            || !str_starts_with($requestUrl, 'https://')
            || $redirectCount < 0
            || $attemptCount < 1
            || $giveUpAt < 1
            || preg_match('/^[a-f0-9]{32}$/D', $claimToken) !== 1
            || ($storageKey !== '' && preg_match('/^[a-f0-9]{64}$/D', $contentHash) !== 1)
            || ($storageKey !== '' && $byteSize < 1)
        ) {
            throw new \InvalidArgumentException('A claimed remote avatar is invalid.');
        }
    }

    public function conditionalEtag(): ?string
    {
        return hash_equals($this->sourceUrlHash, $this->publishedSourceHash) ? $this->etag : null;
    }

    public function conditionalLastModified(): ?string
    {
        return hash_equals($this->sourceUrlHash, $this->publishedSourceHash) ? $this->lastModified : null;
    }
}
