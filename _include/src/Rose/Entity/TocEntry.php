<?php

declare(strict_types = 1);

/**
 * @copyright 2016-2023 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Entity;

class TocEntry
{
    protected ?int $internalId = null;

    public function __construct(protected string $title, protected string $description, protected ?\DateTime $date, protected string $url, private readonly float $relevanceRatio, protected string $hash)
    {
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getDate(): ?\DateTime
    {
        return $this->date;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getRelevanceRatio(): float
    {
        return $this->relevanceRatio;
    }

    public function getInternalId(): ?int
    {
        return $this->internalId;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function setInternalId(int $internalId): self
    {
        $this->internalId = $internalId;

        return $this;
    }

    public function getFormattedDate(): ?string
    {
        return $this->date instanceof \DateTime ? $this->date->format('Y-m-d H:i:s') : null;
    }

    public function getTimeZone(): ?string
    {
        return $this->date instanceof \DateTime ? $this->date->getTimezone()->getName() : null;
    }
}
