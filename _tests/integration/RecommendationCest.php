<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Psr\Cache\CacheItemPoolInterface;
use Register\Core\Pdo\DbLayer;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Module\Search\Service\RecommendationProvider;
use Register\Rose\Entity\ExternalId;
use Register\Rose\Entity\Indexable;
use Register\Rose\Indexer;

/** @group search */
final class RecommendationCest
{
    public function tryToFindRecommendationsOnSqlite(\IntegrationTester $I): void
    {
        $I->setConfigValue('REGISTER_SEARCH_RECOMMENDATIONS_LIMIT', '10');

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
        $externalId = new ExternalId('post:10');
        [$recommendations, $log, $rawRecommendations] = $provider->getRecommendations(
            '/source',
            $externalId,
        );

        $I->assertSame([], $recommendations);
        $I->assertSame([], $log);
        $I->assertSame([], $rawRecommendations);

        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $queued = $dbLayer->select('COUNT(*)')->from('queue')
            ->where('id = :id')->setParameter('id', $externalId->toString())
            ->andWhere('code = :code')->setParameter('code', RecommendationProvider::RECOMMENDATIONS_QUEUE)
            ->execute()
            ->result()
        ;
        $I->assertSame(1, (int)$queued);

        $provider->handle(
            $externalId->toString(),
            RecommendationProvider::RECOMMENDATIONS_QUEUE,
            [],
            new QueueExecutionBudget(5.0),
        );
        [$recommendations, $log, $rawRecommendations] = $provider->getRecommendations(
            '/source',
            $externalId,
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
