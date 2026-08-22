<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

final readonly class FederationUrlGenerator
{
    public function __construct(
        private CanonicalOrigin   $origin,
        private CanonicalBasePath $basePath,
    ) {
    }

    public function sharedInbox(): string
    {
        return $this->resource('/activitypub/inbox');
    }

    public function actor(string $publicId): string
    {
        return $this->identifiedResource('/activitypub/actors/', $publicId);
    }

    public function actorInbox(string $actorPublicId): string
    {
        return $this->actor($actorPublicId) . '/inbox';
    }

    public function actorOutbox(string $actorPublicId): string
    {
        return $this->actor($actorPublicId) . '/outbox';
    }

    public function actorFollowers(string $actorPublicId): string
    {
        return $this->actor($actorPublicId) . '/followers';
    }

    public function actorFollowing(string $actorPublicId): string
    {
        return $this->actor($actorPublicId) . '/following';
    }

    public function actorFeatured(string $actorPublicId): string
    {
        return $this->actor($actorPublicId) . '/featured';
    }

    public function object(string $publicId): string
    {
        return $this->identifiedResource('/activitypub/objects/', $publicId);
    }

    public function objectReplies(string $publicId): string
    {
        return $this->object($publicId) . '/replies';
    }

    public function activity(string $publicId): string
    {
        return $this->identifiedResource('/activitypub/activities/', $publicId);
    }

    public function key(string $publicId): string
    {
        return $this->identifiedResource('/activitypub/keys/', $publicId);
    }

    public function nodeInfo(): string
    {
        return $this->resource('/nodeinfo/2.1');
    }

    public function resource(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('A federation resource path must start with a slash.');
        }

        return $this->origin->value . $this->basePath->value . $path;
    }

    private function identifiedResource(string $prefix, string $publicId): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $publicId) !== 1) {
            throw new \InvalidArgumentException('The ActivityPub public identifier is invalid.');
        }

        return $this->resource($prefix . $publicId);
    }
}
