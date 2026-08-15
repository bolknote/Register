<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

final readonly class DnsResponse
{
    /** @param list<string> $addresses */
    public function __construct(
        public DnsResponseStatus $status,
        public array             $addresses = [],
    ) {
    }
}
