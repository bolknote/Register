<?php

declare(strict_types = 1);

/**
 * @copyright 2023 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Extractor;

use Psr\Log\LoggerAwareTrait;
use Register\Rose\Exception\LogicException;
use Register\Rose\Exception\RuntimeException;

class ChainExtractor implements ExtractorInterface
{
    use LoggerAwareTrait;

    /** @var list<ExtractorInterface> */
    private array $extractors = [];

    public function attachExtractor(ExtractorInterface $extractor): void
    {
        $this->extractors[] = $extractor;
    }

    /**
     * {@inheritdoc}
     * @throws RuntimeException
     */
    #[\Override]
    public function extract(string $text): ExtractionResult
    {
        if (\count($this->extractors) === 0) {
            throw new LogicException('No extractors were attached to the ChainExtractor.');
        }

        $lastIndex = \count($this->extractors) - 1;
        foreach ($this->extractors as $index => $extractor) {
            try {
                return $extractor->extract($text);
            } catch (\Throwable $exception) {
                if ($this->logger instanceof \Psr\Log\LoggerInterface) {
                    $this->logger->error($exception->getMessage(), ['exception' => $exception]);
                }

                if ($index === $lastIndex) {
                    throw new RuntimeException($exception->getMessage(), 0, $exception);
                }
            }
        }

        throw new LogicException('Extractor chain ended without producing a result.');
    }
}
