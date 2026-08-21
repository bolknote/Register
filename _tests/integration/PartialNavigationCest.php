<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentSchema;
use Register\Content\ContentType;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Template\PartialPageResponse;
use Symfony\Component\HttpFoundation\Response;

final class PartialNavigationCest
{
    public function servesProgressiveNavigationWithoutChangingNormalPages(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $this->insertPost($dbLayer);

        $I->amOnPage('https://localhost/');
        $I->seeResponseCodeIs(Response::HTTP_OK);
        $I->seeElement('#register-page[data-register-page]');
        $I->assertStringContainsString('/_assets/register/partial-navigation.js', $I->grabResponse());
        $I->assertStringContainsString(PartialPageResponse::REQUEST_HEADER, (string)$I->grabHttpHeader('Vary'));

        $home = $this->requestPartial($I, 'https://localhost/');
        $I->assertSame(1, $home['version']);
        $I->assertSame('My blog', $home['title']);
        $I->assertSame('blog_main', $home['bodyClass']);
        $I->assertStringContainsString('<div id="register-page" data-register-page>', $home['fragment']);
        $I->assertStringContainsString('data-live-region="posts:0"', $home['fragment']);
        $I->assertStringContainsString('meta name="register-live-updates"', $home['head']);
        $I->assertNotEmpty(array_filter(
            $home['assets'],
            static fn(mixed $asset): bool => is_string($asset)
                && parse_url($asset, PHP_URL_PATH) === '/_assets/register/partial-navigation.js',
        ));
        $I->assertStringNotContainsString('<!DOCTYPE html>', $home['fragment']);

        $post = $this->requestPartial($I, 'https://localhost/partial-navigation-post');
        $I->assertSame('Partial navigation post — My blog', $post['title']);
        $I->assertSame('blog', $post['bodyClass']);
        $I->assertStringContainsString('Partial navigation body', $post['fragment']);
        $I->assertSame($home['assets'], $post['assets']);

        $missing = $this->requestPartial($I, 'https://localhost/partial-navigation-missing', Response::HTTP_NOT_FOUND);
        $I->assertSame('No posts', $missing['title']);
        $I->assertSame('blog', $missing['bodyClass']);
        $I->assertStringContainsString('id="register-page"', $missing['fragment']);
    }

    /** @return array<string, mixed> */
    private function requestPartial(\IntegrationTester $I, string $url, int $status = Response::HTTP_OK): array
    {
        $I->sendRequestWithHeaders($url, [
            'Accept' => PartialPageResponse::RESPONSE_CONTENT_TYPE,
            PartialPageResponse::REQUEST_HEADER => 'partial',
        ]);

        $I->seeResponseCodeIs($status);
        $I->assertSame(
            PartialPageResponse::RESPONSE_CONTENT_TYPE . '; charset=UTF-8',
            $I->grabHttpHeader('Content-Type'),
        );
        $I->assertSame('partial', $I->grabHttpHeader(PartialPageResponse::REQUEST_HEADER));
        $I->assertStringContainsString('no-store', (string)$I->grabHttpHeader('Cache-Control'));

        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertIsArray($payload);

        return $payload;
    }

    private function insertPost(DbLayer $dbLayer): void
    {
        $timestamp = 1_700_000_500;
        $dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::POST->value)
            ->setValue('slug_scope', "'root'")
            ->setValue('created_at', ':time')->setParameter('time', $timestamp)
            ->setValue('published_at', ':time')
            ->setValue('updated_at', ':time')
            ->setValue('revision', '1')
            ->setValue('title', "'Partial navigation post'")
            ->setValue('excerpt', "''")
            ->setValue('body', "'<p>Partial navigation body</p>'")
            ->setValue('published', '1')
            ->setValue('featured', '0')
            ->setValue('comments_enabled', '1')
            ->setValue('series', "''")
            ->setValue('slug', "'partial-navigation-post'")
            ->setValue('author_id', 'NULL')
            ->execute()
        ;
    }
}
