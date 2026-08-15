<?php

declare(strict_types = 1);

/**
 * @copyright 2016-2025 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Storage\File;

use S2\Rose\Entity\ExternalId;
use S2\Rose\Entity\Metadata\Img;
use S2\Rose\Entity\Metadata\ImgCollection;
use S2\Rose\Entity\Metadata\SnippetSource;
use S2\Rose\Entity\TocEntry;
use S2\Rose\Helper\ProfileHelper;
use S2\Rose\Storage\ArrayFulltextStorage;
use S2\Rose\Storage\ArrayStorage;

class SingleFileArrayStorage extends ArrayStorage
{
    public function __construct(protected string $filename)
    {
        $this->fulltextProxy = new ArrayFulltextStorage();
    }

    /** @return list<array<string, string|float|int>> */
    public function load(bool $isDebug = false): array
    {
        $return = [];
        if (\count($this->toc) !== 0) {
            return $return;
        }

        if (!is_file($this->filename)) {
            return $return;
        }

        $startTime = microtime(true);

        $data = file_get_contents($this->filename);
        if ($data === false) {
            throw new \RuntimeException(sprintf('Cannot read search index file "%s".', $this->filename));
        }

        if ($isDebug) {
            $return[] = ProfileHelper::getProfilePoint('Reading index file', -$startTime + ($startTime = microtime(true)));
        }

        $myData = $this->extractSerializedSection($data);
        $unserializeOptions = ['allowed_classes' => [
            \DateTime::class,
            TocEntry::class,
            Img::class,
            ImgCollection::class,
            SnippetSource::class,
        ]];
        $this->getArrayFulltextStorage()->setFulltextIndex($this->decodeFulltextIndex($myData, $unserializeOptions));

        $myData              = $this->extractSerializedSection($data);
        $this->excludedWords = $this->decodeExcludedWords($myData, $unserializeOptions);

        $myData         = $this->extractSerializedSection($data);
        $this->metadata = $this->decodeMetadata($myData, $unserializeOptions);

        $myData = $this->extractSerializedSection($data);
        $this->toc = $this->decodeToc($myData, $unserializeOptions);


        if ($isDebug) {
            $return[] = ProfileHelper::getProfilePoint('Unserializing index', -$startTime + ($startTime = microtime(true)));
        }

        $this->externalIdMap = [];
        foreach ($this->toc as $serializedExtId => $entry) {
            $internalId = $entry->getInternalId();
            if ($internalId === null) {
                throw new \RuntimeException(sprintf('TOC entry "%s" has no internal id.', $serializedExtId));
            }

            $this->externalIdMap[$internalId] = ExternalId::fromString($serializedExtId);
        }

        return $return;
    }

    public function save(): void
    {
        if (is_file($this->filename) && !unlink($this->filename)) {
            throw new \RuntimeException(sprintf('Cannot replace search index file "%s".', $this->filename));
        }

        file_put_contents($this->filename, '<?php die; //' . 'a:' . \count($this->getArrayFulltextStorage()->getFulltextIndex()) . ':{');
        $buffer = '';
        $length = 0;
        foreach ($this->getArrayFulltextStorage()->getFulltextIndex() as $word => $data) {
            $chunk  = serialize($word) . serialize($data);
            $length += \strlen($chunk);
            $buffer .= $chunk;
            if ($length > 100000) {
                file_put_contents($this->filename, $buffer, FILE_APPEND);
                $buffer = '';
                $length = 0;
            }
        }

        file_put_contents($this->filename, $buffer . '}' . "\n", FILE_APPEND);
        $this->getArrayFulltextStorage()->setFulltextIndex([]);

        file_put_contents($this->filename, '      //' . serialize($this->excludedWords) . "\n", FILE_APPEND);
        $this->excludedWords = [];

        file_put_contents($this->filename, '      //' . serialize($this->metadata) . "\n", FILE_APPEND);
        $this->metadata = [];

        file_put_contents($this->filename, '      //' . serialize($this->toc) . "\n", FILE_APPEND);
        $this->toc = [];
    }

    private function extractSerializedSection(string &$data): string
    {
        $endPos = strpos($data, "\n");
        if ($endPos === false) {
            $line = $data;
            $data = '';
        } else {
            $line = substr($data, 0, $endPos);
            $data = substr($data, $endPos + 1);
        }

        $commentPos = strpos($line, '//');
        if ($commentPos === false) {
            throw new \RuntimeException('Broken SingleFileArrayStorage format: "//" marker not found.');
        }

        return substr($line, $commentPos + 2);
    }

    private function getArrayFulltextStorage(): ArrayFulltextStorage
    {
        if (!$this->fulltextProxy instanceof ArrayFulltextStorage) {
            throw new \LogicException('Single-file storage requires ArrayFulltextStorage.');
        }

        return $this->fulltextProxy;
    }

    /**
     * @param array{allowed_classes: list<class-string>} $options
     * @return array<int|string, array<int, int|string>>
     */
    private function decodeFulltextIndex(string $serialized, array $options): array
    {
        $data   = $this->decodeArray($serialized, $options);
        $result = [];
        foreach ($data as $word => $entries) {
            if (!\is_array($entries)) {
                throw new \RuntimeException('Broken fulltext section in the search index.');
            }

            foreach ($entries as $id => $entry) {
                if (!\is_int($id) || (!\is_int($entry) && !\is_string($entry))) {
                    throw new \RuntimeException('Broken fulltext entry in the search index.');
                }

                $result[$word][$id] = $entry;
            }
        }

        return $result;
    }

    /**
     * @param array{allowed_classes: list<class-string>} $options
     * @return array<int|string, int>
     */
    private function decodeExcludedWords(string $serialized, array $options): array
    {
        $data   = $this->decodeArray($serialized, $options);
        $result = [];
        foreach ($data as $word => $value) {
            if (!\is_int($value)) {
                throw new \RuntimeException('Broken excluded-words section in the search index.');
            }

            $result[$word] = $value;
        }

        return $result;
    }

    /**
     * @param array{allowed_classes: list<class-string>} $options
     * @return array<int, array{wordCount?: int, images?: ImgCollection, snippets?: list<SnippetSource>}>
     */
    private function decodeMetadata(string $serialized, array $options): array
    {
        $data   = $this->decodeArray($serialized, $options);
        $result = [];
        foreach ($data as $id => $metadata) {
            if (!\is_int($id) || !\is_array($metadata)) {
                throw new \RuntimeException('Broken metadata section in the search index.');
            }

            if (isset($metadata['wordCount'])) {
                if (!\is_int($metadata['wordCount'])) {
                    throw new \RuntimeException('Broken word count in the search index metadata.');
                }

                $result[$id]['wordCount'] = $metadata['wordCount'];
            }

            if (isset($metadata['images'])) {
                if (!$metadata['images'] instanceof ImgCollection) {
                    throw new \RuntimeException('Broken image collection in the search index metadata.');
                }

                $result[$id]['images'] = $metadata['images'];
            }

            if (isset($metadata['snippets'])) {
                if (!\is_array($metadata['snippets'])) {
                    throw new \RuntimeException('Broken snippets in the search index metadata.');
                }

                $snippets = [];
                foreach ($metadata['snippets'] as $snippet) {
                    if (!$snippet instanceof SnippetSource) {
                        throw new \RuntimeException('Broken snippet in the search index metadata.');
                    }

                    $snippets[] = $snippet;
                }

                $result[$id]['snippets'] = $snippets;
            }
        }

        return $result;
    }

    /**
     * @param array{allowed_classes: list<class-string>} $options
     * @return array<string, TocEntry>
     */
    private function decodeToc(string $serialized, array $options): array
    {
        $data   = $this->decodeArray($serialized, $options);
        $result = [];
        foreach ($data as $serializedExternalId => $entry) {
            if (!\is_string($serializedExternalId) || !$entry instanceof TocEntry) {
                throw new \RuntimeException('Broken TOC section in the search index.');
            }

            $result[$serializedExternalId] = $entry;
        }

        return $result;
    }

    /**
     * @param array{allowed_classes: list<class-string>} $options
     * @return array<mixed, mixed>
     */
    private function decodeArray(string $serialized, array $options): array
    {
        $data = unserialize($serialized, $options);
        if (!\is_array($data)) {
            throw new \RuntimeException('Broken serialized section in the search index.');
        }

        return $data;
    }
}
