<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Domain;

final readonly class CollectionAnchor
{
    public function __construct(
        public int $timestamp,
        public int $id,
    ) {
        if ($timestamp < 0 || $id <= 0) {
            throw new \InvalidArgumentException('An ActivityPub collection anchor is invalid.');
        }
    }
}
