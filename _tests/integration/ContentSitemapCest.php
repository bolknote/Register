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
use Register\Content\PageContentSource;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\PDO;
use Symfony\Component\HttpFoundation\Response;

final class ContentSitemapCest
{
    public function containsPublishedPagesAndPostsFromCanonicalSources(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertContent($dbLayer, ContentType::PAGE, 'sitemap-page', true, 1_700_000_000, 1_700_000_100);
        $this->insertContent($dbLayer, ContentType::POST, 'sitemap-post', true, 1_700_000_200, 1_700_000_300);
        $this->insertContent($dbLayer, ContentType::POST, 'sitemap-draft', false, 1_700_000_400, 1_700_000_500);

        $I->amOnPage('/sitemap.xml');
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $indexXml = $I->grabResponse();
        $indexDocument = new \DOMDocument();
        $I->assertTrue($indexDocument->loadXML($indexXml));
        $I->assertStringContainsString(
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
            $indexXml,
        );
        $I->assertStringContainsString('<loc>http://register.localhost/sitemap-1.xml</loc>', $indexXml);
        $I->assertSame('application/xml; charset=utf-8', $I->grabHttpHeader('Content-Type'));

        $indexEtag = $I->grabHttpHeader('ETag');
        $I->assertNotNull($indexEtag);
        $I->sendRequestWithHeaders('/sitemap.xml', ['If-None-Match' => $indexEtag]);
        $I->seeResponseCodeIs(Response::HTTP_NOT_MODIFIED);
        /** @var PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $I->assertSame([], $pdo->getQueryLog(), 'A cached sitemap index must not query the database.');

        $I->amOnPage('/sitemap-1.xml');
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $xml = $I->grabResponse();
        $document = new \DOMDocument();
        $I->assertTrue($document->loadXML($xml));

        $I->assertStringContainsString('/sitemap-page', $xml);
        $I->assertStringContainsString('/sitemap-post', $xml);
        $I->assertStringContainsString(gmdate('c', 1_700_000_300), $xml);
        $I->assertStringNotContainsString('/sitemap-draft', $xml);
        $I->assertStringNotContainsString('<priority>', $xml);
        $I->assertStringNotContainsString('<changefreq>', $xml);
        $I->assertSame('application/xml; charset=utf-8', $I->grabHttpHeader('Content-Type'));

        $etag = $I->grabHttpHeader('ETag');
        $I->assertNotNull($etag);
        $I->sendRequestWithHeaders('/sitemap-1.xml', ['If-None-Match' => $etag]);
        $I->seeResponseCodeIs(Response::HTTP_NOT_MODIFIED);
        $I->assertSame([], $pdo->getQueryLog(), 'A cached sitemap part must not query the database.');

        $I->amOnPage('/sitemap-2.xml');
        $I->seeResponseCodeIs(Response::HTTP_NOT_FOUND);
    }

    public function traversesTheCompletePageTreeWithOneDatabaseQuery(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $parentId = $this->insertContent(
            $dbLayer,
            ContentType::PAGE,
            'single-query-parent',
            true,
            1_700_001_000,
            1_700_001_100,
        );
        $childId = $this->insertContent(
            $dbLayer,
            ContentType::PAGE,
            'single-query-child',
            true,
            1_700_001_200,
            1_700_001_300,
            $parentId,
        );

        /** @var PDO $pdo */
        $pdo = $I->grabService(\PDO::class);
        $pdo->cleanLogs();
        /** @var PageContentSource $source */
        $source = $I->grabService(PageContentSource::class);
        $items = iterator_to_array($source->published());

        $I->assertSame(1, $pdo->getQueryCount(), 'A page tree crawl must not issue one SQL query per parent.');
        $pathsById = [];
        foreach ($items as $item) {
            $pathsById[$item->id->value] = $item->path;
        }

        $I->assertSame('/single-query-parent/', $pathsById[$parentId] ?? null);
        $I->assertSame('/single-query-parent/single-query-child', $pathsById[$childId] ?? null);
    }

    public function robotsTxtAdvertisesTheSitemap(\IntegrationTester $I): void
    {
        $I->amOnPage('/robots.txt');
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->assertSame('text/plain; charset=utf-8', $I->grabHttpHeader('Content-Type'));
        $I->assertSame(
            "User-agent: *\nDisallow: /_admin/\nSitemap: http://register.localhost/sitemap.xml\n",
            $I->grabResponse(),
        );

        $etag = $I->grabHttpHeader('ETag');
        $I->assertNotNull($etag);
        $I->sendRequestWithHeaders('/robots.txt', ['If-None-Match' => $etag]);
        $I->seeResponseCodeIs(Response::HTTP_NOT_MODIFIED);
    }

    private function insertContent(
        DbLayer     $dbLayer,
        ContentType $contentType,
        string      $slug,
        bool        $published,
        int         $publishedAt,
        int         $updatedAt,
        ?int        $parentId = null,
    ): int {
        $dbLayer->insert(ContentSchema::TABLE_NAME)->values([
            'content_type' => ':content_type',
            'parent_id'    => ':parent_id',
            'slug_scope'   => $contentType === ContentType::POST ? "'root'" : "'main'",
            'slug'         => ':slug',
            'title'        => ':title',
            'excerpt'      => "''",
            'body'         => "'<p>Sitemap</p>'",
            'created_at'   => ':published_at',
            'published_at' => ':published_at',
            'updated_at'   => ':updated_at',
            'published'    => ':published',
        ])->execute([
            'content_type' => $contentType->value,
            'parent_id'    => $parentId,
            'slug'         => $slug,
            'title'        => $slug,
            'published_at' => $publishedAt,
            'updated_at'   => $updatedAt,
            'published'    => (int)$published,
        ]);

        return (int)$dbLayer->insertId();
    }
}
