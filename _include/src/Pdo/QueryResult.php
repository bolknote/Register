<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Pdo;

readonly class QueryResult
{
    public function __construct(private \PDOStatement $pdoStatement)
    {
    }

    /**
     * @return array<mixed>
     */
    public function fetchAssocAll(): array
    {
        return $this->pdoStatement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function result(int $row = 0, int|string $col = 0): mixed
    {
        if ($row < 0) {
            throw new \InvalidArgumentException('Row number cannot be negative.');
        }

        for ($i = $row; $i--;) {
            $curRow = $this->pdoStatement->fetch();
            if ($curRow === false) {
                return false;
            }
        }

        $curRow = $this->pdoStatement->fetch();
        if ($curRow === false) {
            return false;
        }

        return $curRow[$col] ?? false;
    }

    /**
     * @return array<mixed>|false
     */
    public function fetchAssoc(): array|false
    {
        return $this->pdoStatement->fetch(\PDO::FETCH_ASSOC);
    }


    /**
     * @return array<mixed>|false
     */
    public function fetchRow(): array|false
    {
        return $this->pdoStatement->fetch(\PDO::FETCH_NUM);
    }

    /**
     * @return array<mixed>
     */
    public function fetchColumn(): array
    {
        return $this->pdoStatement->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function affectedRows(): int
    {
        return $this->pdoStatement->rowCount();
    }

    public function freeResult(): true
    {
        $this->pdoStatement->closeCursor();
        return true;
    }

    /**
     * @return array<mixed>
     */
    public function fetchKeyPair(): array
    {
        return $this->pdoStatement->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
}
