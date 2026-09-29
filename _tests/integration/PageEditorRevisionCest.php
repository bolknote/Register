<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Codeception\Example;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\Tag;
use Register\Content\TagRepository;
use Register\Core\Pdo\DbLayer;

final class PageEditorRevisionCest
{
    /** @dataProvider _pageSettings */
    public function settingsChangesRejectOlderForms(\IntegrationTester $I, Example $example): void
    {
        [$id, $url, $older] = $this->draftPage($I);
        $field = (string)$example['field'];
        $changed = $older;
        $changed[$field] = match ($field) {
            'tags' => 'Three, Four',
            'template' => 'custom.php',
            'author_id' => (string)$I->grabAdminService(DbLayer::class)->select('id')->from('users')
                ->where('login = :login')->setParameter('login', 'author')->execute()->result(),
            'published_at', 'updated_at' => '2002-03-04T05:06',
            default => '1',
        };
        if (\in_array($field, ['featured', 'comments_enabled'], true) && isset($older[$field])) {
            unset($changed[$field]);
        }

        $I->sendAjaxPostRequest($url, $changed);
        $I->seeResponseCodeIs(200);

        $saved = $this->stored($I, $id);

        // Saving an unchanged old form must not silently restore its settings.
        foreach ([$older, [...$older, 'body' => '<p>Changes from the older tab.</p>']] as $stale) {
            $I->sendAjaxPostRequest($url, $stale);
            $I->seeResponseCodeIs(422);
            $I->assertJsonSubResponseEquals(false, ['success']);
            $I->assertSame($saved, $this->stored($I, $id));
        }

        $I->assertSame((int)$older['revision'] + 1, (int)$saved['revision']);

        $I->amOnPage($url);

        $fresh = $I->grabFormValues('form[name="article-form"]');
        $I->sendAjaxPostRequest($url, [...$fresh, 'body' => '<p>Changes after reloading.</p>']);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals((int)$saved['revision'] + 1, ['revision']);
        $I->assertSame('<p>Changes after reloading.</p>', $this->stored($I, $id)['body']);
    }

    /** @return list<array{field: string}> */
    public function _pageSettings(): array
    {
        return array_map(static fn(string $field): array => ['field' => $field], [
            'tags', 'template', 'comments_enabled', 'featured', 'author_id', 'published_at', 'updated_at',
        ]);
    }

    public function unchangedDatesPreserveSecondsAndRevision(\IntegrationTester $I): void
    {
        [$id, $url] = $this->draftPage($I);
        $db = $I->grabAdminService(DbLayer::class);
        // Existing timestamps can have seconds, while the form displays minutes.
        $db->update(ContentSchema::TABLE_NAME)
            ->set('published_at', ':published')->setParameter('published', strtotime('2002-03-04 05:06:57'))
            ->set('updated_at', ':updated')->setParameter('updated', strtotime('2003-04-05 06:07:38'))
            ->set('scheduled_at', ':scheduled')->setParameter('scheduled', strtotime('+1 day 12:34:59'))
            ->where('id = :id')->setParameter('id', $id)->execute();
        $before = $this->stored($I, $id);
        $I->amOnPage($url);
        $values = $I->grabFormValues('form[name="article-form"]');

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $I->sendAjaxPostRequest($url, $values);
            $I->seeResponseCodeIs(200);
            $I->assertSame($before, $this->stored($I, $id));
            $I->assertJsonSubResponseEquals((int)$before['revision'], ['revision']);
        }
    }

    public function changedDatesUseTheSelectedMinuteAndCanBeCleared(\IntegrationTester $I): void
    {
        [$id, $url, $values] = $this->draftPage($I);
        $dates = [
            'published_at' => '2002-03-04T05:06',
            'updated_at' => '2003-04-05T06:07',
            'scheduled_at' => date('Y-m-d\TH:i', strtotime('+1 day 12:34')),
        ];
        $I->sendAjaxPostRequest($url, [...$values, ...$dates, '_publication_state' => 'scheduled']);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals((int)$values['revision'] + 1, ['revision']);

        $saved = $this->stored($I, $id);
        foreach ($dates as $field => $date) {
            $I->assertSame(strtotime($date), (int)$saved[$field]);
        }

        $I->amOnPage($url);
        $values = $I->grabFormValues('form[name="article-form"]');
        $I->sendAjaxPostRequest($url, [...$values, 'updated_at' => '', 'scheduled_at' => '', '_publication_state' => 'draft']);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals((int)$saved['revision'] + 1, ['revision']);

        $cleared = $this->stored($I, $id);
        $I->assertSame(0, (int)$cleared['updated_at']);
        $I->assertSame(0, (int)$cleared['scheduled_at']);
        $I->assertSame($saved['published_at'], $cleared['published_at']);
    }

    public function equivalentTagsKeepRevisionButReorderingAdvancesIt(\IntegrationTester $I): void
    {
        [$id, $url, $values] = $this->draftPage($I);
        $before = $this->stored($I, $id);
        $I->sendAjaxPostRequest($url, [...$values, 'tags' => ' one, , ДВА, One, два ']);
        $I->seeResponseCodeIs(200);
        $I->assertSame($before, $this->stored($I, $id));
        $I->assertJsonSubResponseEquals((int)$before['revision'], ['revision']);

        $I->sendAjaxPostRequest($url, [...$values, 'tags' => 'Два, One']);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals((int)$before['revision'] + 1, ['revision']);
        $I->assertSame(['Два', 'One'], $this->stored($I, $id)['tags']);

        $I->amOnPage($url);

        $reordered = $I->grabFormValues('form[name="article-form"]');
        $I->assertSame('Два, One', $reordered['tags']);
        $I->sendAjaxPostRequest($url, $reordered);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals((int)$before['revision'] + 1, ['revision']);
        $I->assertSame(['Два', 'One'], $this->stored($I, $id)['tags']);
    }

    public function authorCanSaveWithoutAdministratorOnlyFields(\IntegrationTester $I): void
    {
        [$id, $url, $values] = $this->draftPage($I);
        $db = $I->grabAdminService(DbLayer::class);
        $authorId = $db->select('id')->from('users')->where('login = :login')
            ->setParameter('login', 'author')->execute()->result();
        $I->sendAjaxPostRequest($url, [...$values, 'author_id' => (string)$authorId]);
        $I->seeResponseCodeIs(200);
        $I->logout();
        $I->login('author', 'author');
        $I->amOnPage($url);

        $values = $I->grabFormValues('form[name="article-form"]');
        $I->assertArrayNotHasKey('author_id', $values);
        $I->assertArrayNotHasKey('featured', $values);
        $I->sendAjaxPostRequest($url, [...$values, 'tags' => 'Author tag']);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals((int)$values['revision'] + 1, ['revision']);
        $I->assertSame(['Author tag'], $this->stored($I, $id)['tags']);
    }

    /** @return array{int, string, array<string, mixed>} */
    private function draftPage(\IntegrationTester $I): array
    {
        $I->login('admin', 'admin');
        $db = $I->grabAdminService(DbLayer::class);
        $id = (int)$db->select('id')->from(ContentSchema::TABLE_NAME)
            ->where("content_type = 'page'")->andWhere('parent_id IS NULL')->execute()->result();
        $url = 'https://localhost/_admin/index.php?entity=Article&action=edit&id=' . $id;
        $I->amOnPage($url);
        $values = $I->grabFormValues('form[name="article-form"]');
        unset($values['published']);
        $I->sendAjaxPostRequest($url, [...$values,
            'body' => '<p>Original page.</p>', 'meta_description' => 'Original page.',
            'tags' => 'One, Два', 'scheduled_at' => '', '_publication_state' => 'draft',
        ]);
        $I->seeResponseCodeIs(200);
        $I->amOnPage($url);
        return [$id, $url, $I->grabFormValues('form[name="article-form"]')];
    }

    /** @return array<string, mixed> */
    private function stored(\IntegrationTester $I, int $id): array
    {
        $db = $I->grabAdminService(DbLayer::class);
        $row = $db->select('title, body, revision, published, published_at, updated_at, scheduled_at, template, comments_enabled, featured, author_id')
            ->from(ContentSchema::TABLE_NAME)->where('id = :id')->setParameter('id', $id)->execute()->fetchAssoc();
        $I->assertIsArray($row);
        $contentId = ContentId::page($id);
        $tags = $I->grabAdminService(TagRepository::class)->findForContent([$contentId]);
        return [...$row, 'tags' => array_map(static fn(Tag $tag): string => $tag->name, $tags[(string)$contentId])];
    }
}
