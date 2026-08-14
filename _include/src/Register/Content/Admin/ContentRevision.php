<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content\Admin;

final readonly class ContentRevision
{
    public function __construct(
        public bool $contentChanged,
        public string $value,
    ) {
    }
}
