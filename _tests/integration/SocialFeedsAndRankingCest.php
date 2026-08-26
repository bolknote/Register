<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Content\ContentRepository;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Content\ContentViewRepository;
use Register\Content\TagRepository;
use Register\Core\Pdo\DbLayer;
use Register\Module\Search\Service\SearchDocumentFactory;
use Register\Rose\Indexer;
use Symfony\Component\HttpFoundation\Response;

/** @group search */
final class SocialFeedsAndRankingCest
{
    public function publishesSocialMetadataAndContextualFeeds(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var TagRepository $tagRepository */
        $tagRepository = $I->grabService(TagRepository::class);

        $postId = $this->insertPost(
            $dbLayer,
            'JSON Feed constellation',
            'json-feed-constellation',
            time(),
            false,
            '<p>Quasarfeed discovery <a href="/about">About</a>.</p><img src="/media/body-card.jpg" alt="">',
            'A concise social description.',
            '/media/social-card.jpg',
        );
        $tagId = $this->insertTag($dbLayer, 'Feed topic', 'feed-topic');
        $tagRepository->replace(ContentId::post($postId), [$tagId]);

        $I->amOnPage('/json-feed-constellation');
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->seeElement('meta[property="og:type"][content="article"]');
        $I->seeElement('meta[property="og:description"][content="A concise social description."]');
        $I->seeElement('meta[property="og:image"][content="http://register.localhost/media/social-card.jpg"]');
        $I->seeElement('meta[name="twitter:card"][content="summary_large_image"]');
        $I->seeElement('link[rel="alternate"][type="application/feed+json"][href="/feed.json"]');

        $I->amOnPage('/feed.json');
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->assertStringStartsWith('application/feed+json', (string)$I->grabHttpHeader('Content-Type'));

        $feed = $this->decodeFeed($I->grabResponse());
        $I->assertSame('https://jsonfeed.org/version/1.1', $feed['version']);
        $I->assertSame('http://register.localhost/feed.json', $feed['feed_url']);

        $item = $this->findFeedItem($feed, 'JSON Feed constellation');
        $I->assertSame('http://register.localhost/json-feed-constellation', $item['url']);
        $I->assertSame('http://register.localhost/media/social-card.jpg', $item['image']);
        $I->assertSame(['Feed topic'], $item['tags']);
        $I->assertStringContainsString('href="http://register.localhost/about"', $item['content_html']);

        $I->amOnPage('/tags/feed-topic/');
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->seeElement('link[rel="alternate"][type="application/rss+xml"][href="/tags/feed-topic/rss"]');
        $I->seeElement('link[rel="alternate"][type="application/feed+json"][href="/tags/feed-topic/feed.json"]');

        $I->amOnPage('/tags/feed-topic/rss');
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->assertStringContainsString('JSON Feed constellation', $I->grabResponse());

        $I->amOnPage('/tags/feed-topic/feed.json');
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $tagFeed = $this->decodeFeed($I->grabResponse());
        $I->assertSame('http://register.localhost/tags/feed-topic/', $tagFeed['home_page_url']);
        $I->assertSame('JSON Feed constellation', $tagFeed['items'][0]['title']);

        $dbLayer->update(ContentSchema::TABLE_NAME)
            ->set('social_image', "''")
            ->where('id = :id')->setParameter('id', $postId)
            ->execute();
        $I->amOnPage('/json-feed-constellation?source=social');
        $I->seeElement('meta[property="og:image"][content="http://register.localhost/media/body-card.jpg"]');
        $I->seeElement('meta[property="og:url"][content="http://register.localhost/json-feed-constellation"]');

        $dbLayer->update(ContentSchema::TABLE_NAME)
            ->set('body', "'<p>Body without an image.</p>'")
            ->where('id = :id')->setParameter('id', $postId)
            ->execute();
        $I->setConfigValue('REGISTER_SOCIAL_IMAGE', '/media/site-card.jpg');
        $I->amOnPage('/json-feed-constellation');
        $I->seeElement('meta[property="og:image"][content="http://register.localhost/media/site-card.jpg"]');
    }

    public function publishesSearchResultsAsRssAndJsonFeed(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $postId = $this->insertPost(
            $dbLayer,
            'Search feed result',
            'search-feed-result',
            time(),
            false,
            '<p>The unique quasarfeedneedle belongs in a saved search.</p>',
        );

        /** @var ContentRepository $contentRepository */
        $contentRepository = $I->grabService(ContentRepository::class);
        $content = $contentRepository->find(ContentId::post($postId));
        if (!$content instanceof ContentItem) {
            throw new \RuntimeException('The search-feed fixture is not exposed by the content repository.');
        }

        /** @var Indexer $indexer */
        $indexer = $I->grabService(Indexer::class);
        /** @var SearchDocumentFactory $factory */
        $factory = $I->grabService(SearchDocumentFactory::class);
        $indexer->index($factory->create($content));

        try {
            $I->amOnPage('/search?q=quasarfeedneedle');
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $I->seeElement('link[rel="alternate"][type="application/rss+xml"][href="/search/rss?q=quasarfeedneedle"]');
            $I->seeElement('link[rel="alternate"][type="application/feed+json"][href="/search/feed.json?q=quasarfeedneedle"]');

            $I->amOnPage('/search/rss?q=quasarfeedneedle');
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $searchRss = $I->grabResponse();
            $I->assertStringContainsString('Search feed result', $searchRss);
            $I->assertStringContainsString(
                '<atom:link href="http://register.localhost/search/rss?q=quasarfeedneedle" rel="self"',
                $searchRss,
            );

            $I->amOnPage('/search/feed.json?q=quasarfeedneedle');
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $feed = $this->decodeFeed($I->grabResponse());
            $I->assertSame('http://register.localhost/search/feed.json?q=quasarfeedneedle', $feed['feed_url']);
            $I->assertSame('Search feed result', $feed['items'][0]['title']);
        } finally {
            $indexer->removeById(SearchDocumentFactory::externalId(ContentId::post($postId)), null);
        }
    }

    public function countsViewsAndRendersPopularHotAndRandomNavigation(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var ContentViewRepository $views */
        $views = $I->grabService(ContentViewRepository::class);

        $oldPopularId = $this->insertPost($dbLayer, 'Old popular post', 'old-popular-post', 1_700_000_001);
        $hotId = $this->insertPost($dbLayer, 'Hot post', 'hot-post', 1_700_000_004);
        $featuredId = $this->insertPost($dbLayer, 'Featured post', 'featured-post', 1_700_000_002, true);
        $normalId = $this->insertPost($dbLayer, 'Normal post', 'normal-post', 1_700_000_003);

        $this->recordViews($views, ContentId::post($oldPopularId), 12, '2020-01-01');
        $this->recordViews($views, ContentId::post($hotId), 10);
        $this->recordViews($views, ContentId::post($featuredId), 4);
        $this->recordViews($views, ContentId::post($normalId), 4);

        $popular = $views->popularPostIds(100);
        $I->assertLessThan(
            array_search($normalId, $popular, true),
            array_search($featuredId, $popular, true),
            'An equally viewed featured post must rank above a normal post.',
        );
        $I->assertSame($hotId, $views->hotPostIds(100)[0]);

        $I->amOnPage('/popular/');
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $popularHtml = $I->grabResponse();
        $I->assertLessThan(strpos($popularHtml, 'Hot post'), strpos($popularHtml, 'Old popular post'));
        $I->seeElement('.post-foot-meta > .post-foot-views[aria-label="12 views"]');
        $I->see('12', '.post-foot-views-count');

        $I->amOnPage('/hot/');
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $hotHtml = $I->grabResponse();
        $I->assertLessThan(strpos($hotHtml, 'Featured post'), strpos($hotHtml, 'Hot post'));
        $I->assertStringNotContainsString('Old popular post', $hotHtml);

        $I->amOnPage('/random/');
        $I->seeResponseCodeIs(Response::HTTP_FOUND);
        $I->assertContains($I->grabHttpHeader('Location'), [
            '/old-popular-post',
            '/hot-post',
            '/featured-post',
            '/normal-post',
        ]);

        $before = $views->total(ContentId::post($oldPopularId));
        $I->sendRequestWithHeaders('/old-popular-post', ['User-Agent' => 'Googlebot/2.1']);
        $I->assertSame($before, $views->total(ContentId::post($oldPopularId)));
        $I->sendRequestWithHeaders('/old-popular-post', ['User-Agent' => 'Mozilla/5.0 Register integration']);
        $I->assertSame($before + 1, $views->total(ContentId::post($oldPopularId)));

        $dbLayer->delete(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $normalId)
            ->execute();
        $I->assertSame(0, $views->total(ContentId::post($normalId)));
    }

    private function insertPost(
        DbLayer $dbLayer,
        string $title,
        string $slug,
        int $publishedAt,
        bool $featured = false,
        string $body = '<p>Post body</p>',
        string $description = '',
        string $socialImage = '',
    ): int {
        $dbLayer->insert(ContentSchema::TABLE_NAME)->values([
            'content_type'     => ':content_type',
            'slug_scope'       => "'root'",
            'created_at'       => ':published_at',
            'published_at'     => ':published_at',
            'updated_at'       => ':published_at',
            'revision'         => '1',
            'title'            => ':title',
            'excerpt'          => "''",
            'body'             => ':body',
            'published'        => '1',
            'featured'         => ':featured',
            'comments_enabled' => '1',
            'series'           => "''",
            'slug'             => ':slug',
            'author_id'        => 'NULL',
            'meta_description' => ':description',
            'social_image'     => ':social_image',
        ])->execute([
            'content_type' => ContentType::POST->value,
            'published_at' => $publishedAt,
            'title' => $title,
            'body' => $body,
            'featured' => (int)$featured,
            'slug' => $slug,
            'description' => $description,
            'social_image' => $socialImage,
        ]);

        return (int)$dbLayer->insertId();
    }

    private function insertTag(DbLayer $dbLayer, string $name, string $slug): int
    {
        $dbLayer->insert('tags')->values([
            'name' => ':name',
            'description' => "''",
            'modify_time' => ':modify_time',
            'url' => ':slug',
        ])->execute([
            'name' => $name,
            'modify_time' => time(),
            'slug' => $slug,
        ]);

        return (int)$dbLayer->insertId();
    }

    private function recordViews(ContentViewRepository $repository, ContentId $contentId, int $count, ?string $day = null): void
    {
        for ($number = 0; $number < $count; ++$number) {
            $repository->record($contentId, $day);
        }
    }

    /** @return array<string, mixed> */
    private function decodeFeed(string $response): array
    {
        $feed = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        if (!\is_array($feed)) {
            throw new \RuntimeException('The JSON Feed response is not an object.');
        }

        return $feed;
    }

    /**
     * @param array<string, mixed> $feed
     * @return array<string, mixed>
     */
    private function findFeedItem(array $feed, string $title): array
    {
        foreach ($feed['items'] ?? [] as $item) {
            if (\is_array($item) && ($item['title'] ?? null) === $title) {
                return $item;
            }
        }

        throw new \RuntimeException('The expected JSON Feed item was not found.');
    }
}
