<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license http://opensource.org/licenses/MIT MIT
 * @package AdminYard
 */

declare(strict_types=1);

namespace Register\AdminYard\Database;

interface TypeTransformerInterface
{
    public function normalizedFromDb(mixed $value, string $dataType): mixed;
    public function dbFromNormalized(mixed $value, string $dataType): mixed;
}
