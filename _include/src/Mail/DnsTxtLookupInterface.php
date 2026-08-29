<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Mail;

interface DnsTxtLookupInterface
{
    /**
     * @param list<string> $names
     * @return array<string, list<string>|null>|null Null means that a bounded lookup is unavailable.
     */
    public function lookup(array $names): ?array;
}
