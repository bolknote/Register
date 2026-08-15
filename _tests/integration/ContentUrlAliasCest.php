<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Url\ContentSlugService;
use Register\Url\ContentUrlAliasRepository;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Response;

final class ContentUrlAliasCest
{
    public function historicalPathsRedirectStraightToTheCurrentSlug(\IntegrationTester $I): void
    {
        $postId = $this->insertPost($I, 'alias-target');
        $aliases = $I->grabService(ContentUrlAliasRepository::class);
        foreach ([
            'old-flat-address',
            '2004/07/19/~1004',
            'register/19.07.2004/1',
            '19.07.2004/1',
            'comments/1090208592',
            'all/1004',
        ] as $path) {
            $aliases->add(ContentId::post($postId), $path);
        }

        foreach ([
            '/old-flat-address',
            '/2004/07/19/~1004',
            '/register/19.07.2004/1',
            '/19.07.2004/1',
            '/comments/1090208592',
            '/all/1004/',
        ] as $path) {
            $I->amOnPage($path);
            $I->seeResponseCodeIs(Response::HTTP_MOVED_PERMANENTLY);
            $I->seeLocationIs('/alias-target');
        }

        $I->amOnPage('/2004/07/19/~1004?from=archive');
        $I->seeResponseCodeIs(Response::HTTP_MOVED_PERMANENTLY);
        $I->seeLocationIs('/alias-target?from=archive');
    }

    public function canonicalChangeRetainsThePreviousSlugAndAllowsARevert(\IntegrationTester $I): void
    {
        $postId = $this->insertPost($I, 'first-address');
        $dbLayer = $I->grabService(DbLayer::class);
        $aliases = $I->grabService(ContentUrlAliasRepository::class);
        $slugService = $I->grabService(ContentSlugService::class);

        $dbLayer->update(ContentSchema::TABLE_NAME)
            ->set('slug', ':slug')->setParameter('slug', 'second-address')
            ->where('id = :id')->setParameter('id', $postId)
            ->execute();
        $aliases->rememberCanonicalChange(ContentId::post($postId), 'first-address', 'second-address');

        $I->assertSame(ContentSlugService::STATUS_OK, $slugService->postStatus($postId, 'first-address'));
        $I->assertSame(ContentSlugService::STATUS_NOT_UNIQUE, $slugService->postStatus(0, 'first-address'));
        $I->amOnPage('/first-address');
        $I->seeResponseCodeIs(Response::HTTP_MOVED_PERMANENTLY);
        $I->seeLocationIs('/second-address');

        $dbLayer->update(ContentSchema::TABLE_NAME)
            ->set('slug', ':slug')->setParameter('slug', 'first-address')
            ->where('id = :id')->setParameter('id', $postId)
            ->execute();
        $aliases->rememberCanonicalChange(ContentId::post($postId), 'second-address', 'first-address');

        $I->amOnPage('/second-address');
        $I->seeResponseCodeIs(Response::HTTP_MOVED_PERMANENTLY);
        $I->seeLocationIs('/first-address');
        $I->amOnPage('/first-address');
        $I->seeResponseCodeIs(Response::HTTP_OK);
    }

    public function nestedImportedPostPathStaysCanonicalAndItsAliasesRedirectToIt(\IntegrationTester $I): void
    {
        $postId = $this->insertPost($I, 'all/1004');
        $aliases = $I->grabService(ContentUrlAliasRepository::class);
        $aliases->add(ContentId::post($postId), '2004/07/19/~1004');

        $I->amOnPage('/all/1004');
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $I->amOnPage('/1004');
        $I->seeResponseCodeIs(Response::HTTP_NOT_FOUND);

        $I->amOnPage('/2004/07/19/~1004');
        $I->seeResponseCodeIs(Response::HTTP_MOVED_PERMANENTLY);
        $I->seeLocationIs('/all/1004');
    }

    private function insertPost(\IntegrationTester $I, string $slug): int
    {
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer->insert(ContentSchema::TABLE_NAME)
            ->values([
                'content_type' => ':content_type',
                'slug_scope'   => "'root'",
                'slug'         => ':slug',
                'title'        => ':slug',
                'excerpt'      => "''",
                'body'         => "'<p>Alias target</p>'",
                'created_at'   => '1',
                'published_at' => '1',
                'updated_at'   => '1',
                'published'    => '1',
            ])
            ->execute([
                'content_type' => ContentType::POST->value,
                'slug'         => $slug,
            ]);

        return (int)$dbLayer->insertId();
    }
}
