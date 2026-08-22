<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Controller;

use S2\Cms\Framework\ControllerInterface;
use s2_extensions\activitypub\Infrastructure\RemoteAvatarRepository;
use s2_extensions\activitypub\Media\RemoteAvatarStorage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Serves a small verified private-cache file without exposing or fetching its remote source. */
final readonly class RemoteAvatarController implements ControllerInterface
{
    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param null|\Closure(): int $clock */
    public function __construct(
        private RemoteAvatarRepository $repository,
        private RemoteAvatarStorage    $storage,
        ?\Closure                      $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        $publicId = $request->attributes->get('publicId');
        if (!\is_string($publicId)) {
            return $this->notFound();
        }

        $asset = $this->repository->findPublicAsset($publicId, ($this->clock)());
        if (!$asset instanceof \s2_extensions\activitypub\Infrastructure\RemoteAvatarAsset) {
            return $this->notFound();
        }

        try {
            $filename = $this->storage->path($asset->storageKey);
            $warning = null;
            $content = s2_call_without_warnings(static fn(): string|false => file_get_contents($filename), $warning);
            unset($warning);
        } catch (\InvalidArgumentException) {
            return $this->notFound();
        }

        if (!\is_string($content)
            || \strlen($content) !== $asset->byteSize
            || !hash_equals($asset->contentHash, hash('sha256', $content))
        ) {
            return $this->notFound();
        }

        $response = new Response($request->isMethod(Request::METHOD_HEAD) ? '' : $content, Response::HTTP_OK, [
            'Content-Type'                  => $asset->contentType,
            'Content-Length'                => (string)$asset->byteSize,
            'Content-Disposition'           => 'inline',
            'Cache-Control'                 => 'public, max-age=3600, stale-if-error=86400',
            'X-Content-Type-Options'        => 'nosniff',
            'Cross-Origin-Resource-Policy'  => 'same-origin',
            'Content-Security-Policy'       => "default-src 'none'; sandbox",
            'X-Robots-Tag'                  => 'noindex, nofollow, noarchive',
        ]);
        $response->setEtag($asset->contentHash, false);
        $response->setLastModified((new \DateTimeImmutable())->setTimestamp($asset->fetchedAt));
        $response->isNotModified($request);

        return $response;
    }

    private function notFound(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND, [
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag'           => 'noindex, nofollow, noarchive',
        ]);
    }
}
