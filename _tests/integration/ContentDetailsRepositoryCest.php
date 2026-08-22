<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Author\AuthorProfileRepository;
use Register\Content\ContentDetailsRepository;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Core\Pdo\DbLayer;

final class ContentDetailsRepositoryCest
{
    public function exposesAuthorAndTagsThroughProductCapabilities(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer->update('users')
            ->set('name', ':name')->setParameter('name', 'Federated Author')
            ->where('login = :login')->setParameter('login', 'author')
            ->execute()
        ;
        $authorId = (int)$dbLayer->select('id')->from('users')
            ->where('login = :login')->setParameter('login', 'author')
            ->execute()->result()
        ;

        $now = time();
        $dbLayer->insert(ContentSchema::TABLE_NAME)->values([
            'content_type'     => ':content_type',
            'slug_scope'       => "'root'",
            'slug'             => ':slug',
            'title'            => ':title',
            'excerpt'          => ':excerpt',
            'body'             => ':body',
            'meta_keywords'    => ':keywords',
            'meta_description' => ':description',
            'created_at'       => ':now',
            'published_at'     => ':now',
            'updated_at'       => ':now',
            'published'        => '1',
            'comments_enabled' => '1',
            'author_id'        => ':author_id',
        ])->execute([
            'content_type' => ContentType::POST->value,
            'slug'         => 'federated-post',
            'title'        => 'Federated post',
            'excerpt'      => 'Portable summary',
            'body'         => '<p>Portable body</p>',
            'keywords'     => 'federation',
            'description'  => 'Description',
            'now'          => $now,
            'author_id'    => $authorId,
        ]);
        $contentId = ContentId::post((int)$dbLayer->insertId());

        /** @var TagRepository $tagRepository */
        $tagRepository = $I->grabService(TagRepository::class);
        $tagIds = $tagRepository->findOrCreateIdsByNames(['ActivityPub', 'Register']);
        $tagRepository->replace($contentId, $tagIds);

        /** @var ContentDetailsRepository $repository */
        $repository = $I->grabService(ContentDetailsRepository::class);
        $details    = $repository->find($contentId);

        $I->assertNotNull($details);
        $I->assertSame('Portable summary', $details->content->excerpt);
        $I->assertSame($authorId, $details->content->authorId);
        $I->assertNotNull($details->author);
        $I->assertSame('Federated Author', $details->author->displayName);
        $I->assertTrue($details->author->canPublish);
        $I->assertSame(['ActivityPub', 'Register'], array_column($details->tags, 'name'));

        /** @var AuthorProfileRepository $authorRepository */
        $authorRepository = $I->grabService(AuthorProfileRepository::class);
        $I->assertArrayHasKey($authorId, $authorRepository->findMany([$authorId]));
        $I->assertContains($authorId, array_column($authorRepository->publishers(), 'id'));
    }
}
