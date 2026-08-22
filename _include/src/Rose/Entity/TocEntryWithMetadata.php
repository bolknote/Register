<?php

declare(strict_types = 1);

/**
 * @copyright 2020-2023 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Entity;

use Register\Rose\Entity\Metadata\ImgCollection;

class TocEntryWithMetadata
{
    public function __construct(private readonly TocEntry $tocEntry, private readonly ExternalId $externalId, private readonly ImgCollection $imgCollection)
    {
    }

    public function getTocEntry(): TocEntry
    {
        return $this->tocEntry;
    }

    public function getExternalId(): ExternalId
    {
        return $this->externalId;
    }

    public function getImgCollection(): ImgCollection
    {
        return $this->imgCollection;
    }
}
