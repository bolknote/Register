<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

use Psr\Log\LoggerInterface;
use s2_extensions\activitypub\Infrastructure\RemoteAvatarRepository;
use s2_extensions\activitypub\Media\RemoteAvatarQueue;
use s2_extensions\activitypub\Media\RemoteAvatarStorage;

/** Bounded refresh and derived-file retention work for shared-hosting shutdown runs. */
final readonly class RemoteAvatarMaintenanceService
{
    public function __construct(
        private RemoteAvatarRepository $repository,
        private RemoteAvatarQueue      $queue,
        private RemoteAvatarStorage    $storage,
        private LoggerInterface        $logger,
    ) {
    }

    public function scheduleDue(int $now): int
    {
        $affected = $this->repository->activateDue($now);
        if ($affected > 0) {
            $this->queue->wake($now + 1);
        }

        return $affected;
    }

    public function detachExpired(int $now): int
    {
        $storageKeys = $this->repository->detachExpiredAssets($now);
        foreach ($storageKeys as $storageKey) {
            try {
                $this->storage->remove($storageKey);
            } catch (\RuntimeException $exception) {
                $this->logger->warning('Unable to remove an expired remote avatar cache file.', [
                    'storage_key' => $storageKey,
                    'exception'   => $exception,
                ]);
            }
        }

        return \count($storageKeys);
    }
}
