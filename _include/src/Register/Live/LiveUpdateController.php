<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Live;

use Register\Comment\ContentCommentRenderer;
use Register\Content\ContentId;
use Register\Content\ContentRepository;
use Register\Content\ContentType;
use Register\Module\Blog\Model\PostFeedRenderer;
use Register\Auth\PublicAuthRenderer;
use Register\Core\Framework\ControllerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Returns all changed regions for an open page in one polling request. */
final readonly class LiveUpdateController implements ControllerInterface
{
    private const int BATCH_SIZE = 256;

    private const int MAX_REGIONS = 8;

    public function __construct(
        private LiveUpdateRepository   $repository,
        private PostFeedRenderer       $postFeedRenderer,
        private ContentCommentRenderer $commentRenderer,
        private ContentRepository      $contentRepository,
        private LiveFragmentRenderer   $fragmentRenderer,
        private PublicAuthRenderer     $publicAuthRenderer,
    ) {
    }

    #[\Override]
    public function handle(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $cursor = $this->cursor($request);
        if ($cursor === null) {
            return $this->error('Invalid live-update cursor.');
        }

        $regions = $this->regions($request);
        if ($regions === null || $regions === []) {
            return $this->error('At least one valid live region is required.');
        }

        $updates = $this->repository->findAfter($cursor, self::BATCH_SIZE + 1);
        $more    = \count($updates) > self::BATCH_SIZE;
        if ($more) {
            $updates = \array_slice($updates, 0, self::BATCH_SIZE);
        }

        $nextCursor = $updates === [] ? $cursor : $updates[\count($updates) - 1]->cursor;
        $patches     = [];

        foreach ($regions as $region) {
            if (!$this->regionChanged($region, $updates)) {
                continue;
            }

            $patches[$region] = $this->fragmentRenderer->render(
                $this->renderRegion($region, $request),
            );
        }

        $response = new JsonResponse([
            'cursor'  => $nextCursor,
            'patches' => $patches === [] ? new \stdClass() : $patches,
            'more'    => $more,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function cursor(Request $request): ?int
    {
        $value = $request->query->get('cursor');
        if (!\is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }

        $cursor = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $cursor === false ? null : $cursor;
    }

    /** @return list<string>|null */
    private function regions(Request $request): ?array
    {
        $value = $request->query->all()['region'] ?? [];
        if (\is_string($value)) {
            $value = [$value];
        }

        if (!\is_array($value) || \count($value) > self::MAX_REGIONS) {
            return null;
        }

        $regions = [];
        foreach ($value as $region) {
            if (!\is_string($region) || !$this->validRegion($region)) {
                return null;
            }

            $regions[$region] = true;
        }

        return array_keys($regions);
    }

    private function validRegion(string $region): bool
    {
        return preg_match('/^posts:(0|[1-9][0-9]{0,6})$/D', $region) === 1
            || preg_match('/^comments:(page|post):[1-9][0-9]*$/D', $region) === 1
            || $region === 'site-account';
    }

    /** @param list<LiveUpdate> $updates */
    private function regionChanged(string $region, array $updates): bool
    {
        if ($region === 'site-account') {
            foreach ($updates as $update) {
                if ($update->topic === LiveUpdateRepository::TOPIC_COMMENTS) {
                    return true;
                }
            }

            return false;
        }

        if (str_starts_with($region, 'posts:')) {
            foreach ($updates as $update) {
                if ($update->contentId->type === ContentType::POST) {
                    return true;
                }
            }

            return false;
        }

        $contentId = ContentId::fromString(substr($region, \strlen('comments:')));
        foreach ($updates as $update) {
            if ($update->contentId->equals($contentId)) {
                return true;
            }
        }

        return false;
    }

    private function renderRegion(string $region, Request $request): string
    {
        if ($region === 'site-account') {
            return $this->publicAuthRenderer->renderAccount($request, true);
        }

        if (preg_match('/^posts:([0-9]+)$/D', $region, $matches) === 1) {
            return $this->postFeedRenderer->render((int)$matches[1], $request)->html;
        }

        $contentId = ContentId::fromString(substr($region, \strlen('comments:')));
        $content   = $this->contentRepository->find($contentId);
        if (!$content instanceof \Register\Content\ContentItem) {
            return '<div class="live-comments-region" data-live-region="'
                . register_htmlencode($region)
                . '"></div>';
        }

        if (!$content->commentsEnabled) {
            return '<div class="live-comments-region" data-live-region="'
                . register_htmlencode($region)
                . '"></div>';
        }

        return $this->commentRenderer->renderRegion($contentId, $request, $content->path);
    }

    private function error(string $message): JsonResponse
    {
        $response = new JsonResponse(['error' => $message], Response::HTTP_BAD_REQUEST);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
