<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

use Register\Content\ContentId;
use Register\Content\ContentType;
use S2\Cms\Framework\StatefulServiceInterface;

/** Request-scoped barrier ensuring editor settings are stored before their one canonical projection. */
final class ContentProjectionStaging implements StatefulServiceInterface
{
    /** @var array<string, true> */
    private array $content = [];

    /** @var array<string, true> */
    private array $newContentTypes = [];

    public function defer(ContentId $contentId): void
    {
        $this->content[(string)$contentId] = true;
    }

    public function deferNew(ContentType $contentType): void
    {
        $this->newContentTypes[$contentType->value] = true;
    }

    public function isDeferred(ContentId $contentId): bool
    {
        return isset($this->content[(string)$contentId])
            || isset($this->newContentTypes[$contentId->type->value]);
    }

    public function release(ContentId $contentId): void
    {
        unset($this->content[(string)$contentId], $this->newContentTypes[$contentId->type->value]);
    }

    #[\Override]
    public function clearState(): void
    {
        $this->content         = [];
        $this->newContentTypes = [];
    }
}
