<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update;

final readonly class UpdatePlan
{
    /**
     * @param list<string> $writes
     * @param list<string> $deletes
     * @param list<string> $unchanged
     * @param list<string> $conflicts
     */
    public function __construct(
        public array $writes,
        public array $deletes,
        public array $unchanged,
        public array $conflicts,
        public int   $writeBytes,
    ) {
    }

    public function canApply(): bool
    {
        return $this->conflicts === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'writes'      => $this->writes,
            'deletes'     => $this->deletes,
            'unchanged'   => $this->unchanged,
            'conflicts'   => $this->conflicts,
            'write_bytes' => $this->writeBytes,
        ];
    }
}
