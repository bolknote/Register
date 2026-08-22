<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Pdo\QueryBuilder;

use Register\Core\Pdo\QueryResult;
use Register\Core\Pdo\DbLayerException;

interface QueryExecutorInterface
{
    /**
     * @throws DbLayerException
     * @param array<int|string, mixed> $params
     * @param array<int|string, int> $types
     */
    public function query(string $sql, array $params = [], array $types = []): QueryResult;
}
