<?php

declare(strict_types = 1);

/**
 * @copyright 2016-2023 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Storage;

interface FulltextProxyInterface
{
    public const int TYPE_TITLE = 1;

    public const int TYPE_KEYWORD = 2;

    public const int TYPE_CONTENT = 3;

    /**
     * @return array<int, array<int, list<int>>>
     */
    public function getByWord(string $word): array;

    public function countByWord(string $word): int;

    public function addWord(string $word, int $id, int $type, int $position): void;

    public function removeWord(string $word): void;

    /**
     * @return array<int|string, int>
     */
    public function getFrequentWords(int $threshold): array;

    public function removeById(int $id): void;
}
