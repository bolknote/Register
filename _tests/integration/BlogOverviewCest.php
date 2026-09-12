<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Comment\CommentRepository;
use Register\Comment\CommentSchema;
use Register\Content\Admin\BlogOverviewRepository;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Register\Module\Analytics\AnalyticsIngestor;
use Register\Module\Analytics\AnalyticsReportCache;
use Register\Module\Analytics\AnalyticsSchema;

final class BlogOverviewCest
{
    public function explainsAnEmptyBlogAndKeepsTechnicalPanelsInSystemStatus(\IntegrationTester $I): void
    {
        $this->clearAudienceCache($I);
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Dashboard');
        $I->seeResponseCodeIs(200);
        $I->see('No comments are waiting for review.', '.overview-moderation');
        $I->see('No published comments yet.', '.overview-comments');
        $I->see('No drafts. Start a new post using the + button on the blog.', '.overview-drafts');
        $I->see('No posts scheduled.', '.overview-schedule');
        $I->see('No published posts yet.', '.overview-recent-posts');
        $I->see('No visits recorded in this period yet.', '.overview-audience');
        $I->see('No post views recorded in this period yet.', '.overview-audience');
        $I->assertCount(7, $I->grabMultiple('.overview-empty'));
        $I->dontSeeElement('.overview-list');
        $I->dontSeeElement('.overview-metrics');
        $I->dontSeeElement('.security-stat-item');
        $I->dontSeeElement('.environment-stat-item');
        $I->dontSeeElement('.performance-stat-item');
        $I->dontSeeElement('.query-profiler-stat-item');
        $I->dontSeeElement('.page-cache-stat-item');
        $this->exportPreview($I->grabResponse(), true);

        $I->amOnPage('https://localhost/_admin/index.php?entity=SystemStatus');
        $I->seeResponseCodeIs(200);
        $I->see('Security monitoring', '.security-stat-item h3');
        $I->seeElement('.environment-stat-item');
        $I->dontSeeElement('.overview-editorial');
    }

    public function boundsEditorialListsAndKeepsStableOrderingAndAuthorScope(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabAdminService(DbLayer::class);
        /** @var BlogOverviewRepository $repository */
        $repository = $I->grabAdminService(BlogOverviewRepository::class);
        $now = time();
        $author = $this->userId($db, 'author');
        $admin = $this->userId($db, 'admin');
        $recent = [];
        $drafts = [];
        $scheduled = [];
        for ($index = 1; $index <= 7; ++$index) {
            $recent[] = $this->post($db, 'recent-' . $index, $author, $now - 10, true, $now - 10);
            $drafts[] = $this->post($db, 'draft-' . $index, $author, $now, false);
            $scheduled[] = $this->post($db, 'scheduled-' . $index, $author, $now, false, null, $now + 3600);
        }

        $otherDraft = $this->post($db, 'other-author-draft', $admin, $now + 1, false);
        $otherScheduled = $this->post($db, 'other-author-scheduled', $admin, $now, false, null, $now + 1800);
        $legacyFuture = $this->post($db, 'future-published', $author, $now, true, $now + 1800);
        $otherLegacyFuture = $this->post($db, 'other-future-published', $admin, $now, true, $now + 900);
        $this->post($db, 'missing-publication-time', $author, $now, true);

        $snapshot = $repository->snapshot($now, true, false, $author, false);
        $I->assertSame(['drafts' => 7, 'scheduled' => 8, 'overdue' => 0], $snapshot['queue']);
        $I->assertSame(array_slice(array_reverse($recent), 0, 5), $this->ids($snapshot['recent']));
        $I->assertSame(array_slice(array_reverse($drafts), 0, 5), $this->ids($snapshot['drafts']));
        $I->assertSame([$legacyFuture, ...array_slice($scheduled, 0, 4)], $this->ids($snapshot['scheduled']));
        $I->assertSame($now + 1800, (int)$snapshot['scheduled'][0]['scheduled_at']);
        $I->assertSame('/draft-7', $snapshot['drafts'][0]['url']);
        $I->assertArrayNotHasKey('body', $snapshot['drafts'][0]);
        $I->assertSame($snapshot, $repository->snapshot($now, true, false, $author, false));

        $adminSnapshot = $repository->snapshot($now, true, true, $admin, true);
        $I->assertSame(['drafts' => 8, 'scheduled' => 10, 'overdue' => 0], $adminSnapshot['queue']);
        $I->assertSame([$otherLegacyFuture, $otherScheduled, $legacyFuture, ...array_slice($scheduled, 0, 2)], $this->ids($adminSnapshot['scheduled']));
        $I->assertSame($otherDraft, (int)$adminSnapshot['drafts'][0]['id']);
        $I->assertCount(5, $adminSnapshot['drafts']);

        $noWrite = $repository->snapshot($now, false, false, $author, false);
        $I->assertSame(['drafts' => 0, 'scheduled' => 0, 'overdue' => 0], $noWrite['queue']);
        $I->assertSame([], $noWrite['drafts']);
        $I->assertSame([], $noWrite['scheduled']);
        $I->assertSame($snapshot['recent'], $noWrite['recent']);
    }

    public function distinguishesActionableModerationFromHiddenSpamAndDeletedComments(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabAdminService(DbLayer::class);
        /** @var BlogOverviewRepository $repository */
        $repository = $I->grabAdminService(BlogOverviewRepository::class);
        $now = time();
        $admin = $this->userId($db, 'admin');
        $post = $this->post($db, 'discussion-for-overview', $admin, $now, true, $now - 100);
        $draft = $this->post($db, 'unpublished-discussion', $admin, $now, false);
        $future = $this->post($db, 'future-discussion', $admin, $now, true, $now + 100);
        $pending = [];
        $visible = [];
        for ($index = 1; $index <= 7; ++$index) {
            $pending[] = $this->comment($db, $post, $now - 10, 'Pending ' . $index);
            $visible[] = $this->comment($db, $post, $now - 10, 'Published ' . $index, true);
        }

        $this->comment($db, $post, $now, 'Already hidden', false, true);
        $this->comment($db, $post, $now, 'Deleted', false, false, true);
        $this->comment($db, $post, $now, 'Deleted but shown', true, false, true);
        $spamId = $this->comment($db, $post, $now, 'Confirmed spam');
        /** @var CommentRepository $comments */
        $comments = $I->grabAdminService(CommentRepository::class);
        $comments->markSpam($spamId, ContentType::POST);
        foreach ([$draft, $future] as $target) {
            $this->comment($db, $target, $now, 'Unavailable pending');
            $this->comment($db, $target, $now, 'Unavailable visible', true);
        }

        $snapshot = $repository->snapshot($now, true, true, $admin, true);
        $I->assertSame(7, $snapshot['pendingCount']);
        $I->assertSame(array_slice(array_reverse($pending), 0, 5), $this->ids($snapshot['pending']));
        $I->assertSame(array_slice(array_reverse($visible), 0, 5), $this->ids($snapshot['comments']));
        $I->assertSame('/discussion-for-overview#comment-' . end($pending), $snapshot['pending'][0]['url']);
        $I->assertArrayNotHasKey('text', $snapshot['pending'][0]);
        $I->assertArrayNotHasKey('email', $snapshot['pending'][0]);

        $nonModerator = $repository->snapshot($now, true, true, $admin, false);
        $I->assertSame(0, $nonModerator['pendingCount']);
        $I->assertSame([], $nonModerator['pending']);
        $I->assertSame($snapshot['comments'], $nonModerator['comments']);
    }

    public function distinguishesDuePublicationAndOffersRealSafeLinks(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabAdminService(DbLayer::class);
        /** @var BlogOverviewRepository $repository */
        $repository = $I->grabAdminService(BlogOverviewRepository::class);
        $now = time();
        $admin = $this->userId($db, 'admin');
        $this->post($db, 'work-in-progress', $admin, $now - 60, false);
        $overdue = $this->post($db, 'publication-overdue', $admin, $now - 300, false, null, $now - 60);
        $due = $this->post($db, 'publication-due-now', $admin, $now - 300, false, null, $now);
        $next = $this->post($db, 'publication-next', $admin, $now - 300, false, null, $now + 3600);
        $legacyNext = $this->post($db, 'legacy-publication-next', $admin, $now - 300, true, $now + 1800);
        $this->post($db, 'already-published-at-boundary', $admin, $now - 300, true, $now, $now - 60);
        $post = $this->post($db, 'walking & writing', $admin, $now - 600, true, $now - 600,
            title: 'Notes on writing <img src=x onerror="alert(1)">');
        $maliciousNick = '<img src=x onerror="alert(2)">';
        $maliciousText = '<p>A useful question &amp; &lt;img src=x onerror="alert(3)"&gt; ' . str_repeat('слово ', 45) . '</p>';
        $pending = $this->comment($db, $post, $now - 90, $maliciousText, nick: $maliciousNick);
        $this->comment($db, $post, $now - 120, '<p>Thanks for the detailed explanation! What would you recommend trying first?</p>', true, nick: 'A reader');
        $this->comment($db, $post, $now - 600, '<p>I tried this approach over the weekend. It works well for small projects.</p>', true, nick: 'Another reader');

        $snapshot = $repository->snapshot($now, true, true, $admin, true);
        $I->assertSame(['drafts' => 1, 'scheduled' => 2, 'overdue' => 2], $snapshot['queue']);
        $I->assertSame([$overdue, $due, $legacyNext, $next], $this->ids($snapshot['scheduled']));
        $I->assertSame($now + 1800, (int)$snapshot['scheduled'][2]['scheduled_at']);
        $I->assertSame('/walking%20%26%20writing#comment-' . $pending, $snapshot['pending'][0]['url']);
        $I->assertSame(180, mb_strlen($snapshot['pending'][0]['snippet']));
        $I->assertStringEndsWith('…', $snapshot['pending'][0]['snippet']);
        $I->assertStringContainsString('A useful question & <img', $snapshot['pending'][0]['snippet']);

        $this->seedAudience($db, $post);
        $this->clearAudienceCache($I);

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Dashboard');
        $I->seeResponseCodeIs(200);
        $I->seeElement('.overview-moderation .overview-comment-link[href="/walking%20%26%20writing#comment-' . $pending . '"]');
        $I->seeElement('.overview-drafts a[href="/work-in-progress"]');
        $I->seeElement('.overview-schedule a[href="/publication-next"]');
        $I->dontSeeElement('.overview-editorial img');
        $I->dontSeeElement('.overview-editorial script');
        $I->dontSeeElement('.overview-editorial [onerror]');
        $I->dontSeeElement('.overview-editorial a[href*="entity=Comment"]');
        $I->seeElement('.overview-audience');
        $I->see('280', '.overview-audience');
        $I->see('How I organize notes for the blog', '.overview-audience');
        $I->see($maliciousNick, '.overview-moderation .overview-item-meta');

        $html = $I->grabResponse();
        $I->assertStringContainsString('&lt;img src=x onerror=&quot;alert(2)&quot;&gt;', $html);
        $this->exportPreview($html);
    }

    public function givesAuthorsAndSiteEditorsOnlyTheActionsTheyCanUse(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabAdminService(DbLayer::class);
        $now = time();
        $this->post($db, 'authors-own-draft', $this->userId($db, 'author'), $now, false);
        $this->post($db, 'another-authors-draft', $this->userId($db, 'admin'), $now, false);

        $I->login('author', 'author');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Dashboard');
        $I->seeResponseCodeIs(200);
        $I->seeElement('.overview-drafts a[href="/authors-own-draft"]');
        $I->dontSeeElement('.overview-drafts a[href="/another-authors-draft"]');
        $I->dontSeeElement('.overview-moderation');
        $I->logout();

        $I->login('editor', 'editor');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Dashboard');
        $I->seeResponseCodeIs(200);
        $I->seeElement('.overview-drafts a[href="/authors-own-draft"]');
        $I->seeElement('.overview-drafts a[href="/another-authors-draft"]');
        $I->logout();

        $I->login('power_guest', 'power_guest');
        $I->amOnPage('https://localhost/_admin/index.php?entity=Dashboard');
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('.overview-drafts');
        $I->dontSeeElement('.overview-schedule');
        $I->dontSeeElement('.overview-moderation');
        $I->seeElement('.overview-recent-posts');
    }

    public function includesOnlyPageCommentsWithAnAccessiblePublicPath(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabAdminService(DbLayer::class);
        /** @var BlogOverviewRepository $repository */
        $repository = $I->grabAdminService(BlogOverviewRepository::class);
        $now = time();
        $admin = $this->userId($db, 'admin');
        $visibleRoot = $this->post($db, 'overview-section', $admin, $now, true);
        $hiddenRoot = $this->post($db, 'private-overview-section', $admin, $now, false);
        $visibleChild = $this->post($db, 'public-child', $admin, $now, true);
        $hiddenChild = $this->post($db, 'private-child', $admin, $now, true);
        foreach ([$visibleRoot, $hiddenRoot, $visibleChild, $hiddenChild] as $id) {
            $db->update(ContentSchema::TABLE_NAME)->set('content_type', "'page'")
                ->where('id = :id')->setParameter('id', $id)->execute();
        }

        foreach ([$visibleChild => $visibleRoot, $hiddenChild => $hiddenRoot] as $child => $parent) {
            $db->update(ContentSchema::TABLE_NAME)->set('parent_id', ':parent')->setParameter('parent', $parent)
                ->set('slug_scope', ':scope')->setParameter('scope', 'page:' . $parent)
                ->where('id = :id')->setParameter('id', $child)->execute();
        }

        $comment = $this->comment($db, $visibleChild, $now - 10, 'Please check this permanent page.', type: ContentType::PAGE);
        $this->comment($db, $hiddenChild, $now, 'An inaccessible pending comment.', type: ContentType::PAGE);
        $this->comment($db, $hiddenChild, $now, 'An inaccessible visible comment.', true, type: ContentType::PAGE);

        $snapshot = $repository->snapshot($now, true, true, $admin, true);
        $I->assertSame(1, $snapshot['pendingCount']);
        $I->assertSame([$comment], $this->ids($snapshot['pending']));
        $I->assertSame('/overview-section/public-child#comment-' . $comment, $snapshot['pending'][0]['url']);
        $I->assertSame([], $snapshot['comments']);
    }

    public function keepsLegacyRowsWithoutValidPathsFromBreakingTheOverview(\IntegrationTester $I): void
    {
        /** @var DbLayer $db */
        $db = $I->grabAdminService(DbLayer::class);
        /** @var BlogOverviewRepository $repository */
        $repository = $I->grabAdminService(BlogOverviewRepository::class);
        $now = time();
        $admin = $this->userId($db, 'admin');
        $draft = $this->post($db, 'legacy-unlinked-draft', $admin, $now, false);
        $db->update(ContentSchema::TABLE_NAME)->set('slug', "''")->set('slug_scope', "'legacy-draft'")
            ->where('id = :id')->setParameter('id', $draft)->execute();
        $brokenPost = $this->post($db, 'legacy//broken-path', $admin, $now, true, $now);
        $this->comment($db, $brokenPost, $now, 'No usable destination');

        $snapshot = $repository->snapshot($now, true, true, $admin, true);
        $I->assertSame(1, $snapshot['queue']['drafts']);
        $I->assertSame($draft, (int)$snapshot['drafts'][0]['id']);
        $I->assertNull($snapshot['drafts'][0]['url']);
        $I->assertNull($snapshot['recent'][0]['url']);
        $I->assertSame(0, $snapshot['pendingCount']);
        $I->assertSame([], $snapshot['pending']);
    }

    private function post(DbLayer $db, string $slug, int $author, int $updatedAt, bool $published, ?int $publishedAt = null, int $scheduledAt = 0, ?string $title = null): int
    {
        $db->insert(ContentSchema::TABLE_NAME)->values([
            'content_type' => "'post'", 'slug_scope' => "'root'", 'slug' => ':slug',
            'title' => ':title', 'excerpt' => "''", 'body' => "'<p>Overview fixture</p>'",
            'created_at' => ':created', 'updated_at' => ':updated', 'published' => ':published',
            'published_at' => ':published_at', 'scheduled_at' => ':scheduled_at', 'author_id' => ':author',
        ])->execute([
            'slug' => $slug, 'title' => $title ?? ucfirst(str_replace('-', ' ', $slug)),
            'created' => $updatedAt, 'updated' => $updatedAt, 'published' => (int)$published,
            'published_at' => $publishedAt, 'scheduled_at' => $scheduledAt, 'author' => $author,
        ]);
        return (int)$db->insertId();
    }

    private function comment(DbLayer $db, int $post, int $time, string $text, bool $shown = false, bool $sent = false, bool $deleted = false, string $nick = 'Reader', ContentType $type = ContentType::POST): int
    {
        $db->insert(CommentSchema::TABLE_NAME)->values([
            'content_type' => ':type', 'content_id' => ':post', 'time' => ':time', 'nick' => ':nick',
            'email' => "'private-reader@example.test'", 'ip' => "'127.0.0.1'", 'text' => ':text',
            'shown' => ':shown', 'sent' => ':sent', 'deleted' => ':deleted',
        ])->execute([
            'type' => $type->value, 'post' => $post, 'time' => $time, 'nick' => $nick, 'text' => $text,
            'shown' => (int)$shown, 'sent' => (int)$sent, 'deleted' => (int)$deleted,
        ]);
        return (int)$db->insertId();
    }

    private function userId(DbLayer $db, string $login): int
    {
        return (int)$db->select('id')->from('users')->where('login = :login')
            ->setParameter('login', $login)->execute()->result();
    }

    private function seedAudience(DbLayer $db, int $postId): void
    {
        $prefix = $db->getPrefix();
        $key = hash('sha256', 'overview-fixture-post');
        $db->query('INSERT INTO ' . $prefix . AnalyticsSchema::PAGE_TABLE
            . ' (page_key, path, title, first_seen_at, last_seen_at) VALUES (?, ?, ?, 0, 0)',
            [$key, '/walking%20%26%20writing', 'How I organize notes for the blog']);
        $db->query('INSERT INTO ' . $prefix . AnalyticsSchema::PAGE_METADATA_TABLE
            . ' (page_key, content_type, content_id, author_key, section_key, published_at, word_count, first_seen_at, last_seen_at) '
            . "VALUES (?, 'post', ?, '', '', 0, 0, 0, 0)", [$key, (string)$postId]);
        $today = new \DateTimeImmutable('today');
        foreach ([['-1 day', 280, 98], ['-8 days', 140, 49]] as [$offset, $views, $readers]) {
            $db->query('INSERT INTO ' . $prefix . AnalyticsSchema::DAY_ROLLUP_TABLE
                . ' (bucket, dimension, dimension_key, views, sessions, unique_count, bounces, engaged_seconds) '
                . ' VALUES (?, ?, ?, ?, 0, ?, 0, 0)', [
                    $today->modify($offset)->format('Y-m-d'), AnalyticsIngestor::DIMENSION_GLOBAL,
                    AnalyticsIngestor::GLOBAL_KEY, $views, $readers,
                ]);
        }

        $db->query('INSERT INTO ' . $prefix . AnalyticsSchema::DAY_ROLLUP_TABLE
            . ' (bucket, dimension, dimension_key, views, sessions, unique_count, bounces, engaged_seconds) '
            . ' VALUES (?, ?, ?, 80, 0, 20, 0, 0)', [
                $today->modify('-1 day')->format('Y-m-d'), AnalyticsIngestor::DIMENSION_PAGE, $key,
            ]);
    }

    private function clearAudienceCache(\IntegrationTester $I): void
    {
        /** @var AnalyticsReportCache $cache */
        $cache = $I->grabAdminService(AnalyticsReportCache::class);
        $cache->clear();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn(array $row): int => (int)$row['id'], $rows);
    }

    private function exportPreview(string $html, bool $empty = false): void
    {
        $path = getenv('REGISTER_OVERVIEW_PREVIEW');
        if ($path === false || $path === '') {
            return;
        }

        if (preg_match('~\A/tmp/register-overview-[a-zA-Z0-9_.-]+\.html\z~D', $path) !== 1) {
            throw new \InvalidArgumentException('Overview preview must be a /tmp/register-overview-*.html file.');
        }

        if ($empty) {
            $path = '/tmp/register-overview-empty.html';
        }

        if (file_put_contents($path, $html) === false) {
            throw new \RuntimeException('Unable to save the requested overview preview.');
        }
    }
}
