<?php

declare(strict_types = 1);

/**
 * @copyright 2023 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Storage\Dto;

use S2\Rose\Entity\ExternalId;
use S2\Rose\Entity\ExternalIdCollection;
use S2\Rose\Exception\LogicException;

class SnippetQuery
{
    /**
     * @var array<string, list<int>|null>
     */
    private array $data = [];

    public function __construct(ExternalIdCollection $externalIds)
    {
        foreach ($externalIds->toArray() as $externalId) {
            $this->data[$externalId->toString()] = null;
        }
    }

    /**
     * @param array<int, int> $positions
     */
    public function attach(ExternalId $externalId, array $positions): void
    {
        $serializedExtId = $externalId->toString();
        if (isset($this->data[$serializedExtId])) {
            throw new LogicException(sprintf('SnippetQuery already has id "%s".', $serializedExtId));
        }

        $this->data[$serializedExtId] = array_values($positions);
    }

    /**
     * @param callable(ExternalId, list<int>|null): void $callback
     */
    public function iterate(callable $callback): void
    {
        foreach ($this->data as $serializedExtId => $positions) {
            $callback(ExternalId::fromString($serializedExtId), $positions);
        }
    }

    /**
     * @return list<ExternalId>
     */
    public function getExternalIds(): array
    {
        return array_map(ExternalId::fromString(...), array_keys($this->data));
    }
}
