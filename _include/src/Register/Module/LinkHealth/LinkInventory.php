<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use Register\Content\ContentId;
use Register\Content\ContentRepository;
use S2\Cms\Queue\QueuePublisher;

final readonly class LinkInventory
{
    public function __construct(
        private ContentRepository       $contentRepository,
        private HtmlLinkExtractor       $htmlLinkExtractor,
        private LinkUrlNormalizer       $linkUrlNormalizer,
        private ContentPathResolver     $contentPathResolver,
        private LinkInventoryRepository $repository,
        private QueuePublisher          $queuePublisher,
    ) {
    }

    public function refreshPathIndex(): void
    {
        $this->contentPathResolver->refresh();
    }

    public function synchronize(ContentId $contentId, ?int $now = null, bool $refreshPathIndex = true): void
    {
        $now     ??= time();
        $content = $this->contentRepository->find($contentId);
        $revision = $this->repository->publishedRevision($contentId);
        if (!$content instanceof \Register\Content\ContentItem || $revision === null) {
            $this->repository->removeContent($contentId);
            return;
        }

        if ($refreshPathIndex) {
            $this->refreshPathIndex();
        }

        /** @var array<string, DiscoveredLink> $grouped */
        $grouped = [];
        foreach ($this->htmlLinkExtractor->extract($content->body) as $href) {
            $normalized = $this->linkUrlNormalizer->normalize($href, $content->path);
            if (!$normalized instanceof NormalizedLink) {
                continue;
            }

            $key = $normalized->kind->value . ':' . $normalized->url;
            if (isset($grouped[$key])) {
                $existing      = $grouped[$key];
                $grouped[$key] = new DiscoveredLink(
                    $existing->link,
                    $existing->originalHref,
                    $existing->occurrenceCount + 1,
                    $existing->localContentId,
                );
                continue;
            }

            $grouped[$key] = new DiscoveredLink(
                $normalized,
                $href,
                1,
                $normalized->kind === LinkKind::LOCAL
                    ? $this->contentPathResolver->resolve($normalized->url)
                    : null,
            );
        }

        $links = array_values($grouped);

        foreach ($this->repository->synchronize($contentId, $revision, $links, $now) as $target) {
            if ($target->isDue($now)) {
                $this->queuePublisher->publishIfAbsent(
                    LinkQueue::targetJobId($target->id),
                    LinkQueue::CHECK_CODE,
                    ['target_id' => $target->id],
                );
            }
        }
    }
}
