<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Pdo\QueryBuilder;

readonly class UnionAll
{
    /**
     * @var array<int|string, \Register\Core\Pdo\QueryBuilder\SelectBuilder>
     */
    public array $selects;

    public function __construct(SelectBuilder ...$selects)
    {
        $this->selects = $selects;
    }
}
