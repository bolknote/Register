<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Model\Comment;

final readonly class CommentModerator
{
    public function __construct(
        public string $login,
        public string $email,
        public bool   $canHide,
        public bool   $canEdit,
        public string $sessionHash,
    ) {
    }
}
