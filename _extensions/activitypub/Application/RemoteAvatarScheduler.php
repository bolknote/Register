<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

use s2_extensions\activitypub\Infrastructure\RemoteAvatarRepository;
use s2_extensions\activitypub\Media\RemoteAvatarQueue;

final readonly class RemoteAvatarScheduler
{
    public function __construct(
        private RemoteAvatarRepository $repository,
        private RemoteAvatarQueue      $queue,
    ) {
    }

    public function synchronize(int $remoteActorId, ?string $avatarUrl, int $now): void
    {
        if ($this->repository->synchronizeSource($remoteActorId, $avatarUrl, $now)) {
            $this->queue->wake($now);
        }
    }
}
