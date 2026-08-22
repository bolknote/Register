<?php

declare(strict_types = 1);

/**
 * @copyright 2020 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Storage\Database;

use Register\Rose\Entity\ExternalId;

class IdMappingStorage
{
    /**
     * @var array<string, int>
     */
    protected array $idMapping = [];

    public function add(ExternalId $externalId, int $internalId): void
    {
        $this->idMapping[$externalId->toString()] = $internalId;
    }

    public function remove(ExternalId $externalId): void
    {
        unset($this->idMapping[$externalId->toString()]);
    }

    public function clear(): void
    {
        $this->idMapping = [];
    }

    public function get(ExternalId $externalId): ?int
    {
        $externalIdString = $externalId->toString();
        if (!isset($this->idMapping[$externalIdString])) {
            return null;
        }

        return $this->idMapping[$externalIdString];
    }
}
