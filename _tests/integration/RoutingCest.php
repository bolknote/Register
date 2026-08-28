<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use IntegrationTester;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Response;

class RoutingCest
{
    public function methodNotAllowedReturns405ForUnsupportedVerb(IntegrationTester $I): void
    {
        $I->sendRequestWithMethod('DELETE', 'https://localhost/some-path');
        $I->seeResponseCodeIs(Response::HTTP_METHOD_NOT_ALLOWED);

        $allow = $I->grabHttpHeader('Allow');
        $I->assertNotNull($allow, 'Allow header must be present on a 405 response');
        $I->assertStringContainsString('GET', $allow);
        $I->assertStringContainsString('POST', $allow);
        $I->assertStringContainsString('POST', $allow);
    }

    public function methodNotAllowedReturns405ForPutOnGetOnlyRoute(IntegrationTester $I): void
    {
        $I->sendRequestWithMethod('PUT', 'https://localhost/comment_unsubscribe');
        $I->seeResponseCodeIs(Response::HTTP_METHOD_NOT_ALLOWED);

        $allow = $I->grabHttpHeader('Allow');
        $I->assertNotNull($allow, 'Allow header must be present on a 405 response');
        $I->assertStringContainsString('GET', $allow);
    }

    public function getOnExistingRouteStillWorks(IntegrationTester $I): void
    {
        $I->amOnPage('/');
        $I->seeResponseCodeIs(Response::HTTP_OK);
    }

    public function archivesUseTheirOwnCanonicalNamespace(IntegrationTester $I): void
    {
        $publishedAt = (new \DateTimeImmutable('2023-08-12 11:32:00'))->getTimestamp();
        $I->grabService(DbLayer::class)
            ->insert(ContentSchema::TABLE_NAME)
            ->values([
                'content_type' => ':content_type',
                'slug_scope'   => "'root'",
                'slug'         => "'archive-routing-post'",
                'title'        => "'Archive routing post'",
                'excerpt'      => "''",
                'body'         => "'<p>Archive routing body</p>'",
                'created_at'   => ':published_at',
                'published_at' => ':published_at',
                'updated_at'   => ':published_at',
                'published'    => '1',
            ])
            ->execute([
                'content_type' => ContentType::POST->value,
                'published_at' => $publishedAt,
            ])
        ;

        $I->sendRequestWithMethod('GET', 'https://localhost/archive/2023/');
        $I->seeResponseCodeIs(Response::HTTP_OK);

        foreach (['/archive/2023/08/', '/archive/2023/08/12/'] as $path) {
            $I->sendRequestWithMethod('GET', 'https://localhost' . $path);
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $I->assertStringContainsString('Archive routing post', $I->grabResponse());
        }

        $I->sendRequestWithMethod('GET', 'https://localhost/2023/08/');
        $I->seeResponseCodeIs(Response::HTTP_NOT_FOUND);
    }
}
