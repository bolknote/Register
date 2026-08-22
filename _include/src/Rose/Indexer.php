<?php

declare(strict_types = 1);

/**
 * Creates search index
 *
 * @copyright 2010-2024 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose;

use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Register\Rose\Entity\ContentWithMetadata;
use Register\Rose\Entity\ExternalId;
use Register\Rose\Entity\Indexable;
use Register\Rose\Exception\RuntimeException;
use Register\Rose\Exception\UnknownException;
use Register\Rose\Extractor\DefaultExtractorFactory;
use Register\Rose\Extractor\ExtractorInterface;
use Register\Rose\Helper\StringHelper;
use Register\Rose\Stemmer\StemmerHelper;
use Register\Rose\Stemmer\StemmerInterface;
use Register\Rose\Storage\Exception\EmptyIndexException;
use Register\Rose\Storage\StorageEraseInterface;
use Register\Rose\Storage\StorageWriteInterface;
use Register\Rose\Storage\TransactionalStorageInterface;

class Indexer
{
    use LoggerAwareTrait;

    protected ExtractorInterface $extractor;

    private bool $autoErase = false;

    public function __construct(
        protected StorageWriteInterface $storage,
        protected StemmerInterface      $stemmer,
        ?ExtractorInterface   $extractor = null,
        ?LoggerInterface      $logger = null
    ) {
        $this->extractor = $extractor ?? DefaultExtractorFactory::create();
        $this->logger    = $logger;
    }

    /**
     * Cleaning up an HTML string.
     */
    public static function titleStrFromHtml(string $content, string $allowedSymbols = ''): string
    {
        $content = mb_strtolower($content);
        $content = str_replace(['&nbsp;', "\xc2\xa0"], ' ', $content);
        $content = preg_replace('#&[^;]{1,20};#', '', $content) ?? $content;

        // We allow letters, digits and some punctuation: ".,-"
        $content = preg_replace('#[^\\-.,0-9\\p{L}^_' . $allowedSymbols . ']+#u', ' ', $content) ?? $content;

        // These punctuation characters are meant to be inside words and numbers.
        // We'll remove trailing characters when splitting the words.
        $content .= ' ';

        return $content;
    }

    /**
     * @return string[]
     */
    protected static function arrayFromStr(string $contents): array
    {
        $words = preg_split('#[\\-.,]*?[ ]+#S', $contents);
        if ($words === false) {
            return [];
        }

        StringHelper::removeLongWords($words);

        return $words;
    }

    protected function addToIndex(ExternalId $externalId, string $title, ContentWithMetadata $content, string $keywords): void
    {
        $sentenceCollection = $content->getSentenceMap()->toSentenceCollection();
        $contentWordsArray  = $sentenceCollection->getWordsArray();

        foreach ($contentWordsArray as $i => $word) {
            if ($this->storage->isExcludedWord($word)) {
                unset($contentWordsArray[$i]);
            }
        }

        $titleWordsArray = self::arrayFromStr($title);
        $keywordsArray   = self::arrayFromStr($keywords);

        $this->storage->addMetadata($externalId, \count($titleWordsArray) + \count($contentWordsArray), $content->getImageCollection());
        $this->storage->addSnippets($externalId, ...$sentenceCollection->getSnippetSources());
        $this->storage->addToFulltextIndex(
            $this->getStemsWithComponents($titleWordsArray),
            $this->getStemsWithComponents($keywordsArray), // TODO consider different semantics of space and comma?
            $this->getStemsWithComponents($contentWordsArray),
            $externalId
        );
    }

    public function removeById(string $id, ?int $instanceId): void
    {
        $externalId = new ExternalId($id, $instanceId);
        $this->storage->removeFromIndex($externalId);
        $this->storage->removeFromToc($externalId);
    }

    /**
     * @throws RuntimeException
     * @throws UnknownException
     */
    public function index(Indexable $indexable): void
    {
        try {
            $this->doIndex($indexable);
        } catch (EmptyIndexException $e) {
            if (!$this->autoErase || !$this->storage instanceof StorageEraseInterface) {
                throw $e;
            }

            $this->storage->erase();
            $this->doIndex($indexable);
        }
    }

    public function setAutoErase(bool $autoErase): void
    {
        $this->autoErase = $autoErase;
    }

    /**
     * @throws RuntimeException
     * @throws UnknownException
     */
    protected function doIndex(Indexable $indexable): void
    {
        if ($this->storage instanceof TransactionalStorageInterface) {
            $this->storage->startTransaction();
        }

        try {
            $externalId  = $indexable->getExternalId();
            $oldTocEntry = $this->storage->getTocByExternalId($externalId);

            $this->storage->addEntryToToc($indexable->toTocEntry(), $externalId);

            if (!$oldTocEntry instanceof \Register\Rose\Entity\TocEntry || $oldTocEntry->getHash() !== $indexable->calcHash()) {
                $this->storage->removeFromIndex($externalId);

                $extractionResult = $this->extractor->extract($indexable->getContent());
                $extractionErrors = $extractionResult->getErrors();
                if ($this->logger instanceof \Psr\Log\LoggerInterface && $extractionErrors->hasErrors()) {
                    $this->logger->warning(sprintf(
                        'Found warnings on indexing "%s" (id="%s", instance="%s", url="%s")',
                        $indexable->getTitle(),
                        $indexable->getExternalId()->getId(),
                        $indexable->getExternalId()->getInstanceId() ?? '',
                        $indexable->getUrl()
                    ), $extractionErrors->getFormattedLines());
                }

                // strtolower in titleStrFromHtml is important
                $this->addToIndex(
                    $externalId,
                    self::titleStrFromHtml($indexable->getTitle()),
                    $extractionResult->getContentWithMetadata(),
                    self::titleStrFromHtml($indexable->getKeywords())
                );
            }

            if ($this->storage instanceof TransactionalStorageInterface) {
                $this->storage->commitTransaction();
            }
        } catch (\Throwable $e) {
            if ($this->storage instanceof TransactionalStorageInterface) {
                $this->storage->rollbackTransaction();
            }

            if (!($e instanceof RuntimeException)) {
                throw new UnknownException('Unknown exception occurred while indexing.', 0, $e);
            }

            throw $e;
        }
    }

    /**
     * Replaces words with stems. Also, this method detects compound words and adds the component stems to the result.
     * A word normalizer may return several equivalent forms; they are stored at the same logical position.
     *
     * The keys in the result arrays are the positions of the word. For compound words a string representation
     * of a float is used to map one index to several words. For example, for input
     *
     * [10 => 'well-known', 11 => 'facts']
     *
     * this method returns
     *
     * [10 => 'well-known', 11 => 'fact', '10.1' => 'well', '10.2' => 'known']
     *
     * @param string[] $words
     *
     * @return array<int|string, string>
     */
    private function getStemsWithComponents(array $words): array
    {
        $result = [];
        foreach ($words as $position => $word) {
            $stems = StemmerHelper::stemWords($this->stemmer, $word, false);

            // If the word contains punctuation marks like hyphen, add variants without them.
            if (false !== strpbrk($word, StringHelper::WORD_COMPONENT_DELIMITERS)) {
                $subWords = preg_split('#(?<=[\p{L}\d])[\-.,]+|[\-.,]++(?=[\p{L}\d])#u', $word);
                foreach ($subWords === false ? [] : $subWords as $subWord) {
                    if ($subWord !== '' && $subWord !== $word) {
                        array_push($stems, ...StemmerHelper::stemWords($this->stemmer, $subWord, false));
                    }
                }
            }

            foreach (array_values(array_unique($stems)) as $variant => $stem) {
                $key          = $variant === 0 ? $position : (string)$position . '.' . $variant;
                $result[$key] = $stem;
            }
        }

        return $result;
    }
}
