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

trait ParamsExecutableTrait
{
    private readonly QueryExecutorInterface $queryExecutor;

    /** @var array<int|string, mixed> */
    private array $paramValues = [];

    /** @var array<int|string, int> */
    private array $paramTypes = [];

    /**
     * @throws DbLayerException
     */
    public function getSql(): string
    {
        return $this->compiler->getSql($this);
    }

    /**
     * @throws DbLayerException
     * @param array<int|string, mixed> $params
     * @param array<int|string, int> $types
     */
    public function execute(array $params = [], array $types = []): QueryResult
    {
        return $this->queryExecutor->query(
            $this->getSql(),
            array_merge($this->paramValues, $params),
            array_merge($this->paramTypes, $types)
        );
    }

    public function setParameter(string $name, mixed $value, ?int $type = null): self
    {
        $this->paramValues[$name] = $value;
        if ($type !== null) {
            $this->paramTypes[$name] = $type;
        }

        return $this;
    }
}
