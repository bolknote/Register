<?php

declare(strict_types = 1);

/**
 * @copyright 2020-2023 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Entity;

use S2\Rose\Exception\InvalidArgumentException;

class ExternalIdCollection
{
    /**
     * @var list<ExternalId>
     */
    private readonly array $externalIds;

    /**
     * @param array<array-key, mixed> $externalIds
     */
    public function __construct(array $externalIds)
    {
        foreach ($externalIds as $externalId) {
            if (!$externalId instanceof ExternalId) {
                // @codeCoverageIgnoreStart
                throw new InvalidArgumentException('External ids must be an array of ExternalId.');
                // @codeCoverageIgnoreEnd
            }
        }

        $this->externalIds = array_values($externalIds);
    }

    /**
     * @param list<string> $serializedExternalIds
     */
    public static function fromStringArray(array $serializedExternalIds): self
    {
        return new self(array_map(ExternalId::fromString(...), $serializedExternalIds));
    }

    /**
     * @return list<ExternalId>
     */
    public function toArray(): array
    {
        return $this->externalIds;
    }
}
