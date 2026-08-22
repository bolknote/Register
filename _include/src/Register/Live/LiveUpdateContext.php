<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Live;

use Register\Content\ContentId;
use Register\Core\Framework\StatefulServiceInterface;

/** Collects live regions while one server-rendered page is being built. */
final class LiveUpdateContext implements StatefulServiceInterface
{
    private ?int $cursor = null;

    /** @var array<string, true> */
    private array $regions = [];

    public function __construct(private readonly LiveUpdateRepository $repository)
    {
    }

    /** Samples the journal before page data is read so concurrent writes cannot be missed. */
    public function start(): void
    {
        $this->cursor ??= $this->repository->currentCursor();
    }

    public function subscribePosts(int $skip): string
    {
        if ($skip < 0) {
            throw new \InvalidArgumentException('A live post-feed offset cannot be negative.');
        }

        return $this->subscribe('posts:' . $skip);
    }

    public function subscribeComments(ContentId $contentId): string
    {
        return $this->subscribe('comments:' . (string)$contentId);
    }

    public function subscribeSiteTools(): string
    {
        return $this->subscribe('site-tools');
    }

    public function cursor(): ?int
    {
        return $this->regions === [] ? null : $this->cursor;
    }

    /** @return list<string> */
    public function regions(): array
    {
        return array_keys($this->regions);
    }

    #[\Override]
    public function clearState(): void
    {
        $this->cursor  = null;
        $this->regions = [];
    }

    private function subscribe(string $region): string
    {
        $this->start();
        $this->regions[$region] = true;

        return $region;
    }
}
