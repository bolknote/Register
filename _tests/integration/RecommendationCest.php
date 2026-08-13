<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Psr\Cache\CacheItemPoolInterface;
use Register\Module\Search\Service\RecommendationProvider;
use S2\Rose\Entity\ExternalId;
use S2\Rose\Entity\Indexable;
use S2\Rose\Indexer;

/** @group search */
final class RecommendationCest
{
    public function tryToFindRecommendationsOnSqlite(\IntegrationTester $I): void
    {
        $I->setConfigValue('S2_SEARCH_RECOMMENDATIONS_LIMIT', '10');

        $cache = $I->grabService('recommendations_cache');
        if (!$cache instanceof CacheItemPoolInterface) {
            throw new \RuntimeException('The recommendations cache service is unavailable.');
        }
        $I->assertTrue($cache->clear());

        /** @var Indexer $indexer */
        $indexer = $I->grabService(Indexer::class);
        $indexer->index(
            (new Indexable('post:10', 'Source document', '<p>Alpha beta gamma delta. Sourceonly prose.</p>'))
                ->setUrl('/source')
        );
        $indexer->index(
            (new Indexable('page:11', 'Related reading', '<p>Alpha beta gamma delta. Useful details.</p>'))
                ->setUrl('/related')
                ->setDate(new \DateTime('2024-04-05'))
        );

        /** @var RecommendationProvider $provider */
        $provider = $I->grabService(RecommendationProvider::class);
        [$recommendations, $log, $rawRecommendations] = $provider->getRecommendations(
            '/source',
            new ExternalId('post:10'),
        );

        $I->assertNotEmpty($log);
        $I->assertCount(1, $rawRecommendations);
        $I->assertCount(1, $recommendations);
        $I->assertSame('Related reading', $recommendations[0]['title']);
        $I->assertSame('/related', $recommendations[0]['url']);
        $I->assertSame('Alpha beta gamma delta. Useful details.', $recommendations[0]['snippet']);
        $I->assertSame('2024', $recommendations[0]['date']?->format('Y'));

        $I->assertTrue($cache->clear());
    }
}
