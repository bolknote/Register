<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

/** Cached deterministic fragment of the technical post index. */
final readonly class AllPostsPage
{
    public function __construct(
        public string $title,
        public string $html,
    ) {
    }
}
