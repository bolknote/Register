<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Pdo\QueryBuilder;

use S2\Cms\Pdo\QueryResult;
use S2\Cms\Pdo\DbLayerException;

interface QueryExecutorInterface
{
    /**
     * @throws DbLayerException
     * @param array<int|string, mixed> $params
     * @param array<int|string, int> $types
     */
    public function query(string $sql, array $params = [], array $types = []): QueryResult;
}
