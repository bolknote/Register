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
use Register\Url\ContentUrlGenerator;
use S2\Cms\Pdo\DbLayer;

final class ContentUrlGeneratorCest
{
    private ContentUrlGenerator $urlGenerator;

    private DbLayer $dbLayer;

    public function _before(\IntegrationTester $I): void
    {
        $this->urlGenerator = $I->grabService(ContentUrlGenerator::class);
        $this->dbLayer      = $I->grabService(DbLayer::class);
    }

    public function generatesCanonicalPathsForEveryContentType(\IntegrationTester $I): void
    {
        $rootId    = $this->rootPageId();
        $sectionId = $this->insertPage($rootId, 'раздел', true);
        $leafId    = $this->insertPage($sectionId, 'leaf', true);
        $postId    = $this->insertPost('new post', true);

        $I->assertSame('/', $this->urlGenerator->path(ContentId::page($rootId), true));
        $I->assertSame('/%D1%80%D0%B0%D0%B7%D0%B4%D0%B5%D0%BB/', $this->urlGenerator->pagePath($sectionId, true));
        $I->assertSame('/%D1%80%D0%B0%D0%B7%D0%B4%D0%B5%D0%BB/leaf', $this->urlGenerator->path(ContentId::page($leafId), true));
        $I->assertSame('/new%20post', $this->urlGenerator->path(ContentId::post($postId), true));
        $I->assertSame('/new%20post', $this->urlGenerator->post('new post'));
        $I->assertStringEndsWith('/new%20post', $this->urlGenerator->absolutePost('new post'));
    }

    public function omitsPathsBelowAnUnpublishedAncestor(\IntegrationTester $I): void
    {
        $rootId   = $this->rootPageId();
        $parentId = $this->insertPage($rootId, 'hidden', false);
        $leafId   = $this->insertPage($parentId, 'visible-child', true);

        $I->assertSame('/hidden/visible-child', $this->urlGenerator->pagePath($leafId));
        $I->assertNull($this->urlGenerator->pagePath($leafId, true));
        $I->assertSame([], $this->urlGenerator->completePublishedPagePaths([0 => $parentId], [0 => 'visible-child']));
    }

    private function rootPageId(): int
    {
        return (int)$this->dbLayer
            ->select('id')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->andWhere('parent_id IS NULL')
            ->execute()
            ->result()
        ;
    }

    private function insertPage(int $parentId, string $slug, bool $published): int
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
                'published'    => ':published',
            ])
            ->execute([
                'content_type' => ContentType::PAGE->value,
                'parent_id'    => $parentId,
                'slug_scope'   => 'page:' . $parentId,
                'slug'         => $slug,
                'published'    => (int)$published,
            ])
        ;

        return (int)$this->dbLayer->insertId();
    }

    private function insertPost(string $slug, bool $published): int
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
                'published'    => ':published',
            ])
            ->execute([
                'content_type' => ContentType::POST->value,
                'slug'         => $slug,
                'published'    => (int)$published,
            ])
        ;

        return (int)$this->dbLayer->insertId();
    }
}
