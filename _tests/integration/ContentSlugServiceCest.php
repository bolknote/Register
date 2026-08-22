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
use Register\Url\ContentSlugService;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerException;

final class ContentSlugServiceCest
{
    private ContentSlugService $slugService;

    private DbLayer $dbLayer;

    public function _before(\IntegrationTester $I): void
    {
        $this->slugService = $I->grabService(ContentSlugService::class);
        $this->dbLayer     = $I->grabService(DbLayer::class);
    }

    public function reservedAndInvalidPostSlugsAreRejected(\IntegrationTester $I): void
    {
        $I->assertSame(ContentSlugService::STATUS_UNAVAILABLE, $this->slugService->postStatus(0, 'all'));
        $I->assertSame(ContentSlugService::STATUS_UNAVAILABLE, $this->slugService->postStatus(0, 'tags'));
        $I->assertSame(ContentSlugService::STATUS_UNAVAILABLE, $this->slugService->postStatus(0, 'Bad Slug'));
        $I->assertSame(ContentSlugService::STATUS_UNAVAILABLE, $this->slugService->postStatus(0, 'bad/slug'));
        $I->assertSame(ContentSlugService::STATUS_EMPTY, $this->slugService->postStatus(0, ''));
    }

    public function postAndRootPageShareOneNamespace(\IntegrationTester $I): void
    {
        $rootId = $this->rootPageId();
        $pageId = $this->insertPage($rootId, 'shared');

        $I->assertSame(ContentSlugService::STATUS_NOT_UNIQUE, $this->slugService->postStatus(0, 'shared'));
        $I->assertSame(ContentSlugService::STATUS_OK, $this->slugService->pageStatus($pageId, 'shared'));

        $postId = $this->insertPost('post-only');
        $I->assertSame(ContentSlugService::STATUS_NOT_UNIQUE, $this->slugService->pageStatusAtParent(0, $rootId, 'post-only'));
        $I->assertSame(ContentSlugService::STATUS_OK, $this->slugService->postStatus($postId, 'post-only'));
    }

    public function pageSlugsAreUniqueAmongSiblings(\IntegrationTester $I): void
    {
        $rootId      = $this->rootPageId();
        $firstParent = $this->insertPage($rootId, 'first-parent');
        $secondParent = $this->insertPage($rootId, 'second-parent');
        $firstChild  = $this->insertPage($firstParent, 'child');

        $I->assertSame(ContentSlugService::STATUS_OK, $this->slugService->pageStatus($firstChild, 'child'));
        $I->assertSame(ContentSlugService::STATUS_NOT_UNIQUE, $this->slugService->pageStatusAtParent(0, $firstParent, 'child'));
        $I->assertSame(ContentSlugService::STATUS_OK, $this->slugService->pageStatusAtParent(0, $secondParent, 'child'));
        $I->assertSame(ContentSlugService::STATUS_UNAVAILABLE, $this->slugService->pageStatusAtParent(0, $rootId, 'archive'));
    }

    public function generatesTypeSpecificUniqueFallbacks(\IntegrationTester $I): void
    {
        $rootId = $this->rootPageId();
        $this->insertPost('post');
        $this->insertPage($rootId, 'page');

        $I->assertSame('post-2', $this->slugService->generatePost('💥'));
        $I->assertSame('page-2', $this->slugService->generatePage($rootId, '💥'));
    }

    public function databaseEnforcesTheSharedRootNamespace(\IntegrationTester $I): void
    {
        $rootId = $this->rootPageId();
        $this->insertPage($rootId, 'database-collision');

        $I->expectThrowable(
            DbLayerException::class,
            fn(): int => $this->insertPost('database-collision'),
        );
    }

    public function databaseEnforcesSiblingPageUniqueness(\IntegrationTester $I): void
    {
        $rootId = $this->rootPageId();
        $parent = $this->insertPage($rootId, 'database-parent');
        $this->insertPage($parent, 'database-child');

        $I->expectThrowable(
            DbLayerException::class,
            fn(): int => $this->insertPage($parent, 'database-child'),
        );
    }

    private function rootPageId(): int
    {
        return (int)$this->dbLayer
            ->select('id')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->andWhere('parent_id IS NULL')
            ->execute()
            ->result();
    }

    private function insertPage(int $parentId, string $slug): int
    {
        $this->dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->values([
                'content_type' => ':content_type',
                'parent_id'    => ':parent_id',
                'slug_scope'   => ':slug_scope',
                'slug'         => ':slug',
                'title'        => ':slug',
                'excerpt'      => "''",
                'body'         => "''",
                'created_at'   => '1',
                'updated_at'   => '1',
                'published'    => '0',
            ])
            ->execute([
                'content_type' => ContentType::PAGE->value,
                'parent_id'    => $parentId,
                'slug_scope'   => $this->slugService->pageScope($parentId),
                'slug'         => $slug,
            ]);

        return (int)$this->dbLayer->insertId();
    }

    private function insertPost(string $slug): int
    {
        $this->dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->values([
                'content_type' => ':content_type',
                'slug_scope'   => "'root'",
                'slug'         => ':slug',
                'title'        => ':slug',
                'excerpt'      => "''",
                'body'         => "''",
                'created_at'   => '1',
                'updated_at'   => '1',
                'published'    => '0',
            ])
            ->execute([
                'content_type' => ContentType::POST->value,
                'slug'         => $slug,
            ]);

        return (int)$this->dbLayer->insertId();
    }
}
