<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Controller\Comment;

readonly class CommentDto
{
    public function __construct(
        public int    $id,
        public int    $targetId,
        public string $name,
        public string $email,
        public string $text,
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
