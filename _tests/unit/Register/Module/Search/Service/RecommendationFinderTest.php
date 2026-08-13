<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Search\Service;

use Codeception\Test\Unit;
use Register\Module\Search\Service\RecommendationFinder;
use S2\Rose\Entity\ExternalId;
use S2\Rose\Entity\Indexable;
use S2\Rose\Entity\TocEntryWithMetadata;
use S2\Rose\Indexer;
use S2\Rose\Stemmer\PorterStemmerEnglish;
use S2\Rose\Storage\Database\PdoStorage;

final class RecommendationFinderTest extends Unit
{
    public function testFindsAndFormatsSimilarContentInSqlite(): void
    {
        $pdo     = new \PDO('sqlite::memory:');
        $storage = new PdoStorage($pdo, 'recommendation_test_');
        $storage->erase();

        $indexer = new Indexer($storage, new PorterStemmerEnglish());
        $indexer->index(
            (new Indexable('source', 'Source document', '<p>Alpha beta gamma delta. Originonly prose.</p>', 1))
                ->setUrl('/source')
        );
        $indexer->index(
            (new Indexable(
                'strong',
                'Strong recommendation',
                '<p>Alpha beta <i>gamma</i> delta alpha. Strongonly details.</p><img src="/strong.jpg" width="640" height="320" alt="">',
                1,
            ))
                ->setUrl('/strong')
                ->setDate(new \DateTime('2024-05-10 12:30:00', new \DateTimeZone('Europe/Moscow')))
        );
        $indexer->index(
            (new Indexable('weak', 'Weak recommendation', '<p>Alpha beta epsilon zeta. Weakonly details.</p>', 1))
                ->setUrl('/weak')
        );
        $indexer->index(
            (new Indexable('other-instance', 'Other instance', '<p>Alpha beta gamma delta. Crossinstance text.</p>', 2))
                ->setUrl('/other-instance')
        );

        $finder = new RecommendationFinder($storage, $pdo, 'sqlite', 'recommendation_test_');

        $recommendations = $finder->getSimilar(new ExternalId('source', 1), false, 1, 4, 10);

        self::assertCount(1, $recommendations);
        self::assertSame('strong', $recommendations[0]['external_id']);
        self::assertSame('Alpha beta gamma delta alpha.', $recommendations[0]['snippet']);
        self::assertSame('Strongonly details.', $recommendations[0]['snippet2']);
        self::assertGreaterThan(0.0, (float)$recommendations[0]['relevance']);

        $tocWithMetadata = $recommendations[0]['tocWithMetadata'];
        self::assertInstanceOf(TocEntryWithMetadata::class, $tocWithMetadata);
        self::assertSame('Strong recommendation', $tocWithMetadata->getTocEntry()->getTitle());
        self::assertSame('/strong', $tocWithMetadata->getTocEntry()->getUrl());
        self::assertSame('2024-05-10T12:30:00+03:00', $tocWithMetadata->getTocEntry()->getDate()?->format(DATE_ATOM));
        $images = [];
        foreach ($tocWithMetadata->getImgCollection() as $image) {
            $images[] = $image;
        }
        self::assertCount(1, $images);
        self::assertSame('/strong.jpg', $images[0]->getSrc());

        $formatted = $finder->getSimilar(new ExternalId('source', 1), true, 1, 4, 10);
        self::assertSame('Alpha beta <i>gamma</i> delta alpha.', $formatted[0]['snippet']);

        $relaxed = $finder->getSimilar(new ExternalId('source', 1), false, 1, 2, 10);
        self::assertSame(['strong', 'weak'], array_column($relaxed, 'external_id'));

        $allInstances = $finder->getSimilar(new ExternalId('source', 1), false, null, 4, 10);
        self::assertSame(['strong', 'other-instance'], array_column($allInstances, 'external_id'));
    }
}
