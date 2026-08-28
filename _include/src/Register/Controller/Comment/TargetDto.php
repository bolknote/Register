<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Controller\Comment;

readonly class TargetDto
{
    public function __construct(
        public int    $id,
        public string $title,
    ) {
    }
}
