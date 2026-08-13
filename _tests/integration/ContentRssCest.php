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
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Response;

final class ContentRssCest
{
    public function publishesOnlyTheTenNewestPostsAndRecordsTheBlogFeed(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        for ($number = 1; $number <= 11; ++$number) {
            $this->insertContent(
                $dbLayer,
                ContentType::POST,
                'RSS post ' . $number,
                'rss-post-' . $number,
                true,
                1_700_000_000 + $number,
            );
        }

        $this->insertContent($dbLayer, ContentType::POST, 'RSS draft', 'rss-draft', false, 1_700_000_100);
        $this->insertContent($dbLayer, ContentType::PAGE, 'RSS page', 'rss-page', true, 1_700_000_200);

        $I->sendRequestWithHeaders('/rss.xml', ['User-Agent' => 'Register RSS integration reader']);
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $xml = $I->grabResponse();
        $I->assertStringContainsString('RSS post 11', $xml);
        $I->assertStringContainsString('/rss-post-11', $xml);
        $I->assertStringNotContainsString('RSS post 1</title>', $xml);
        $I->assertStringNotContainsString('RSS draft', $xml);
        $I->assertStringNotContainsString('RSS page', $xml);
        $I->assertSame(10, substr_count($xml, '<item>'));

        $lastModified = $I->grabHttpHeader('Last-Modified');
        $I->assertNotNull($lastModified);
        $I->sendRequestWithHeaders('/rss.xml', ['If-Modified-Since' => $lastModified]);
        $I->seeResponseCodeIs(Response::HTTP_NOT_MODIFIED);

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
    ): void {
        $dbLayer->insert(ContentSchema::TABLE_NAME)->values([
            'content_type' => ':content_type',
            'parent_id'    => 'NULL',
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
            'body'         => '<p>' . $title . ' body</p>',
            'timestamp'    => $timestamp,
            'published'    => (int)$published,
        ]);
    }
}
