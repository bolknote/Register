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
    ) {
    }

    /** @return array{counts: array<string, int>, selected: string|null, total: int} */
    public function toArray(): array
    {
        return [
            'counts'   => $this->counts,
            'selected' => $this->selected?->value,
            'total'    => array_sum($this->counts),
        ];
    }
}
