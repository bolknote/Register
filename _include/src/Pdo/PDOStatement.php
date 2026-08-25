<?php
/**
 * PDO wrapper for lazy connections and logging.
 *
 * Forked from https://github.com/filisko/pdo-plus
 * 1. Fixed a bug with PDO::query()
 * 2. Made connections lazy
 * 3. Updated code to PHP 8.2
 *
 * @copyright 2023-2024 Roman Parpalak, based on code (c) 2021 Filis Futsarov
 * @license   MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Pdo;

use PDO as NativePdo;
use PDOStatement as NativePdoStatement;

class PDOStatement extends NativePdoStatement
{
    /**
     * For binding simulations purposes.
     * @var array<mixed>
     */
    protected array $bindings = [];

    protected function __construct(protected PDO $pdo)
    {
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function bindParam(
        int|string $param,
        mixed      &$var,
        int        $type = NativePdo::PARAM_STR,
        int        $maxLength = 0,
        mixed      $driverOptions = null
    ): bool {
        $this->bindings[$param] = $var;
        return parent::bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function bindValue(int|string $param, mixed $value, int $type = NativePdo::PARAM_STR): bool
    {
        $this->bindings[$param] = $value;
        return parent::bindValue($param, $value, $type);
    }

    /**
     * {@inheritdoc}
     * @param array<mixed>|null $params
     */
    #[\Override]
    public function execute(?array $params = null): bool
    {
        if ($params !== null) {
            $this->bindings = $params;
        }

        $statement = $this->addValuesToQuery($this->bindings, $this->queryString);

        $start  = microtime(true);
        $result = parent::execute($params);
        $this->pdo->addLog($statement, microtime(true) - $start, $this->queryString);
        return $result;
    }

    /**
     * @param array<mixed> $bindings
     */
    private function addValuesToQuery(array $bindings, string $query): string
    {
        $indexed = array_is_list($bindings);

        foreach ($bindings as $param => $value) {
            $value = match (true) {
                $value === null => 'null',
                \is_int($value) => (string)$value,
                \is_float($value) => (string)$value,
                \is_string($value) && is_numeric($value) => $value,
                default => $this->quoteForLog($value),
            };

            if ($indexed) {
                $query = preg_replace('/\?/', $value, $query, 1)
                    ?? throw new \RuntimeException('Unable to interpolate a positional query parameter.');
            } else {
                $query = str_replace(":$param", $value, $query);
            }
        }

        return $query;
    }

    private function quoteForLog(mixed $value): string
    {
        if (!\is_scalar($value) && !$value instanceof \Stringable) {
            return "'[" . get_debug_type($value) . "]'";
        }

        $quoted = $this->pdo->quote((string)$value);
        return $quoted === false ? "''" : $quoted;
    }
}
