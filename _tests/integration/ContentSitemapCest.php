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
use S2\Cms\Pdo\DbLayer;
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

        $xml = $I->grabResponse();

        $I->assertStringContainsString('/sitemap-page', $xml);
        $I->assertStringContainsString('/sitemap-post', $xml);
        $I->assertStringContainsString(gmdate('c', 1_700_000_300), $xml);
        $I->assertStringNotContainsString('/sitemap-draft', $xml);
        $I->assertSame('text/xml; charset=utf-8', $I->grabHttpHeader('Content-Type'));

        $lastModified = $I->grabHttpHeader('Last-Modified');
        $I->assertNotNull($lastModified);
        $I->sendRequestWithHeaders('/sitemap.xml', ['If-Modified-Since' => $lastModified]);
        $I->seeResponseCodeIs(Response::HTTP_NOT_MODIFIED);
    }

    private function insertContent(
        DbLayer     $dbLayer,
        ContentType $contentType,
        string      $slug,
        bool        $published,
        int         $publishedAt,
        int         $updatedAt,
    ): void {
        $dbLayer->insert(ContentSchema::TABLE_NAME)->values([
            'content_type' => ':content_type',
            'parent_id'    => 'NULL',
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
            'slug'         => $slug,
            'title'        => $slug,
            'published_at' => $publishedAt,
            'updated_at'   => $updatedAt,
            'published'    => (int)$published,
        ]);
    }
}
