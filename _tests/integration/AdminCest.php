<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentSchema;
use Register\Content\ContentType;
use S2\Cms\Admin\AdminConfigProvider;
use S2\Cms\Comment\Antispam\SpamAssessment;
use S2\Cms\Comment\Antispam\SpamAssessmentRepository;
use S2\Cms\Comment\SpamDetectorReport;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Model\AuthManager;
use S2\Cms\Model\LoginRateLimiter;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Request;

/**
 * @group admin
 */
class AdminCest
{
    public function testLogin(\IntegrationTester $I): void
    {
        $I->amOnPage('https://localhost/_admin/index.php');
        $I->see('Shared computer');
        $I->seeElement('input[name="foreign_computer"]:not([checked])');

        $I->login('admin', 'no-pass');
        $I->seeResponseCodeIs(401);

        $I->login('admin', 'admin');
        $I->seeResponseCodeIs(200);
    }

    public function testRegisterAdminShellAndListDensity(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');

        $I->amOnPage('https://localhost/_admin/index.php');
        $I->see('Overview', 'h1#dashboard-title');
        $I->seeElement('details[data-menu-group="Materials"]');
        $I->seeElement('details[data-menu-group="Moderation"]');
        $I->seeElement('details[data-menu-group="Settings"]');
        $I->seeElement('details[data-menu-group="Account"]');
        $I->dontSeeElement('details.main-menu-system');
        $I->seeElement('details[data-menu-group="Materials"] a[href="?entity=BlogPost&action=list"]');
        $I->seeElement('details[data-menu-group="Materials"] a[href="?entity=Article&action=list"]');
        $I->seeElement('details[data-menu-group="Materials"] a[href="?entity=Media"]');
        $I->seeElement('details[data-menu-group="Materials"] a[href="?entity=Tag&action=list"]');
        $I->seeElement('details[data-menu-group="Moderation"] a[href="?entity=Comment&action=list"]');
        $I->seeElement('details[data-menu-group="Moderation"] a[href="?entity=SpamAssessment&action=list"]');
        $I->seeElement('details[data-menu-group="Moderation"] a[href="?entity=SpamRule&action=list"]');
        $I->dontSeeElement('details[data-menu-group="Moderation"] a[href*="entity=SpamSignalPolicy"]');
        $I->dontSeeElement('details[data-menu-group="Moderation"] a[href*="entity=SpamRatePolicy"]');
        $I->seeElement('details[data-menu-group="Account"] a[href^="?entity=User&action=edit&id="]');
        $I->seeElement('details[data-menu-group="Account"] a[href="?entity=Session&action=list"]');

        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list');

        $I->seeElement('link[rel="stylesheet"][href^="/_admin/css/register.css?v="]');
        $I->seeElement('html[data-color-scheme="light dark"]');
        $I->seeElement('header.admin-shell');
        $I->seeElement('a.admin-brand[href="/"]');
        $I->see('Register', 'a.admin-brand');
        $I->seeElement('nav[aria-label="Control panel navigation"]');
        $I->seeElement('a.main-menu-link[aria-current="page"][href="?entity=BlogPost&action=list"]');
        $I->see('Log out', 'a.main-menu-logout-link');
        $I->dontSee('Log out?');

        /** @var AdminConfigProvider $adminConfigProvider */
        $adminConfigProvider = $I->grabAdminService(AdminConfigProvider::class);
        $adminConfig = $adminConfigProvider->getAdminConfig();
        foreach (['Article', 'BlogPost', 'Comment', 'Queue'] as $entityName) {
            $entity = $adminConfig->findEntityByName($entityName)
                ?? throw new \LogicException($entityName . ' admin entity is missing.');
            $I->assertSame(50, $entity->getLimit());
        }

        $I->amOnPage('https://localhost/_admin/index.php?entity=Dashboard');
        $I->see('Overview', 'h1#dashboard-title');
        $I->assertCount(1, $I->grabMultiple('.publication-stat-item'));
        $I->see('Needs attention', '.publication-stat-item h3');
        $I->see('page', '.publication-statistics li:first-child');
        $I->see('post', '.publication-statistics li:last-child');
        $I->see('comment', '.publication-comments');
        $I->dontSeeElement('[data-analytics-table="register-analytics-pages"]');
        $I->dontSeeElement('[data-analytics-table="register-analytics-feeds"]');
        $I->dontSeeElement('.environment-stat-item');
        $I->assertCount(1, $I->grabMultiple('.stat-items > .stat-item'));
        $I->dontSee('Register source code', '.stat-items');
        $I->dontSee('Register is based on', '.stat-items');
        $I->dontSee('© 2007–');

        $I->amOnPage('https://localhost/_admin/index.php?entity=Statistics');
        $I->see('Analytics', 'h1#statistics-title');
        $I->see('Traffic', '.register-analytics h2');
        $I->seeElement('[data-analytics-table="register-analytics-pages"]');
        $I->seeElement('[data-analytics-table="register-analytics-feeds"]');

        $I->amOnPage('https://localhost/_admin/index.php?entity=SystemStatus');
        $I->see('System status', 'h1#system-status-title');
        $I->see('Environment', '.environment-stat-item h3');
        $I->see('PHP', '.environment-stat-item dt');
        $I->see('Database', '.environment-stat-item');
        $I->dontSee('Database', '.stat-item > h3');
        $I->assertCount(3, $I->grabMultiple('.stat-items > .stat-item'));
        $I->dontSeeElement('.publication-stat-item');

        $I->amOnPage('https://localhost/_admin/index.php?entity=Article&action=list');
        $I->seeElement('nav.section-tabs a[aria-current="page"][href="?entity=Article&action=list"]');
        $I->seeElement('nav.section-tabs a[href="?entity=Site"]');
        $I->seeElement('details.filter-panel:not([open])');
        $I->seeElement('label[for="filter-Article-search"]');
        $I->seeElement('fieldset.filter-control-radio > legend.filter-label');
        $I->dontSeeElement('.pagination');

        $I->amOnPage('https://localhost/_admin/index.php?entity=Queue&action=list');
        $I->see('Queue', 'h1');
        $I->see('Job identifier', 'table.list-table th');
        $I->see('Failed at', 'table.list-table th');

        $I->amOnPage('https://localhost/_admin/index.php?entity=Comment&action=list');
        $I->see('Content', 'table.list-table th');
        $I->seeElement('nav.moderation-tabs[aria-label="Moderation sections"]');
        $I->seeElement('nav.moderation-tabs a[aria-current="page"][href="?entity=Comment&action=list"]');
        $I->seeElement('nav.moderation-tabs a[href="?entity=SpamAssessment&action=list"]');
        $I->seeElement('nav.moderation-tabs a[href="?entity=SpamRule&action=list"]');
        $I->seeElement('nav.moderation-tabs a[href="?entity=SpamSignalPolicy&action=list"]');
        $I->dontSeeElement('nav.moderation-subtabs');
        $I->dontSee('Email', 'table.list-table th');
        $I->dontSee('Content type', 'table.list-table th');
        $I->dontSee('Spam label', 'table.list-table th');

        $I->amOnPage('https://localhost/_admin/index.php?entity=User&action=list');
        $I->see('Roles', 'table.list-table th');
        $I->dontSee('Password', 'table.list-table th');
        $I->seeElement('button.list-action-link-delete[data-admin-delete][data-delete-url]');
        $I->dontSeeElement('.list-action-delete-popup');
        $I->seeElement('dialog[data-admin-confirm-dialog][aria-labelledby="admin-confirm-title"]');
        $I->seeElement('button[data-admin-confirm-cancel][value="cancel"]');
        $I->seeElement('button.danger[data-admin-confirm-submit][value="confirm"]');

        $editUserHref = $I->grabAttributeFrom('a.list-action-link-edit', 'href');
        $I->assertNotNull($editUserHref);
        $I->amOnPage('https://localhost/_admin/index.php' . $editUserHref);
        $I->see('Edit user', 'h1');
        $I->assertSame('', $I->grabValueFrom('input[name="password"]'));
        $I->seeElement('button.edit-action-link-delete[data-admin-delete][data-delete-url]');

        $I->amOnPage('https://localhost/_admin/index.php?entity=Site');
        $I->see('Page structure', 'h1');
        $I->seeElement('nav.section-tabs a[aria-current="page"][href="?entity=Site"]');
        $I->seeElement('.admin-structure > .structure-toolbar');
        $I->dontSeeElement('.admin-structure > .toolbar');
        $I->seeElement('button#create_page_button');
        $I->seeElement('input#search_field[aria-label="Search"]');
        $I->seeElement('#context_buttons[role="group"] button#context_add[aria-label]');
        $I->seeElement('#context_buttons[role="group"] button#context_delete.is-dangerous[aria-label]');

        $I->amOnPage('https://localhost/_admin/index.php?entity=Media');
        $I->see('Media', 'h1#media-library-title');
        $I->seeElement('header.admin-shell');
        $I->seeElement('a.main-menu-link[aria-current="page"][href="?entity=Media"]');
        $I->seeElement('section.picture-manager-page.is-embedded[data-picture-manager]');
        $I->seeElement('label[for="media-upload-input"]');
        $I->seeElement('#folders[aria-label="Folders"]');
        $I->seeElement('#files[aria-label="Files"]');

        $I->amOnPage('https://localhost/_admin/index.php?entity=SystemModules');
        $I->see('System modules', 'h1');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Extension');
        $I->see('Optional modules', 'h1');

        $I->amOnPage('https://localhost/_admin/index.php?entity=Config&action=list');
        $I->dontSee('Output gzip compression');
        $I->seeElement('section.config-page[aria-labelledby="settings-title"]');
        $I->see('AI assistant', '.config-section');
        $I->seeElement('nav.config-section-nav[aria-label="Settings sections"]');
        $I->seeElement('[data-config-page-state][data-state="applied"]');
        $I->assertCount(27, $I->grabMultiple('.config-setting label[for]'));
        $I->seeElement('form[action*="name=REGISTER_AI_PROVIDER"] select[name="value"]');
        $I->seeElement('[data-config-key="REGISTER_AI_API_KEY"][data-depends-on="REGISTER_AI_PROVIDER"]');
        $I->seeElement('button[data-ai-key-help-open]');
        $I->seeElement('form[action*="name=REGISTER_AI_API_KEY"] input[type="password"]');
        $I->seeElement('dialog#ai-key-help-dialog');
        $I->seeElement('[data-ai-key-help-panel="gemini"] a[href="https://aistudio.google.com/apikey"]');
        $I->seeElement('[data-ai-key-help-panel="groq"] a[href="https://console.groq.com/keys"]');
        $I->dontSee('REGISTER_LINK_INVENTORY_GENERATION');
    }

    public function testSettingsDistinguishStoredAndAppliedValues(\IntegrationTester $I): void
    {
        /** @var DynamicConfigProvider $configProvider */
        $configProvider = $I->grabAdminService(DynamicConfigProvider::class);
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabAdminService(DbLayer::class);
        $appliedSiteName = (string)$configProvider->get('S2_SITE_NAME');
        $configProvider->regenerate();

        $dbLayer->update('config')
            ->set('value', ':value')->setParameter('value', 'Stored but not applied')
            ->where('name = :name')->setParameter('name', 'S2_SITE_NAME')
            ->execute()
        ;

        try {
            $I->login('admin', 'admin');
            $I->amOnPage('https://localhost/_admin/index.php?entity=Config&action=list');

            $I->seeElement('[data-config-key="S2_SITE_NAME"] input[value="Stored but not applied"]');
            $I->seeElement('[data-config-key="S2_SITE_NAME"] [data-config-save-state][data-state="pending"]');
            $I->see('Some saved settings are not applied', '[data-config-page-state][data-state="pending"]');
        } finally {
            $dbLayer->update('config')
                ->set('value', ':value')->setParameter('value', $appliedSiteName)
                ->where('name = :name')->setParameter('name', 'S2_SITE_NAME')
                ->execute()
            ;
            $configProvider->regenerate();
        }
    }

    /** @throws \JsonException */
    public function testSavedListViewsAreStoredPerAdministrator(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list');

        $I->seeElement('section.saved-list-views[data-saved-list-views]');
        $csrfToken = $I->grabAttributeFrom('section.saved-list-views', 'data-csrf-token');
        $I->assertNotNull($csrfToken);

        $state = json_encode([
            'filters'        => [
                'search'    => 'saved-view-needle',
                'published' => '1',
            ],
            'sort_field'     => 'create_time',
            'sort_direction' => 'desc',
        ], JSON_THROW_ON_ERROR);
        $I->sendPost('https://localhost/_admin/ajax.php?action=register_saved_list_view_save', [
            'entity'     => 'BlogPost',
            'name'       => 'Needs review',
            'state'      => $state,
            'csrf_token' => $csrfToken,
        ]);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals(true, ['success']);

        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list');
        $I->see('Needs review', '.saved-list-views-links');
        $savedViewHref = $I->grabAttributeFrom('.saved-list-views-links a', 'href');
        $I->assertNotNull($savedViewHref);
        $I->assertStringContainsString('search=saved-view-needle', $savedViewHref);
        $I->assertStringContainsString('sort_direction=desc', $savedViewHref);

        $viewId = $I->grabAttributeFrom('[data-saved-list-view-delete]', 'data-view-id');
        $I->assertNotNull($viewId);
        $I->sendPost('https://localhost/_admin/ajax.php?action=register_saved_list_view_delete', [
            'entity'     => 'BlogPost',
            'view_id'    => $viewId,
            'csrf_token' => $csrfToken,
        ]);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals(true, ['success']);

        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list');
        $I->dontSee('Needs review', '.saved-list-views');
    }

    /** @throws \JsonException */
    public function testBulkListActionsPublishContentThroughOneProtectedRequest(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabAdminService(DbLayer::class);
        $content = $dbLayer
            ->select('id, published, published_at, scheduled_at')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->limit(1)
            ->execute()
            ->fetchAssoc()
        ;
        $I->assertIsArray($content);
        $contentId        = (int)$content['id'];
        $originallyPublic = (bool)$content['published'];
        $firstAction      = $originallyPublic ? 'unpublish' : 'publish';
        $restoreAction    = $originallyPublic ? 'publish' : 'unpublish';

        try {
            $I->login('admin', 'admin');
            $I->amOnPage('https://localhost/_admin/index.php?entity=Article&action=list');
            $I->seeElement('[data-bulk-list]');
            $I->seeElement('[data-bulk-select-all]');
            $I->seeElement('[data-bulk-action] option[value="publish"]');
            $I->seeElement('[data-bulk-action] option[value="unpublish"]');
            $I->dontSeeElement('[data-bulk-action] option[value="delete"]');

            $csrfToken = $I->grabAttributeFrom('[data-bulk-list]', 'data-csrf-token');
            $I->assertNotNull($csrfToken);
            $items = json_encode([[
                'primary_key' => ['id' => $contentId],
                'csrf_token'  => '',
            ]], JSON_THROW_ON_ERROR);

            $I->sendPost('https://localhost/_admin/ajax.php?action=register_bulk_list_action', [
                'entity'      => 'Article',
                'bulk_action' => $firstAction,
                'csrf_token'  => 'invalid',
                'items'       => $items,
            ]);
            $I->seeResponseCodeIs(403);

            $I->sendPost('https://localhost/_admin/ajax.php?action=register_bulk_list_action', [
                'entity'      => 'Article',
                'bulk_action' => $firstAction,
                'csrf_token'  => $csrfToken,
                'items'       => $items,
            ]);
            $I->seeResponseCodeIs(200);
            $I->assertJsonSubResponseEquals(1, ['updated']);
            $I->assertSame($originallyPublic ? 0 : 1, $this->contentPublishedState($dbLayer, $contentId));

            $I->sendPost('https://localhost/_admin/ajax.php?action=register_bulk_list_action', [
                'entity'      => 'Article',
                'bulk_action' => $restoreAction,
                'csrf_token'  => $csrfToken,
                'items'       => $items,
            ]);
            $I->seeResponseCodeIs(200);
            $I->assertJsonSubResponseEquals(1, ['updated']);
            $I->assertSame($originallyPublic ? 1 : 0, $this->contentPublishedState($dbLayer, $contentId));

            /** @var \S2\Cms\AdminYard\BulkListActionProvider $provider */
            $provider = $I->grabAdminService(\S2\Cms\AdminYard\BulkListActionProvider::class);
            $I->assertSame(['publish', 'unpublish', 'delete'], $provider->actionsFor('BlogPost'));
            $I->assertSame(['publish', 'unpublish'], $provider->actionsFor('Article'));
            $I->assertSame(['ham', 'spam', 'reject', 'delete'], $provider->actionsFor('Comment'));
        } finally {
            $dbLayer
                ->update(ContentSchema::TABLE_NAME)
                ->set('published', ':published')->setParameter('published', (int)$content['published'])
                ->set('published_at', ':published_at')->setParameter('published_at', $content['published_at'])
                ->set('scheduled_at', ':scheduled_at')->setParameter('scheduled_at', $content['scheduled_at'])
                ->where('id = :id')->setParameter('id', $contentId)
                ->execute()
            ;
        }
    }

    public function testNewPostUsesEditorialEditor(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=new');

        $I->see('New post', 'h1');
        $I->seeElement('section.post-edit-content.is-new');
        $I->seeElement('form[name="article-form"][action="?entity=BlogPost&action=new"]');
        $I->seeElement('script[type="module"][src="/_admin/js/editor/entry.js"]');
        $I->seeElement('main.editor-main-column > .editor-title-block');
        $I->seeElement('main.editor-main-column > .content-editor-ai-tools');
        $I->seeElement('main.editor-main-column > .editor-body-block');
        $I->seeElement('.html-toolbar button[data-editor-action="undo"]');
        $I->seeElement('.html-toolbar button[data-editor-action="redo"]');
        $I->seeElement('.html-toolbar[role="toolbar"][aria-label="Text"]');
        $I->seeElement('.html-toolbar button[data-editor-action="undo"][aria-label="Undo"]');
        $I->dontSeeElement('.html-toolbar button[tabindex="-1"]');
        $I->dontSeeElement('#ai-result-panel');
        $I->seeElement('.editor-title-block input[name="title"][placeholder="Title"]');
        $I->dontSeeElement('.editor-title-block label[for="id-title"]');
        $I->seeElement('main.editor-main-column > .editor-tags-block:last-child');
        $I->seeElement('.editor-tags-block input[name="tags"][placeholder="Tags"]');
        $I->seeElement('label[for="id-body"]');
        $I->dontSeeElement('.editor-tags-block details');
        $I->assertStringContainsString(
            'register_tag_suggestions',
            $I->grabResponse(),
        );
        $I->seeElement('aside.editor-sidebar-column > .editor-publication-card');
        $I->seeElement('a.main-menu-link[aria-current="page"][href="?entity=BlogPost&action=new"]');
        $I->dontSeeElement('a.main-menu-link[aria-current="page"][href="?entity=BlogPost&action=list"]');
        $I->see('Create draft', '.article-form-buttons button[type="submit"]');
        $I->see('Preview and publishing become available after the draft is created.', '.content-editor-note');

        $I->submitForm('form[name="article-form"]', [
            'title'      => 'Editorial editor draft',
            'tags'       => 'register, admin',
            'date_label' => '',
            'body'       => '<p>Created through the shared editor.</p>',
        ]);

        $I->seeResponseCodeIs(302);

        $location = $I->grabHttpHeader('Location');
        $I->assertNotNull($location);
        $I->assertStringContainsString('?entity=BlogPost&action=edit&id=', $location);
        $I->amOnPage('https://localhost/_admin/index.php' . $location);
        $I->assertSame('Editorial editor draft', $I->grabValueFrom('input[name="title"]'));
        $I->assertSame('register, admin', $I->grabValueFrom('input[name="tags"]'));
        $I->seeElement('section.post-edit-content.is-edit');
    }

    public function testScheduledPostUsesOneExplicitPublicationState(
        \IntegrationTester $I,
    ): void {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabAdminService(DbLayer::class);
        $scheduledAt = time() + 3600;
        $dbLayer->insert(ContentSchema::TABLE_NAME)->values([
            'content_type' => ':content_type',
            'slug_scope'   => "'root'",
            'slug'         => ':slug',
            'title'        => ':title',
            'excerpt'      => "''",
            'body'         => "'<p>Scheduled publication state</p>'",
            'created_at'   => ':created_at',
            'published_at' => '0',
            'scheduled_at' => ':scheduled_at',
            'updated_at'   => ':updated_at',
            'published'    => '0',
        ])->execute([
            'content_type' => ContentType::POST->value,
            'slug'         => 'admin-scheduled-state',
            'title'        => 'Admin scheduled state',
            'created_at'   => $scheduledAt - 300,
            'scheduled_at' => $scheduledAt,
            'updated_at'   => $scheduledAt - 300,
        ]);
        $postId = (int)$dbLayer->insertId();

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=edit&id=' . $postId);

        $I->seeElement('fieldset[data-publication-state][data-state="scheduled"]');
        $I->assertCount(3, $I->grabMultiple('input[data-publication-state-input]'));
        $I->seeElement('input[data-publication-state-input][value="scheduled"][checked]');
        $I->seeElement('[data-publication-native-control][hidden] input[name="published"]:not([checked])');
        $I->seeElement('[data-publication-scheduled]:not([hidden]) input[name="scheduled_at"]');
        $I->seeElement('[data-publication-published-at][hidden]');

        $I->submitForm('form[name="article-form"]', [
            '_publication_state' => 'published',
            'published'          => true,
            'scheduled_at'       => '',
        ]);
        $I->seeResponseCodeIs(302);

        $savedState = $dbLayer
            ->select('published, scheduled_at')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $postId)
            ->execute()
            ->fetchAssoc()
        ;
        $I->assertIsArray($savedState);
        $I->assertSame(1, (int)$savedState['published']);
        $I->assertSame(0, (int)$savedState['scheduled_at']);
    }

    public function testEditingUserKeepsBlankPasswordAndUpdatesRoles(\IntegrationTester $I): void
    {
        /** @var \PDO $pdo */
        $pdo = $I->grabAdminService(\PDO::class);

        $statement = $pdo->prepare('SELECT id, password, name, email, create_articles FROM users WHERE login = :login');
        $I->assertNotFalse($statement);
        $statement->execute(['login' => 'admin']);
        $admin = $statement->fetch(\PDO::FETCH_ASSOC);
        $statement->closeCursor();
        $I->assertIsArray($admin);

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=User&action=edit&id=' . $admin['id']);
        $I->submitForm('form', [
            'login'           => 'admin',
            'password'        => '',
            'name'            => $admin['name'],
            'email'           => $admin['email'],
            'view'            => true,
            'view_hidden'     => true,
            'hide_comments'   => true,
            'edit_comments'   => true,
            'create_articles' => false,
            'edit_site'       => true,
            'edit_users'      => true,
        ]);

        $I->seeResponseCodeIs(302);

        $passwordStatement = $pdo->prepare('SELECT password FROM users WHERE id = :id');
        $I->assertNotFalse($passwordStatement);
        $passwordStatement->execute(['id' => $admin['id']]);
        $password = $passwordStatement->fetchColumn();
        $passwordStatement->closeCursor();
        $I->assertSame($admin['password'], $password);

        $roleStatement = $pdo->prepare('SELECT create_articles FROM users WHERE id = :id');
        $I->assertNotFalse($roleStatement);
        $roleStatement->execute(['id' => $admin['id']]);
        $createArticles = $roleStatement->fetchColumn();
        $roleStatement->closeCursor();
        $I->assertSame(0, (int)$createArticles);
    }

    public function testNewUserStillRequiresPassword(\IntegrationTester $I): void
    {
        /** @var \PDO $pdo */
        $pdo = $I->grabAdminService(\PDO::class);

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=User&action=new');
        $I->submitForm('form', [
            'login'    => 'passwordless-user',
            'password' => '',
            'name'     => '',
            'email'    => 'passwordless@example.com',
        ]);

        $I->seeResponseCodeIs(200);
        $I->see('This value should not be blank.', '.error-message-box');

        $countStatement = $pdo->query("SELECT COUNT(*) FROM users WHERE login = 'passwordless-user'");
        $I->assertNotFalse($countStatement);
        $userCount = $countStatement->fetchColumn();
        $countStatement->closeCursor();
        $I->assertSame(0, (int)$userCount);
    }

    public function testFailedLoginsAreRateLimitedWithoutRawIdentifiers(\IntegrationTester $I): void
    {
        /** @var AuthManager $authManager */
        $authManager = $I->grabAdminService(AuthManager::class);
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabAdminService(DbLayer::class);
        $clientIp = '198.51.100.41';

        for ($attempt = 0; $attempt < LoginRateLimiter::FAILURE_LIMIT; ++$attempt) {
            $response = $this->loginResponse($authManager, 'missing-user', 'wrong-password', $clientIp);
            $I->assertSame(401, $response->getStatusCode());
        }

        $blocked = $this->loginResponse($authManager, 'missing-user', 'wrong-password', $clientIp);
        $I->assertSame(429, $blocked->getStatusCode());
        $retryAfter = $blocked->headers->get('Retry-After');
        $I->assertNotNull($retryAfter);
        $I->assertGreaterThan(0, (int)$retryAfter);
        $I->assertLessThanOrEqual(LoginRateLimiter::WINDOW_SECONDS + 1, (int)$retryAfter);

        $rows = $dbLayer
            ->select('bucket_type, bucket_key')
            ->from('spam_rate_events')
            ->where("bucket_type IN ('auth_ip', 'auth_login')")
            ->execute()
            ->fetchAssocAll()
        ;
        $I->assertCount(2 * LoginRateLimiter::FAILURE_LIMIT, $rows);
        foreach ($rows as $row) {
            $I->assertContains($row['bucket_type'], ['auth_ip', 'auth_login']);
            $I->assertSame(64, \strlen((string)$row['bucket_key']));
            $I->assertStringNotContainsString($clientIp, (string)$row['bucket_key']);
            $I->assertStringNotContainsString('missing-user', (string)$row['bucket_key']);
        }
    }

    public function testSuccessfulLoginClearsPreviousFailures(\IntegrationTester $I): void
    {
        /** @var AuthManager $authManager */
        $authManager = $I->grabAdminService(AuthManager::class);
        $clientIp = '198.51.100.42';

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $response = $this->loginResponse($authManager, 'admin', 'wrong-password', $clientIp);
            $I->assertSame(401, $response->getStatusCode());
        }

        $success = $this->loginResponse($authManager, 'admin', 'admin', $clientIp);
        $I->assertSame(200, $success->getStatusCode());

        for ($attempt = 0; $attempt < LoginRateLimiter::FAILURE_LIMIT; ++$attempt) {
            $response = $this->loginResponse($authManager, 'admin', 'wrong-password', $clientIp);
            $I->assertSame(401, $response->getStatusCode());
        }

        $blocked = $this->loginResponse($authManager, 'admin', 'wrong-password', $clientIp);
        $I->assertSame(429, $blocked->getStatusCode());
    }

    public function testBlogPreviewLoadsTemplateFromBaseModule(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/ajax.php?action=load_template&template_id=blog.php&article_id=2&content_type=post');
        $I->seeResponseCodeIs(200);

        $response = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
        $I->assertIsArray($response);
        $I->assertTrue($response['success'] ?? false);
        $I->assertStringContainsString('<!-- s2_text -->', (string)($response['template'] ?? ''));
    }

    public function testLoginLifetimeAndSharedComputerMode(\IntegrationTester $I): void
    {
        /** @var AuthManager $authManager */
        $authManager = $I->grabAdminService(AuthManager::class);
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabAdminService(DbLayer::class);

        $persistentResponse = $authManager->checkAuth(Request::create(
            'https://localhost/_admin/index.php?action=login',
            'POST',
            ['login' => 'admin', 'pass' => 'admin'],
            server: ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_USER_AGENT' => 'Persistent browser'],
        ));
        $I->assertNotNull($persistentResponse);
        $persistentCookies = $persistentResponse->headers->getCookies();
        $I->assertCount(2, $persistentCookies);
        foreach ($persistentCookies as $cookie) {
            $I->assertGreaterThan(time() + 4 * 365 * 86400, $cookie->getExpiresTime());
        }

        $adminCookie = array_values(array_filter(
            $persistentCookies,
            static fn(\Symfony\Component\HttpFoundation\Cookie $cookie): bool => !str_ends_with($cookie->getName(), '_c'),
        ))[0];
        $I->assertStringStartsWith('p', (string)$adminCookie->getValue());
        $dbLayer
            ->update('users_online')
            ->set('time', ':time')->setParameter('time', time() - 86400)
            ->set('ip', ':ip')->setParameter('ip', '192.0.2.1')
            ->where('challenge = :challenge')->setParameter('challenge', $adminCookie->getValue())
            ->execute()
        ;

        $authenticatedResponse = $authManager->checkAuth(Request::create(
            'https://localhost/_admin/index.php',
            'GET',
            cookies: [$adminCookie->getName() => $adminCookie->getValue()],
            server: ['REMOTE_ADDR' => '192.0.2.2', 'HTTP_USER_AGENT' => 'Persistent browser'],
        ));
        $I->assertNull($authenticatedResponse);

        $renewedResponse = new \Symfony\Component\HttpFoundation\Response();
        $authManager->renewPersistentCookies(Request::create(
            'https://localhost/_admin/index.php',
            cookies: [$adminCookie->getName() => $adminCookie->getValue()],
        ), $renewedResponse);
        $I->assertCount(2, $renewedResponse->headers->getCookies());

        $foreignComputerResponse = $authManager->checkAuth(Request::create(
            'https://localhost/_admin/index.php?action=login',
            'POST',
            ['login' => 'admin', 'pass' => 'admin', 'foreign_computer' => '1'],
            server: ['REMOTE_ADDR' => '192.0.2.3', 'HTTP_USER_AGENT' => 'Shared browser'],
        ));
        $I->assertNotNull($foreignComputerResponse);
        $foreignComputerCookies = $foreignComputerResponse->headers->getCookies();
        $I->assertCount(2, $foreignComputerCookies);
        foreach ($foreignComputerCookies as $cookie) {
            $I->assertSame(0, $cookie->getExpiresTime());
        }

        $foreignAdminCookie = array_values(array_filter(
            $foreignComputerCookies,
            static fn(\Symfony\Component\HttpFoundation\Cookie $cookie): bool => !str_ends_with($cookie->getName(), '_c'),
        ))[0];
        $I->assertStringStartsWith('t', (string)$foreignAdminCookie->getValue());
        $notRenewedResponse = new \Symfony\Component\HttpFoundation\Response();
        $authManager->renewPersistentCookies(Request::create(
            'https://localhost/_admin/index.php',
            cookies: [$foreignAdminCookie->getName() => $foreignAdminCookie->getValue()],
        ), $notRenewedResponse);
        $I->assertCount(0, $notRenewedResponse->headers->getCookies());
    }

    public function testSecureCookiePolicy(\IntegrationTester $I): void
    {
        /** @var AuthManager $authManager */
        $authManager = $I->grabAdminService(AuthManager::class);
        $this->assertSecureCookiePolicy($I, $authManager, 'http://localhost/_admin/index.php', true);

        $httpApplication = $I->createAdminApplication([
            'force_admin_https' => false,
            'base_url'          => 'http://s2.localhost',
        ]);
        /** @var AuthManager $httpAuthManager */
        $httpAuthManager = $httpApplication->container->get(AuthManager::class);
        $this->assertSecureCookiePolicy($I, $httpAuthManager, 'http://localhost/_admin/index.php', false);
        $this->assertSecureCookiePolicy($I, $httpAuthManager, 'https://localhost/_admin/index.php', true);

        $httpsBaseUrlApplication = $I->createAdminApplication([
            'force_admin_https' => false,
            'base_url'          => 'https://s2.localhost',
        ]);
        /** @var AuthManager $httpsBaseUrlAuthManager */
        $httpsBaseUrlAuthManager = $httpsBaseUrlApplication->container->get(AuthManager::class);
        $this->assertSecureCookiePolicy($I, $httpsBaseUrlAuthManager, 'http://localhost/_admin/index.php', true);
    }

    public function testNobody(\IntegrationTester $I): void
    {
        $I->login('nobody', 'nobody');
        $I->seeResponseCodeIs(200);
        $I->amOnPage('https://localhost/_admin/index.php');
        $I->see('Access denied');
        $I->see('You do not have permission to access this page.');
    }

    public function testGuest(\IntegrationTester $I): void
    {
        $I->login('guest', 'no-pass');
        $I->seeResponseCodeIs(401);

        $I->login('guest', 'guest');
        $I->seeResponseCodeIs(200);
    }

    public function testModerationNavigationDoesNotLeakExpertTools(\IntegrationTester $I): void
    {
        $I->login('power_guest', 'power_guest');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Comment&action=list');

        $I->seeResponseCodeIs(200);
        $I->seeElement('details[data-menu-group="Moderation"]');
        $I->assertCount(1, $I->grabMultiple('nav.moderation-tabs a'));
        $I->seeElement('nav.moderation-tabs a[aria-current="page"][href="?entity=Comment&action=list"]');
        $I->dontSeeElement('nav.moderation-tabs a[href*="entity=Spam"]');
        $I->dontSeeElement('nav.moderation-subtabs');
    }

    public function testAntispamCalibrationPages(\IntegrationTester $I): void
    {
        $assessment = new SpamAssessment(
            50,
            ['links' => 50],
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            [],
        );
        /** @var SpamAssessmentRepository $assessmentRepository */
        $assessmentRepository = $I->grabAdminService(SpamAssessmentRepository::class);
        $assessmentRepository->save(
            $assessment,
            SpamDetectorReport::STATUS_SPAM,
            contentType: ContentType::PAGE,
            commentId: 1,
        );
        $assessmentRepository->labelComment(1, 'ham', $assessment, ContentType::PAGE);

        $I->login('admin', 'admin');

        $I->amOnPage('https://localhost/_admin/index.php?entity=SpamAssessment&action=list');
        $I->seeResponseCodeIs(200);
        $I->see('Antispam report');
        $I->seeElement('nav.moderation-tabs a[aria-current="page"][href="?entity=SpamAssessment&action=list"]');
        $I->see('Local filter quality');
        $I->see('Shadow comparison');
        $I->see('False positive');
        $I->see('Links');

        $I->amOnPage('https://localhost/_admin/index.php?entity=SpamAssessment&action=list&quality=false_positive&apply_filter=1');
        $I->seeResponseCodeIs(200);
        $I->see('False positive');
        $I->see('Links');

        $I->amOnPage('https://localhost/_admin/index.php?entity=SpamRule&action=list');
        $I->seeResponseCodeIs(200);
        $I->see('Spam rules');
        $I->seeElement('nav.moderation-tabs a[aria-current="page"][href="?entity=SpamRule&action=list"]');

        $I->amOnPage('https://localhost/_admin/index.php?entity=SpamSignalPolicy&action=list');
        $I->seeResponseCodeIs(200);
        $I->see('Spam signal weights');
        $I->see('One link');
        $I->seeElement('nav.moderation-tabs a[aria-current="page"][href="?entity=SpamSignalPolicy&action=list"]');
        $I->seeElement('nav.moderation-subtabs a[aria-current="page"][href="?entity=SpamSignalPolicy&action=list"]');
        $I->seeElement('nav.moderation-subtabs a[href="?entity=SpamRatePolicy&action=list"]');

        $I->amOnPage(
            'https://localhost/_admin/index.php?entity=SpamSignalPolicy&action=edit&signal_code=links_one',
        );
        $I->seeResponseCodeIs(200);
        $I->see('Edit spam signal weight');
        $I->see('Score adjustment');
        $I->seeElement('nav.moderation-tabs a[aria-current="page"]');
        $I->seeElement('nav.moderation-subtabs a[aria-current="page"][href="?entity=SpamSignalPolicy&action=list"]');

        $I->amOnPage('https://localhost/_admin/index.php?entity=SpamRatePolicy&action=list');
        $I->seeResponseCodeIs(200);
        $I->see('Comment rate limits');
        $I->see('IP address');
        $I->see('Allowed attempts');
        $I->seeElement('nav.moderation-tabs a[aria-current="page"][href="?entity=SpamSignalPolicy&action=list"]');
        $I->seeElement('nav.moderation-subtabs a[aria-current="page"][href="?entity=SpamRatePolicy&action=list"]');

        $I->amOnPage('https://localhost/_admin/index.php?entity=SpamRatePolicy&action=edit&bucket_type=ip');
        $I->seeResponseCodeIs(200);
        $I->see('Edit comment rate limit');
        $I->see('Window, seconds');
        $I->seeElement('nav.moderation-subtabs a[aria-current="page"][href="?entity=SpamRatePolicy&action=list"]');
    }

    private function assertSecureCookiePolicy(\IntegrationTester $I, AuthManager $authManager, string $url, bool $expected): void
    {
        $method = new \ReflectionMethod(AuthManager::class, 'shouldUseSecureCookies');

        $I->assertSame($expected, $method->invoke($authManager, Request::create($url)));
    }

    private function contentPublishedState(DbLayer $dbLayer, int $contentId): int
    {
        $row = $dbLayer
            ->select('published')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $contentId)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? (int)$row['published'] : -1;
    }

    private function loginResponse(AuthManager $authManager, string $login, string $password, string $clientIp): \Symfony\Component\HttpFoundation\Response
    {
        $response = $authManager->checkAuth(Request::create(
            'https://localhost/_admin/index.php?action=login',
            'POST',
            ['login' => $login, 'pass' => $password],
            server: ['REMOTE_ADDR' => $clientIp, 'HTTP_USER_AGENT' => 'Rate limiter test'],
        ));

        return $response ?? throw new \RuntimeException('A login attempt must return a response.');
    }
}
