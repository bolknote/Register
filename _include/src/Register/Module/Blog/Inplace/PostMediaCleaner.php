<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Inplace;

use Register\Url\UrlHistoryService;

/** Keeps failed file removals registered so ordinary garbage collection can retry them. */
final readonly class PostMediaCleaner
{
    public function __construct(
        private PostMediaRepository $repository,
        private PostInplaceMediaStorage $storage,
        private UrlHistoryService $urlHistory,
    ) {
    }

    public function deleteUnused(int $mediaId): bool
    {
        return $this->urlHistory->run(function () use ($mediaId): bool {
            // A queued cleanup candidate may have been renamed or published.
            // Read its current path under the same mutex as editorial writes.
            $media = $this->repository->find($mediaId);
            if ($media === null || !$this->repository->deleteUnused($mediaId)) {
                return false;
            }

            // Keep the registry deletion uncommitted until unlink succeeds.
            // A filesystem error rolls it back without undoing the saved post.
            $this->storage->delete((string)$media['storage_path']);

            return true;
        });
    }
}
