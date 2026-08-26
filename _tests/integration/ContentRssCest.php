<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentSchema;
use Register\Content\ContentType;
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
        $I->seeResponseCodeIs(Response::HTTP_MOVED_PERMANENTLY);
        $I->seeLocationIs('/rss?reader=trailing-slash');
    }

    public function publishesOnlyTheTenNewestPostsAndRecordsTheBlogFeed(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        for ($number = 1; $number <= 11; ++$number) {
            $this->insertContent(
                $dbLayer,
                ContentType::POST,
                $number === 11 ? 'RSS & post 11' : 'RSS post ' . $number,
                'rss-post-' . $number,
                true,
                1_700_000_000 + $number,
                $number === 11
                    ? '<style>.feed-only { color: red; }</style><p>RSS post 11 body <nobr>$$x^2$$</nobr></p>'
                    : null,
            );
        }

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
        $I->assertStringContainsString('<title>RSS &amp; post 11</title>', $xml);
        $I->assertStringNotContainsString('&amp;amp;', $xml);
        $I->assertStringContainsString('/rss-post-11', $xml);
        $I->assertStringNotContainsString('RSS post 1</title>', $xml);
        $I->assertStringNotContainsString('RSS draft', $xml);
        $I->assertStringNotContainsString('RSS page', $xml);
        $I->assertStringContainsString('$$x^2$$', $xml);
        $I->assertStringNotContainsString('<img', $xml);
        $I->assertStringNotContainsString('&lt;nobr', $xml);
        $I->assertStringNotContainsString('&lt;style', $xml);
        $I->assertSame(10, substr_count($xml, '<item>'));

        $document = new \DOMDocument();
        $I->assertTrue($document->loadXML($xml));

        $lastModified = $I->grabHttpHeader('Last-Modified');
        $I->assertNotNull($lastModified);
        $I->sendRequestWithHeaders('/rss', ['If-Modified-Since' => $lastModified]);
        $I->seeResponseCodeIs(Response::HTTP_NOT_MODIFIED);

        $this->insertContent(
            $dbLayer,
            ContentType::POST,
            'RSS post 12',
            'rss-post-12',
            true,
            1_700_000_012,
        );
        $I->sendRequestWithHeaders('/rss', ['If-Modified-Since' => $lastModified]);
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $updatedXml = $I->grabResponse();
        $I->assertSame(10, substr_count($updatedXml, '<item>'));
        $I->assertStringContainsString('RSS post 12', $updatedXml);
        $I->assertStringContainsString('RSS &amp; post 11', $updatedXml);

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
    ): void {
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
    }
}
