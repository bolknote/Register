<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

interface ContentDeletionGuardInterface
{
    /** @return list<string> Human-readable reasons that prevent deletion. */
    public function violations(ContentId ...$contentIds): array;
}
