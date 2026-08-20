<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Reactions;

final readonly class ReactionState
{
    /**
     * @param array<string, int> $counts
     */
    public function __construct(
        public array         $counts,
        public ?ReactionType $selected,
        /** @var array<string, int> Exact imported emoji which do not belong to the built-in picker. */
        public array         $extraCounts = [],
    ) {
    }

    /** @return array{counts: array<string, int>, extra: array<string, int>, selected: string|null, total: int} */
    public function toArray(): array
    {
        return [
            'counts'   => $this->counts,
            'extra'    => $this->extraCounts,
            'selected' => $this->selected?->value,
            'total'    => array_sum($this->counts) + array_sum($this->extraCounts),
        ];
    }
}
