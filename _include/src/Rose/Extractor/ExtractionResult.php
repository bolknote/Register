<?php

declare(strict_types = 1);

/**
 * @copyright 2023 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Extractor;

use S2\Rose\Entity\ContentWithMetadata;

class ExtractionResult
{
    public function __construct(private readonly ContentWithMetadata $contentWithMetadata, private readonly ExtractionErrors $errors)
    {
    }

    public function getContentWithMetadata(): ContentWithMetadata
    {
        return $this->contentWithMetadata;
    }

    public function getErrors(): ExtractionErrors
    {
        return $this->errors;
    }
}
