<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Search\Service;

use Codeception\Test\Unit;
use Register\Module\Search\Morphology\ChurchSlavonicNormalizer;
use Register\Module\Search\Morphology\HistoricalRussianNormalizer;
use Register\Module\Search\Morphology\OpenCorporaDictionary;
use Register\Module\Search\Morphology\PreReformRussianNormalizer;
use Register\Module\Search\Service\HistoricalTitleSearch;
use Register\Rose\Entity\ExternalId;
use Register\Rose\Entity\TocEntry;
use Register\Rose\Storage\Database\PdoStorage;

final class HistoricalTitleSearchTest extends Unit
{
    private static ?OpenCorporaDictionary $dictionary = null;

    public function testFindsHistoricalAndModernTitlesInBothDirections(): void
    {
        $search = $this->searchWithTitles();

        self::assertSame(['old'], $this->ids($search->find('дети')));
        self::assertSame(['old'], $this->ids($search->find('ксения')));
        self::assertSame(['modern'], $this->ids($search->find('Ѳома')));
        self::assertSame(['modern'], $this->ids($search->find('ѿрокъ')));
        self::assertSame(['modern', 'ot'], $this->ids($search->find('от')));
        self::assertSame(['yo'], $this->ids($search->find('елка')));
        self::assertSame(['old-ending'], $this->ids($search->find('хорошего')));
        self::assertSame([], $this->ids($search->find('несуществующее')));
    }

    public function testHighlightsHistoricalMatchesAndEscapesTheWholeTitle(): void
    {
        $search = $this->searchWithTitles();

        self::assertSame('<em>Дѣти</em> &amp; Ѯенія', $search->highlight('Дѣти & Ѯенія', 'дети'));
        self::assertSame('Дѣти &amp; <em>Ѯенія</em>', $search->highlight('Дѣти & Ѯенія', 'ксения'));
        self::assertSame('<em>Фома</em> и отрок', $search->highlight('Фома и отрок', 'Ѳома'));
        self::assertSame('Фома и <em>отрок</em>', $search->highlight('Фома и отрок', 'ѿрокъ'));
        self::assertSame('<em>Хорошаго</em> журнала', $search->highlight('Хорошаго журнала', 'хорошего'));
        self::assertSame('&lt;b&gt;<em>Фома</em>&lt;/b&gt;', $search->highlight('<b>Фома</b>', 'фома'));
        self::assertSame('Фома &amp; отрок', $search->highlight('Фома & отрок', ''));
    }

    private function searchWithTitles(): HistoricalTitleSearch
    {
        $storage = new PdoStorage(new \PDO('sqlite::memory:'), 'historical_title_test_');
        $storage->erase();
        $this->addTitle($storage, 'old', 'Дѣти & Ѯенія');
        $this->addTitle($storage, 'modern', 'Фома и отрок');
        $this->addTitle($storage, 'ot', 'Ѿ закона');
        $this->addTitle($storage, 'yo', 'Ёлка');
        $this->addTitle($storage, 'old-ending', 'Хорошаго журнала');
        $this->addTitle($storage, 'other', 'Совсем иное название');

        return new HistoricalTitleSearch(
            $storage,
            new HistoricalRussianNormalizer(
                new ChurchSlavonicNormalizer(),
                new PreReformRussianNormalizer(),
                self::$dictionary ??= new OpenCorporaDictionary(
                    dirname(__DIR__, 6) . '/_include/src/Register/Module/Search/resources/morphology/ru',
                ),
            ),
        );
    }

    private function addTitle(PdoStorage $storage, string $id, string $title): void
    {
        $storage->addEntryToToc(
            new TocEntry($title, '', null, '/' . $id, 1.0, md5($id)),
            new ExternalId($id),
        );
    }

    /**
     * @param list<\Register\Rose\Entity\TocEntryWithMetadata> $entries
     * @return list<string>
     */
    private function ids(array $entries): array
    {
        return array_map(
            static fn(\Register\Rose\Entity\TocEntryWithMetadata $entry): string => $entry->getExternalId()->getId(),
            $entries,
        );
    }
}
