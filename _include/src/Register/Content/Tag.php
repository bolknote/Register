<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

final readonly class Tag
{
    public function __construct(
        public int    $id,
        public string $name,
        public string $slug,
        public string $description,
    ) {
        if ($id <= 0) {
            throw new \InvalidArgumentException('A tag identifier must be a positive integer.');
        }
    }
}
