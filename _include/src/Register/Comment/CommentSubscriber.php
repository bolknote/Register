<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

final readonly class CommentSubscriber
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $unsubscribeToken,
    ) {
    }
}
