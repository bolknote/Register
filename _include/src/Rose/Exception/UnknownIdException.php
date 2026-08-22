<?php

declare(strict_types = 1);

/**
 * @copyright 2016-2020 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Exception;

use Register\Rose\Entity\ExternalId;

class UnknownIdException extends RuntimeException
{
    public static function createIndexMissingExternalId(ExternalId $externalId): self
    {
        return new self(sprintf(
            'External id "%s" for instance "%s" not found in index.',
            $externalId->getId(),
            (string)($externalId->getInstanceId() ?? '')
        ));
    }

    public static function createResultMissingExternalId(ExternalId $externalId): self
    {
        return new self(sprintf(
            'External id "%s" for instance "%s" not found in result.',
            $externalId->getId(),
            (string)($externalId->getInstanceId() ?? '')
        ));
    }
}
