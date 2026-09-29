<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content\Admin;

use Register\AdminYard\Config\VirtualFieldType;

/** Includes a separately stored editor field in the page's optimistic revision check. */
final readonly class RevisionTrackedVirtualFieldType extends VirtualFieldType
{
    /** @param (\Closure(mixed): mixed)|null $normalizer Must match the field's persistence rules. */
    public function __construct(string $titleSqlSubQuery, private ?\Closure $normalizer = null)
    {
        parent::__construct($titleSqlSubQuery);
    }

    public function revisionValue(mixed $value): mixed
    {
        return $this->normalizer === null ? $value : ($this->normalizer)($value);
    }
}
