<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Core\Controller\Rss\FeedSettings;
use Register\Module\Analytics\AnalyticsRecorder;
use Register\Core\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Response;

final class ContentRssCest
{
    public function redirectsTheHistoricalAddressToTheCanonicalFeed(\IntegrationTester $I): void
    {
        $I->amOnPage('/rss.xml?reader=legacy');
        $I->seeResponseCodeIs(Response::HTTP_MOVED_PERMANENTLY);
        $I->seeLocationIs('/rss?reader=legacy');

        $I->amOnPage('/rss/?reader=trailing-slash');
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->seeHttpHeader('Content-Type', 'application/rss+xml; charset=utf-8');
        $I->assertStringContainsString(
            '<atom:link href="http://register.localhost/rss" rel="self"',
            $I->grabResponse(),
        );
    }

    public function publishesTheConfiguredNumberOfPostsAndRecordsTheBlogFeed(\IntegrationTester $I): void
    {
        $I->setConfigValue(FeedSettings::ITEM_LIMIT_CONFIG_KEY, '3');
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $latestPostId = 0;
        for ($number = 1; $number <= 4; ++$number) {
            $latestPostId = $this->insertContent(
                $dbLayer,
                ContentType::POST,
                $number === 4 ? 'RSS & post 4' : 'RSS post ' . $number,
                'rss-post-' . $number,
                true,
                1_700_000_000 + $number,
                $number === 4
                    ? '<style>.feed-only { color: red; }</style><p>RSS post 4 body <nobr>$$x^2$$</nobr></p>'
                    : null,
            );
        }

        /** @var TagRepository $tagRepository */
        $tagRepository = $I->grabService(TagRepository::class);
        $tagRepository->replace(
            ContentId::post($latestPostId),
            $tagRepository->findOrCreateIdsByNames(['RSS & tag']),
        );

        $this->insertContent($dbLayer, ContentType::POST, 'RSS draft', 'rss-draft', false, 1_700_000_100);
        $this->insertContent($dbLayer, ContentType::PAGE, 'RSS page', 'rss-page', true, 1_700_000_200);

        $I->sendRequestWithHeaders('/rss', ['User-Agent' => 'Register RSS integration reader']);
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->seeHttpHeader('Content-Type', 'application/rss+xml; charset=utf-8');

        $cacheControl = $I->grabHttpHeader('Cache-Control');
        $I->assertNotNull($cacheControl);
        $I->assertStringContainsString('public', $cacheControl);
        $I->assertStringContainsString('max-age=600', $cacheControl);

        $xml = $I->grabResponse();
        $I->assertStringContainsString('<atom:link href="http://register.localhost/rss" rel="self"', $xml);
        $I->assertStringNotContainsString('/rss.xml', $xml);
        $I->assertStringContainsString('<language>en</language>', $xml);
        $I->assertStringContainsString('<docs>https://www.rssboard.org/rss-specification</docs>', $xml);
        $I->assertStringContainsString('<title>RSS &amp; post 4</title>', $xml);
        $I->assertStringContainsString('<category>RSS &amp; tag</category>', $xml);
        $I->assertStringNotContainsString('<title>RSS &amp;amp; post 4</title>', $xml);
        $I->assertStringContainsString('/rss-post-4', $xml);
        $I->assertStringNotContainsString('RSS post 1</title>', $xml);
        $I->assertStringNotContainsString('RSS draft', $xml);
        $I->assertStringNotContainsString('RSS page', $xml);
        $I->assertStringContainsString('$$x^2$$', $xml);
        $I->assertStringNotContainsString('<img', $xml);
        $I->assertStringNotContainsString('&lt;nobr', $xml);
        $I->assertStringNotContainsString('&lt;style', $xml);
        $I->assertSame(3, substr_count($xml, '<item>'));

        $document = new \DOMDocument();
        $I->assertTrue($document->loadXML($xml));

        $etag = $I->grabHttpHeader('ETag');
        $I->assertNotNull($etag);
        $I->assertNull($I->grabHttpHeader('Last-Modified'));
        $I->sendRequestWithHeaders('/rss', ['If-None-Match' => $etag]);
        $I->seeResponseCodeIs(Response::HTTP_NOT_MODIFIED);

        $this->insertContent(
            $dbLayer,
            ContentType::POST,
            'RSS post 5',
            'rss-post-5',
            true,
            1_700_000_005,
        );
        $I->sendRequestWithHeaders('/rss', ['If-None-Match' => $etag]);
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $updatedXml = $I->grabResponse();
        $I->assertSame(3, substr_count($updatedXml, '<item>'));
        $I->assertStringContainsString('RSS post 5', $updatedXml);
        $I->assertStringContainsString('RSS &amp; post 4', $updatedXml);
        $I->assertNotSame($etag, $I->grabHttpHeader('ETag'));

        $channel = $dbLayer
            ->select('channel')
            ->from('register_analytics_daily')
            ->where('channel = :channel')->setParameter('channel', AnalyticsRecorder::BLOG_FEED_CHANNEL)
            ->execute()
            ->result()
        ;
        $I->assertSame(AnalyticsRecorder::BLOG_FEED_CHANNEL, $channel);
    }

    private function insertContent(
        DbLayer     $dbLayer,
        ContentType $contentType,
        string      $title,
        string      $slug,
        bool        $published,
        int         $timestamp,
        ?string     $body = null,
    ): int {
        $dbLayer->insert(ContentSchema::TABLE_NAME)->values([
            'content_type' => ':content_type',
            'parent_id'    => 'NULL',
            'slug_scope'   => $contentType === ContentType::POST ? "'root'" : "'main'",
            'slug'         => ':slug',
            'title'        => ':title',
            'excerpt'      => "''",
            'body'         => ':body',
            'created_at'   => ':timestamp',
            'published_at' => ':timestamp',
            'updated_at'   => ':timestamp',
            'published'    => ':published',
        ])->execute([
            'content_type' => $contentType->value,
            'slug'         => $slug,
            'title'        => $title,
            'body'         => $body ?? '<p>' . $title . ' body</p>',
            'timestamp'    => $timestamp,
            'published'    => (int)$published,
        ]);

        return (int)$dbLayer->insertId();
    }
}
