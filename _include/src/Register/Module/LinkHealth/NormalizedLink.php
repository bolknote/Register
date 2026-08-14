<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

final readonly class NormalizedLink
{
    public function __construct(
        public string   $url,
        public LinkKind $kind,
        public string   $host = '',
        public string   $fragment = '',
    ) {
        if ($url === '') {
            throw new \InvalidArgumentException('A normalized link URL cannot be empty.');
        }
    }
}
