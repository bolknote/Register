<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

final readonly class PostFeed
{
    public function __construct(
        public string  $html,
        public ?string $previousUrl,
        public ?string $nextUrl,
    ) {
    }
}
