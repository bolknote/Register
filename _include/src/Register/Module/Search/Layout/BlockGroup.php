<?php
/**
 * @copyright 2023-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Layout;

class BlockGroup
{
    private ?int $cachedCount = null;

    /**
     * @param array<mixed> $positions
     */
    public function __construct(private readonly array $positions, private readonly Block $block)
    {
    }

    public function getBlock(): Block
    {
        return $this->block;
    }

    /**
     * @return array<mixed>
     */
    public function getPositions(): array
    {
        return $this->positions;
    }

    public function count(): int
    {
        return $this->cachedCount ?? $this->cachedCount = \count($this->positions);
    }
}
