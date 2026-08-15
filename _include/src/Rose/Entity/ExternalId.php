<?php

declare(strict_types = 1);

/**
 * @copyright 2020-2023 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Entity;

use S2\Rose\Exception\InvalidArgumentException;

class ExternalId
{
    protected string $id;

    protected ?int $instanceId;

    public function __construct(string|int|float $id, ?int $instanceId = null)
    {
        if (($instanceId !== null) && $instanceId <= 0) {
            // @codeCoverageIgnoreStart
            throw new InvalidArgumentException('Instance id must be positive.');
            // @codeCoverageIgnoreEnd
        }

        $this->id         = (string)$id;
        $this->instanceId = $instanceId;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getInstanceId(): ?int
    {
        return $this->instanceId;
    }

    public function toString(): string
    {
        return ($this->instanceId === null ? '' : (string)$this->instanceId) . ':' . $this->id;
    }

    public static function fromString(string $string): self
    {
        $data = explode(':', $string, 2);

        return new self($data[1], $data[0] !== '' ? (int)$data[0] : null);
    }
}
