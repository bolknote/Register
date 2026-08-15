<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use Register\Content\ContentId;

final readonly class DiscoveredLink
{
    public function __construct(
        public NormalizedLink $link,
        public string         $originalHref,
        public int            $occurrenceCount,
        public ?ContentId     $localContentId = null,
    ) {
        if ($occurrenceCount < 1) {
            throw new \InvalidArgumentException('A discovered link must have at least one occurrence.');
        }
    }
}
