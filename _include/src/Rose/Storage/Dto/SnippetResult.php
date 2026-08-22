<?php

declare(strict_types = 1);

/**
 * @copyright 2023 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Storage\Dto;

use Register\Rose\Entity\ExternalId;
use Register\Rose\Entity\Metadata\SnippetSource;

class SnippetResult
{
    /**
     * @var array<string, list<SnippetSource>>
     */
    private array $data = [];

    public function attach(ExternalId $externalId, SnippetSource $snippet): void
    {
        $this->data[$externalId->toString()][] = $snippet;
    }

    /** @param callable(ExternalId, SnippetSource...): void $callback */
    public function iterate(callable $callback): void
    {
        foreach ($this->data as $serializedId => $snippets) {
            $callback(ExternalId::fromString($serializedId), ...$snippets);
        }
    }
}
