<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model\Comment;

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
