<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Test;

use Codeception\Test\Unit;
use S2\Rose\Entity\Indexable;
use S2\Rose\Entity\Query;
use S2\Rose\Finder;
use S2\Rose\Indexer;
use S2\Rose\Stemmer\WordNormalizerInterface;
use S2\Rose\Storage\File\SingleFileArrayStorage;

final class WordNormalizerTest extends Unit
{
    public function testSeveralNormalFormsAreIndexedSearchedAndHighlighted(): void
    {
        $storage    = new SingleFileArrayStorage(__DIR__ . '/../../tmp/word-normalizer.php');
        $normalizer = new AmbiguousWordNormalizer();
        $indexer    = new Indexer($storage, $normalizer);

        $indexer->index(new Indexable('verb', 'Изменение', 'Они стали сильнее.'));
        $indexer->index(new Indexable('noun', 'Материал', 'Прочная сталь выдержала нагрузку.'));

        $finder = new Finder($storage, $normalizer);
        $finder->setHighlightTemplate('<b>%s</b>');

        $verbResult = $finder->find(new Query('стать'));
        self::assertSame(['verb'], array_map(static fn(\S2\Rose\Entity\ResultItem $item): string => $item->getId(), $verbResult->getItems()));
        self::assertSame('Они <b>стали</b> сильнее.', $verbResult->getItems()[0]->getSnippet());

        $nounResult = $finder->find(new Query('сталь'));
        self::assertEqualsCanonicalizing(
            ['verb', 'noun'],
            array_map(static fn(\S2\Rose\Entity\ResultItem $item): string => $item->getId(), $nounResult->getItems())
        );

        $verbItem = null;
        foreach ($nounResult->getItems() as $item) {
            if ($item->getId() === 'verb') {
                $verbItem = $item;
                break;
            }
        }

        self::assertNotNull($verbItem);
        self::assertSame('Они <b>стали</b> сильнее.', $verbItem->getSnippet());
    }
}

final class AmbiguousWordNormalizer implements WordNormalizerInterface
{
    #[\Override]
    public function stemWord(string $word, bool $normalize = true): string
    {
        return $this->normalizeWord($word, $normalize)[0];
    }

    #[\Override]
    public function normalizeWord(string $word, bool $normalize = true): array
    {
        $word = $normalize ? mb_strtolower($word) : $word;
        if ($word === 'стали') {
            return ['стать', 'сталь'];
        }

        return [$word];
    }
}
