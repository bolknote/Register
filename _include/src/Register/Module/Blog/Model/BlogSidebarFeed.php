<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

/** Path-independent data for a deferred, independently cached blog sidebar block. */
final readonly class BlogSidebarFeed
{
    /** @param list<array<mixed>> $items */
    public function __construct(
        public array $items,
        public ?int  $validUntil = null,
    ) {
        if ($validUntil !== null && $validUntil < 1) {
            throw new \InvalidArgumentException('A sidebar cache boundary must be a positive timestamp.');
        }
    }

    public function isFreshAt(int $timestamp): bool
    {
        return $this->validUntil === null || $timestamp < $this->validUntil;
    }
}
