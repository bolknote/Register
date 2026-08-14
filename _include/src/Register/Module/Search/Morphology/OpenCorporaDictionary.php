<?php
/**
 * Search-oriented PHP reader for pymorphy3's OpenCorpora dictionary format.
 * Format compatibility was informed by the MIT-licensed pymorphy3 and DAWG-Python projects.
 *
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Morphology;

/**
 * Reads known-word paradigms directly from pymorphy3 format 2.4.
 *
 * Only the dictionary analyzer needed by search is implemented here. Unknown-word prediction,
 * grammatical tags, inflection and agreement remain outside this deliberately small runtime.
 */
final class OpenCorporaDictionary
{
    private const int HAS_LEAF_BIT = 1 << 8;

    private const int EXTENSION_BIT = 1 << 9;

    private const int IS_LEAF_BIT = 1 << 31;

    private const int LABEL_MASK = self::IS_LEAF_BIT | 0xff;

    private const int MAX_PAYLOAD_DEPTH = 32;

    private const int CACHE_LIMIT = 8192;

    private string $dawgData;

    private int $unitsCount;

    private int $guideOffset;

    private int $guideSize;

    private string $paradigmData;

    /** @var list<array{offset: int, length: int}> */
    private array $paradigmLocations = [];

    /** @var list<string> */
    private array $suffixes;

    /** @var list<string> */
    private array $paradigmPrefixes;

    /** @var array<int, list<int>> */
    private array $paradigmCache = [];

    /** @var array<string, list<string>> */
    private array $normalFormCache = [];

    public function __construct(string $dictionaryDirectory)
    {
        $dictionaryDirectory = rtrim($dictionaryDirectory, '/\\');
        $meta                 = $this->readJson($dictionaryDirectory . '/meta.json');
        if (($meta['format_version'] ?? null) !== '2.4' || ($meta['language_code'] ?? null) !== 'ru') {
            throw new \UnexpectedValueException('Only a Russian OpenCorpora dictionary in pymorphy format 2.4 is supported.');
        }

        $compileOptions = $meta['compile_options'] ?? null;
        $prefixes       = \is_array($compileOptions) ? ($compileOptions['paradigm_prefixes'] ?? null) : null;
        if (!\is_array($prefixes) || $prefixes === []) {
            throw new \UnexpectedValueException('The OpenCorpora dictionary does not declare paradigm prefixes.');
        }

        $this->paradigmPrefixes = $this->stringList($prefixes, 'paradigm prefixes');

        $suffixes       = $this->readJson($dictionaryDirectory . '/suffixes.json');
        $this->suffixes = $this->stringList($suffixes, 'suffixes');

        $this->loadParadigms($dictionaryDirectory . '/paradigms.array');
        $this->loadDawg($dictionaryDirectory . '/words.dawg');
    }

    /** @return list<string> */
    public function normalForms(string $word): array
    {
        $word = mb_strtolower($word);
        if (isset($this->normalFormCache[$word])) {
            return $this->normalFormCache[$word];
        }

        $normalForms = [];
        foreach ($this->matchingWordIndexes($word) as [$fixedWord, $wordIndex]) {
            $payloadIndex = $this->followBytes("\x01", $wordIndex);
            if ($payloadIndex === null) {
                continue;
            }

            foreach ($this->recordsAt($payloadIndex) as [$paradigmId, $formIndex]) {
                $normalForm               = $this->buildNormalForm($paradigmId, $formIndex, $fixedWord);
                $normalForms[$normalForm] = $normalForm;
            }
        }

        return $this->cacheNormalForms($word, array_values($normalForms));
    }

    private function loadDawg(string $filename): void
    {
        $data = file_get_contents($filename);
        if (!\is_string($data) || \strlen($data) < 12) {
            throw new \UnexpectedValueException('Unable to read the OpenCorpora word dictionary.');
        }

        $this->dawgData   = $data;
        $this->unitsCount = $this->uint32At(0);
        $guideHeader      = 4 + 4 * $this->unitsCount;
        if ($this->unitsCount === 0 || $guideHeader + 4 > \strlen($data)) {
            throw new \UnexpectedValueException('The OpenCorpora DAWG header is invalid.');
        }

        $this->guideSize   = $this->uint32At($guideHeader);
        $this->guideOffset = $guideHeader + 4;
        if ($this->guideSize === 0 || $this->guideOffset + 2 * $this->guideSize > \strlen($data)) {
            throw new \UnexpectedValueException('The OpenCorpora DAWG guide is invalid.');
        }
    }

    private function loadParadigms(string $filename): void
    {
        $data = file_get_contents($filename);
        if (!\is_string($data) || \strlen($data) < 2) {
            throw new \UnexpectedValueException('Unable to read OpenCorpora paradigms.');
        }

        $this->paradigmData = $data;
        $paradigmCount      = $this->uint16From($data, 0);
        $offset             = 2;
        for ($id = 0; $id < $paradigmCount; ++$id) {
            if ($offset + 2 > \strlen($data)) {
                throw new \UnexpectedValueException('The OpenCorpora paradigm header is truncated.');
            }

            $length = $this->uint16From($data, $offset);
            $offset += 2;
            if ($length === 0 || $length % 3 !== 0 || $offset + 2 * $length > \strlen($data)) {
                throw new \UnexpectedValueException('The OpenCorpora paradigm table is invalid.');
            }

            $this->paradigmLocations[] = ['offset' => $offset, 'length' => $length];
            $offset += 2 * $length;
        }
    }

    /**
     * pymorphy accepts an exact spelling and variants in which any «е» is replaced by «ё».
     *
     * @return list<array{string, int}>
     */
    private function matchingWordIndexes(string $word): array
    {
        $characters = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
        if (!\is_array($characters)) {
            return [];
        }

        $matches = [];
        $this->collectMatchingWordIndexes($characters, 0, 0, '', $matches);

        return $matches;
    }

    /**
     * @param list<string> $characters
     * @param list<array{string, int}> $matches
     */
    private function collectMatchingWordIndexes(
        array $characters,
        int $position,
        int $index,
        string $fixedWord,
        array &$matches,
    ): void {
        if ($position === \count($characters)) {
            if ($this->followBytes("\x01", $index) !== null) {
                $matches[] = [$fixedWord, $index];
            }

            return;
        }

        $character = $characters[$position];
        $nextIndex = $this->followBytes($character, $index);
        if ($nextIndex !== null) {
            $this->collectMatchingWordIndexes(
                $characters,
                $position + 1,
                $nextIndex,
                $fixedWord . $character,
                $matches,
            );
        }

        if ($character !== 'е') {
            return;
        }

        $nextIndex = $this->followBytes('ё', $index);
        if ($nextIndex !== null) {
            $this->collectMatchingWordIndexes(
                $characters,
                $position + 1,
                $nextIndex,
                $fixedWord . 'ё',
                $matches,
            );
        }
    }

    /** @return list<array{int, int}> */
    private function recordsAt(int $index): array
    {
        $payloads = [];
        $this->collectPayloads($index, '', 0, $payloads);

        $records = [];
        foreach ($payloads as $payload) {
            $decoded = base64_decode($payload, true);
            if (!\is_string($decoded) || \strlen($decoded) !== 4) {
                throw new \UnexpectedValueException('The OpenCorpora DAWG contains an invalid word record.');
            }

            $record = unpack('nparadigm/nform', $decoded);
            if (!\is_array($record)) {
                throw new \UnexpectedValueException('Unable to decode an OpenCorpora word record.');
            }

            $records[] = [(int)$record['paradigm'], (int)$record['form']];
        }

        return $records;
    }

    /** @param list<string> $payloads */
    private function collectPayloads(int $index, string $payload, int $depth, array &$payloads): void
    {
        if ($depth > self::MAX_PAYLOAD_DEPTH) {
            throw new \UnexpectedValueException('The OpenCorpora DAWG payload exceeds the supported length.');
        }

        if ($this->hasValue($index)) {
            $payloads[] = $payload;
        }

        $label = $this->guideChild($index);
        while ($label !== 0) {
            $childIndex = $this->followLabel($label, $index);
            if ($childIndex === null) {
                throw new \UnexpectedValueException('The OpenCorpora DAWG guide points to an invalid child.');
            }

            $this->collectPayloads($childIndex, $payload . \chr($label), $depth + 1, $payloads);
            $label = $this->guideSibling($childIndex);
        }
    }

    private function buildNormalForm(int $paradigmId, int $formIndex, string $word): string
    {
        $paradigm = $this->paradigm($paradigmId);
        $formCount = intdiv(\count($paradigm), 3);
        if ($formIndex < 0 || $formIndex >= $formCount) {
            throw new \UnexpectedValueException('An OpenCorpora word references an invalid paradigm form.');
        }

        if ($formIndex === 0) {
            return $word;
        }

        $prefixOffset = 2 * $formCount + $formIndex;
        if (!isset($paradigm[$prefixOffset], $paradigm[$formIndex])) {
            throw new \UnexpectedValueException('An OpenCorpora paradigm form is truncated.');
        }

        $prefixId = $paradigm[$prefixOffset];
        $suffixId = $paradigm[$formIndex];
        $prefix   = $this->paradigmPrefixes[$prefixId] ?? null;
        $suffix   = $this->suffixes[$suffixId] ?? null;
        if (!\is_string($prefix) || !\is_string($suffix)) {
            throw new \UnexpectedValueException('An OpenCorpora paradigm references an unknown affix.');
        }

        if (!str_starts_with($word, $prefix) || ($suffix !== '' && !str_ends_with($word, $suffix))) {
            throw new \UnexpectedValueException('An OpenCorpora paradigm cannot be applied to its word.');
        }

        $stemLength = \strlen($word) - \strlen($prefix) - \strlen($suffix);
        if ($stemLength < 0) {
            throw new \UnexpectedValueException('An OpenCorpora paradigm produces a negative stem length.');
        }

        $stem = substr($word, \strlen($prefix), $stemLength);

        $normalPrefixId = $paradigm[2 * $formCount];
        $normalSuffixId = $paradigm[0];
        $normalPrefix   = $this->paradigmPrefixes[$normalPrefixId] ?? null;
        $normalSuffix   = $this->suffixes[$normalSuffixId] ?? null;
        if (!\is_string($normalPrefix) || !\is_string($normalSuffix)) {
            throw new \UnexpectedValueException('An OpenCorpora paradigm references an unknown affix.');
        }

        return $normalPrefix . $stem . $normalSuffix;
    }

    /** @return list<int> */
    private function paradigm(int $id): array
    {
        if (isset($this->paradigmCache[$id])) {
            return $this->paradigmCache[$id];
        }

        $location = $this->paradigmLocations[$id] ?? null;
        if (!\is_array($location)) {
            throw new \UnexpectedValueException('An OpenCorpora word references an unknown paradigm.');
        }

        $encoded = substr($this->paradigmData, $location['offset'], 2 * $location['length']);
        $values  = unpack('v*', $encoded);
        if (!\is_array($values) || \count($values) < $location['length']) {
            throw new \UnexpectedValueException('Unable to decode an OpenCorpora paradigm.');
        }

        return $this->paradigmCache[$id] = array_values(array_slice($values, 0, $location['length']));
    }

    private function followBytes(string $bytes, int $index): ?int
    {
        $length = \strlen($bytes);
        for ($position = 0; $position < $length; ++$position) {
            $index = $this->followLabel(\ord($bytes[$position]), $index);
            if ($index === null) {
                return null;
            }
        }

        return $index;
    }

    private function followLabel(int $label, int $index): ?int
    {
        if ($index < 0 || $index >= $this->unitsCount) {
            return null;
        }

        $base      = $this->unit($index);
        $offset    = (($base >> 10) << (($base & self::EXTENSION_BIT) >> 6)) & 0xffffffff;
        $nextIndex = ($index ^ $offset ^ $label) & 0xffffffff;
        if ($nextIndex >= $this->unitsCount || ($this->unit($nextIndex) & self::LABEL_MASK) !== $label) {
            return null;
        }

        return $nextIndex;
    }

    private function hasValue(int $index): bool
    {
        return ($this->unit($index) & self::HAS_LEAF_BIT) !== 0;
    }

    private function guideChild(int $index): int
    {
        return $this->guideByte($index * 2);
    }

    private function guideSibling(int $index): int
    {
        return $this->guideByte($index * 2 + 1);
    }

    private function guideByte(int $offset): int
    {
        if ($offset < 0 || $offset >= 2 * $this->guideSize) {
            throw new \UnexpectedValueException('The OpenCorpora DAWG guide offset is invalid.');
        }

        return \ord($this->dawgData[$this->guideOffset + $offset]);
    }

    private function unit(int $index): int
    {
        return $this->uint32At(4 + 4 * $index);
    }

    private function uint32At(int $offset): int
    {
        $value = unpack('Vvalue', $this->dawgData, $offset);
        if (!\is_array($value)) {
            throw new \UnexpectedValueException('Unable to decode an OpenCorpora DAWG unit.');
        }

        return (int)$value['value'];
    }

    private function uint16From(string $data, int $offset): int
    {
        $value = unpack('vvalue', $data, $offset);
        if (!\is_array($value)) {
            throw new \UnexpectedValueException('Unable to decode an OpenCorpora paradigm value.');
        }

        return (int)$value['value'];
    }

    /**
     * @param list<string> $normalForms
     * @return list<string>
     */
    private function cacheNormalForms(string $word, array $normalForms): array
    {
        if (\count($this->normalFormCache) >= self::CACHE_LIMIT) {
            $this->normalFormCache = [];
        }

        return $this->normalFormCache[$word] = $normalForms;
    }

    /** @return array<mixed> */
    private function readJson(string $filename): array
    {
        $contents = file_get_contents($filename);
        if (!\is_string($contents)) {
            throw new \UnexpectedValueException('Unable to read OpenCorpora metadata.');
        }

        $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($value)) {
            throw new \UnexpectedValueException('OpenCorpora metadata must be a JSON array.');
        }

        // pymorphy metadata is serialized as a list of key-value pairs.
        if (array_is_list($value) && isset($value[0]) && \is_array($value[0]) && \count($value[0]) === 2) {
            $mapped = [];
            foreach ($value as $item) {
                if (!\is_array($item) || \count($item) !== 2 || !\is_string($item[0])) {
                    throw new \UnexpectedValueException('OpenCorpora metadata contains an invalid key-value pair.');
                }

                $mapped[$item[0]] = $item[1];
            }

            return $mapped;
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function stringList(array $values, string $description): array
    {
        $result = [];
        foreach ($values as $value) {
            if (!\is_string($value)) {
                throw new \UnexpectedValueException('The OpenCorpora ' . $description . ' table contains a non-string value.');
            }

            $result[] = $value;
        }

        return $result;
    }
}
