<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentSchema;
use Register\Core\Pdo\DbLayer;

final class BlogPostWorkspaceCest
{
    public function opensTheExistingEditorAndFiltersPublicationStates(\IntegrationTester $I): void
    {
        $this->seed($I);
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list');
        $I->seeResponseCodeIs(200);
        $I->seeElement('.list-header-actions a[href="/?editor=new"]');
        $I->seeElement('.blog-list-title-link[href="/workspace-draft?editor=edit"]');
        $I->seeElement('.blog-list-title-link[href="/workspace-future?editor=edit"]');
        $I->seeElement('.blog-list-tabs a[aria-current="page"][href*="state=all"]');
        $I->seeElement('.list-search input[name="search"]');
        $I->seeElement('[data-bulk-list][hidden]');
        $I->dontSeeElement('a.entity-action-new');
        $I->dontSeeElement('a.list-action-link-edit');

        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list&state=draft&apply_filter=1');
        $I->see('Workspace draft', '.blog-list-title-link');
        $I->see('Workspace private', '.blog-list-title-link');
        $I->dontSee('Workspace published', '.blog-list-title-link');
        $I->dontSee('Workspace future', '.blog-list-title-link');
        $I->seeElement('input[type="hidden"][name="state"][value="draft"]');
        $I->seeElement('.sort-link[href*="state=draft"]');

        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list&state=scheduled&apply_filter=1');
        $I->see('Workspace future', '.blog-list-title-link');
        $I->dontSee('Workspace draft', '.blog-list-title-link');
        $I->seeElement('.publication-state-scheduled');
        $I->seeElement('th.current-sort .sort-link[href*="sort_field=editorial_date"][href*="sort_direction=desc"]');

        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list&state=published&search=Workspace&apply_filter=1');
        $I->see('Workspace published', '.blog-list-title-link');
        $I->dontSee('Workspace future', '.blog-list-title-link');
        $I->seeElement('.blog-list-tabs a[href*="search=Workspace"][href*="state=draft"]');
    }

    public function authorsOnlySeeTheirOwnUnpublishedPostsAndRealAuthorsInTheFilter(\IntegrationTester $I): void
    {
        $this->seed($I);
        $I->login('author', 'author');
        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list');
        $I->seeResponseCodeIs(200);
        $I->seeElement('.blog-list-title-link[href="/workspace-draft?editor=edit"]');
        $I->seeElement('.blog-list-title-link[href="/workspace-future?editor=edit"]');
        $I->seeElement('.blog-list-title-link[href="/workspace-published"]');
        $I->dontSeeElement('.blog-list-title-link[href="/workspace-published?editor=edit"]');
        $I->dontSee('Workspace private', '.blog-list-title-link');
        $I->assertCount(3, $I->grabMultiple('select[name="author_id"] option'));
        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list&state=draft&apply_filter=1');
        $I->assertCount(1, $I->grabMultiple('.blog-list-title-link'));
        $I->see('Workspace draft', '.blog-list-title-link');
    }

    private function seed(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabAdminService(DbLayer::class);
        $admin = (int)$db->select('id')->from('users')->where('login = :login')->setParameter('login', 'admin')->execute()->result();
        $author = (int)$db->select('id')->from('users')->where('login = :login')->setParameter('login', 'author')->execute()->result();
        $now = time();
        foreach ([
            ['draft', $author, 0, null, 0],
            ['private', $admin, 0, null, 0],
            ['future', $author, 0, null, $now + 3600],
            ['published', $admin, 1, $now - 3600, 0],
        ] as [$name, $owner, $published, $publishedAt, $scheduledAt]) {
            $db->insert(ContentSchema::TABLE_NAME)->values([
                'content_type' => "'post'", 'slug_scope' => "'root'", 'slug' => ':slug', 'title' => ':title',
                'excerpt' => "''", 'body' => "'<p>Body</p>'", 'author_id' => ':author', 'published' => ':published',
                'published_at' => ':published_at', 'scheduled_at' => ':scheduled_at', 'created_at' => ':created_at',
                'updated_at' => ':created_at',
            ])->execute([
                'slug' => 'workspace-' . $name, 'title' => 'Workspace ' . $name, 'author' => $owner,
                'published' => $published, 'published_at' => $publishedAt, 'scheduled_at' => $scheduledAt,
                'created_at' => $now - 3600,
            ]);
        }
    }
}
