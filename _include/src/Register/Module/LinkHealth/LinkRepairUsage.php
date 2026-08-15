<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use Register\Content\ContentId;

final readonly class LinkRepairUsage
{
    public function __construct(
        public ContentId $contentId,
        public int       $expectedRevision,
    ) {
        if ($expectedRevision < 1) {
            throw new \InvalidArgumentException('A repair usage revision must be positive.');
        }
    }
}
