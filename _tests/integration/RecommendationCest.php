<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Psr\Cache\CacheItemPoolInterface;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
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
            false,
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
        $I->assertSame(0, (int)$queued);

        [$recommendations, $log, $rawRecommendations] = $provider->getRecommendations(
            '/source',
            $externalId,
            true,
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

    public function hidesWarmRecommendationsFromCrawlersWithoutChangingReaderPages(\IntegrationTester $I): void
    {
        $I->setConfigValue('REGISTER_SEARCH_RECOMMENDATIONS_LIMIT', '10');

        /** @var CacheItemPoolInterface $cache */
        $cache = $I->grabService('recommendations_cache');
        $I->assertTrue($cache->clear());

        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':type')->setParameter('type', ContentType::POST->value)
            ->setValue('slug_scope', "'root'")
            ->setValue('created_at', '1700000001')
            ->setValue('published_at', '1700000001')
            ->setValue('updated_at', '1700000001')
            ->setValue('revision', '1')
            ->setValue('title', "'Source document'")
            ->setValue('excerpt', "''")
            ->setValue('body', "'<p>Quasar nebula lanthanum aubergine. Sourceonly prose.</p>'")
            ->setValue('published', '1')
            ->setValue('featured', '0')
            ->setValue('comments_enabled', '1')
            ->setValue('series', "''")
            ->setValue('slug', "'recommendation-source'")
            ->setValue('author_id', 'NULL')
            ->execute();
        $postId = (int)$dbLayer->insertId();

        /** @var Indexer $indexer */
        $indexer = $I->grabService(Indexer::class);
        $indexer->index(
            (new Indexable('post:' . $postId, 'Source document', '<p>Quasar nebula lanthanum aubergine. Sourceonly prose.</p>'))
                ->setUrl('/recommendation-source'),
        );
        $indexer->index(
            (new Indexable('page:20', 'Related reading', '<p>Quasar nebula lanthanum aubergine. Useful details.</p>'))
                ->setUrl('/related')
                ->setDate(new \DateTime('2024-04-05')),
        );

        /** @var RecommendationProvider $provider */
        $provider = $I->grabService(RecommendationProvider::class);
        [$recommendations] = $provider->getRecommendations(
            '/recommendation-source',
            new ExternalId('post:' . $postId),
            true,
        );
        $I->assertNotEmpty($recommendations);

        $I->sendRequestWithHeaders('/recommendation-source', [
            'User-Agent' => 'Mozilla/5.0 (compatible; QlyzeBot/1.0)',
        ]);
        $I->seeHttpHeader('X-Register-Page-Cache', 'miss');
        $I->dontSeeElement('.recommendations');
        $I->assertStringNotContainsString('register-deferred-recommendations', $I->grabResponse());

        $I->sendRequestWithHeaders('/recommendation-source', [
            'User-Agent' => 'Mozilla/5.0 (compatible; QlyzeBot/1.0)',
        ]);
        $I->seeHttpHeader('X-Register-Page-Cache', 'hit');
        $I->dontSeeElement('.recommendations');

        $I->sendRequestWithHeaders('/recommendation-source', [
            'User-Agent' => 'Mozilla/5.0 (compatible; QlyzeBot/1.0)',
            'X-Register-Navigation' => 'partial',
        ]);
        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertIsArray($payload);
        $I->assertIsString($payload['fragment'] ?? null);
        $I->assertStringNotContainsString('recommendation-title', $payload['fragment']);
        $I->assertStringNotContainsString('register-deferred-recommendations', $payload['fragment']);

        $I->sendRequestWithHeaders('/recommendation-source', [
            'User-Agent' => 'Mozilla/5.0 integration browser',
        ]);
        $I->seeElement('.recommendations');
        $I->see('Related reading', '.recommendations');

        $I->assertTrue($cache->clear());
    }
}
