<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace integration;

use Codeception\Example;
use Register\Content\ContentSchema;
use Register\Core\Extensions\ExtensionManager;
use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\AdminExtension;
use Register\Extension\activitypub\Extension;
use Register\Extension\activitypub\Infrastructure\ActivityPubSchema;

final class ActivityPubEditorRevisionCest
{
    /** @dataProvider _federationFields */
    public function staleFormsCannotRestoreFederationSettings(\IntegrationTester $I, Example $example): void
    {
        $I->assertSame([], $I->grabAdminService(ExtensionManager::class)->installExtension('activitypub'));
        $I->withAdminModules([Extension::class, AdminExtension::class], function () use ($I, $example): void {
            [$id, $url, $older] = $this->page($I);
            $field = (string)$example['field'];
            $I->sendAjaxPostRequest($url, [...$older, $field => $example['value']]);
            $I->seeResponseCodeIs(200);

            $saved = $this->stored($I, $id);
            foreach ([$older, [...$older, 'body' => '<p>Old tab edit.</p>']] as $stale) {
                $I->sendAjaxPostRequest($url, $stale);
                $I->seeResponseCodeIs(422);
                $I->assertJsonSubResponseEquals(false, ['success']);
                $I->assertSame($saved, $this->stored($I, $id));
            }

            $I->assertSame((int)$older['revision'] + 1, (int)$saved['content']['revision']);

            $I->amOnPage($url);

            $fresh = $I->grabFormValues('form[name="article-form"]');
            $I->assertSame($example['value'], $fresh[$field]);
            $I->sendAjaxPostRequest($url, [...$fresh, 'body' => '<p>Edit after reloading.</p>']);
            $I->seeResponseCodeIs(200);
            $I->assertJsonSubResponseEquals((int)$saved['content']['revision'] + 1, ['revision']);
            $I->assertSame($saved['settings'], $this->stored($I, $id)['settings']);
        });
    }

    /** @return list<array{field: string, value: string}> */
    public function _federationFields(): array
    {
        return [
            ['field' => 'activitypub_publication', 'value' => 'disabled'],
            ['field' => 'activitypub_delivery', 'value' => 'excerpt'],
            ['field' => 'activitypub_visibility', 'value' => 'unlisted'],
            ['field' => 'activitypub_summary', 'value' => 'Spoilers'],
            ['field' => 'activitypub_language', 'value' => 'en-gb'],
        ];
    }

    public function normalizedSettingsDoNotCreateSpuriousRevisions(\IntegrationTester $I): void
    {
        $I->assertSame([], $I->grabAdminService(ExtensionManager::class)->installExtension('activitypub'));
        $I->withAdminModules([Extension::class, AdminExtension::class], function () use ($I): void {
            [$id, $url, $values] = $this->page($I);
            $I->sendAjaxPostRequest($url, [...$values,
                'activitypub_summary' => 'Spoilers', 'activitypub_language' => 'en-gb',
            ]);
            $I->seeResponseCodeIs(200);
            $I->assertJsonSubResponseEquals((int)$values['revision'] + 1, ['revision']);

            $saved = $this->stored($I, $id);
            $I->amOnPage($url);
            $values = $I->grabFormValues('form[name="article-form"]');
            foreach ([$values, [...$values, 'activitypub_summary' => '  Spoilers  ', 'activitypub_language' => ' EN-gB ']] as $equivalent) {
                $I->sendAjaxPostRequest($url, $equivalent);
                $I->seeResponseCodeIs(200);
                $I->assertJsonSubResponseEquals((int)$saved['content']['revision'], ['revision']);
                $I->assertSame($saved, $this->stored($I, $id));
            }

            $I->sendAjaxPostRequest($url, [...$values, 'activitypub_summary' => '', 'activitypub_language' => '']);
            $I->seeResponseCodeIs(200);
            $I->assertJsonSubResponseEquals((int)$saved['content']['revision'] + 1, ['revision']);

            $cleared = $this->stored($I, $id);
            $I->assertSame('', $cleared['settings']['summary']);
            $I->assertNull($cleared['settings']['language']);
        });
    }

    public function invalidSettingsDoNotSaveTheBodyOrConsumeARevision(\IntegrationTester $I): void
    {
        $I->assertSame([], $I->grabAdminService(ExtensionManager::class)->installExtension('activitypub'));
        $I->withAdminModules([Extension::class, AdminExtension::class], function () use ($I): void {
            [$id, $url, $values] = $this->page($I);
            $before = $this->stored($I, $id);
            $I->sendAjaxPostRequest($url, [...$values,
                'body' => '<p>Unsaved edit.</p>', 'activitypub_language' => 'not a language',
            ]);
            $I->seeResponseCodeIs(422);
            $I->assertJsonSubResponseEquals(false, ['success']);
            $I->assertSame($before, $this->stored($I, $id));
            $I->sendAjaxPostRequest($url, [...$values,
                'body' => '<p>Saved edit.</p>', 'activitypub_language' => 'ru',
            ]);
            $I->seeResponseCodeIs(200);
            $I->assertJsonSubResponseEquals((int)$values['revision'] + 1, ['revision']);

            $saved = $this->stored($I, $id);
            $I->assertSame('<p>Saved edit.</p>', $saved['content']['body']);
            $I->assertSame('ru', $saved['settings']['language']);
        });
    }

    /** @return array{int, string, array<string, mixed>} */
    private function page(\IntegrationTester $I): array
    {
        $I->login('admin', 'admin');
        $db = $I->grabAdminService(DbLayer::class);
        $id = (int)$db->select('id')->from(ContentSchema::TABLE_NAME)
            ->where("content_type = 'page'")->andWhere('parent_id IS NULL')->execute()->result();
        $url = 'https://localhost/_admin/index.php?entity=Article&action=edit&id=' . $id;
        $I->amOnPage($url);
        $values = $I->grabFormValues('form[name="article-form"]');
        $I->assertArrayHasKey('activitypub_publication', $values);
        unset($values['published']);
        $I->sendAjaxPostRequest($url, [...$values,
            'body' => '<p>Original page.</p>', 'meta_description' => 'Original page.',
            'scheduled_at' => '', '_publication_state' => 'draft',
        ]);
        $I->seeResponseCodeIs(200);
        $I->amOnPage($url);
        return [$id, $url, $I->grabFormValues('form[name="article-form"]')];
    }

    /** @return array{content: array<string, mixed>, settings: array<string, mixed>} */
    private function stored(\IntegrationTester $I, int $id): array
    {
        $db = $I->grabAdminService(DbLayer::class);
        $content = $db->select('body, revision, published')->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $id)->execute()->fetchAssoc();
        $settings = $db->select('publication_mode, delivery_mode, visibility, summary, language')
            ->from(ActivityPubSchema::CONTENT_SETTING_TABLE)
            ->where("local_type = 'page'")->andWhere('local_id = :id')->setParameter('id', $id)->execute()->fetchAssoc();
        $I->assertIsArray($content);
        $I->assertIsArray($settings);
        return ['content' => $content, 'settings' => $settings];
    }
}
