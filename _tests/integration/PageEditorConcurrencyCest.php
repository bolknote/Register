<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Admin\AdminConfigProvider;
use Register\AdminYard\Config\EntityConfig;
use Register\AdminYard\Controller\ControllerFactoryInterface;
use Register\AdminYard\Controller\DefaultControllerFactory;
use Register\AdminYard\Controller\EntityController;
use Register\AdminYard\Database\PdoDataProvider;
use Register\AdminYard\Event\BeforeSaveEvent;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Transformer\ViewTransformer;
use Register\AdminYard\Translator;
use Register\Content\ContentPublicationScheduler;
use Register\Content\ContentSchema;
use Register\Core\Pdo\DbLayer;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PageEditorConcurrencyCest
{
    public function scheduledPublicationBetweenValidationAndWriteCannotBeReverted(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $db = $I->grabAdminService(DbLayer::class);
        $id = (int)$db->select('id')->from(ContentSchema::TABLE_NAME)
            ->where("content_type = 'page'")->andWhere('parent_id IS NULL')->execute()->result();
        $url = 'https://localhost/_admin/index.php?entity=Article&action=edit&id=' . $id;
        $I->amOnPage($url);
        $values = $I->grabFormValues('form[name="article-form"]');
        unset($values['published']);
        $scheduled = strtotime('+1 day 12:34');
        $I->sendAjaxPostRequest($url, [...$values,
            'body' => '<p>Scheduled page.</p>', 'meta_description' => 'Scheduled page.',
            'scheduled_at' => date('Y-m-d\TH:i', $scheduled), '_publication_state' => 'scheduled',
        ]);
        $I->seeResponseCodeIs(200);
        $I->amOnPage($url);

        $values = $I->grabFormValues('form[name="article-form"]');
        $entity = $I->grabAdminService(AdminConfigProvider::class)->getAdminConfig()->findEntityByName('Article');
        $I->assertInstanceOf(EntityConfig::class, $entity);
        $events = new EventDispatcher();
        foreach ($entity->getListeners() as $eventName => $listeners) {
            foreach ($listeners as $listener) {
                $events->addListener('adminyard.' . $eventName, $listener);
            }
        }

        // A maintenance worker can publish after the editor has read and validated its revision.
        $scheduler = $I->grabAdminService(ContentPublicationScheduler::class);
        $events->addListener('adminyard.Article.' . EntityConfig::EVENT_BEFORE_UPDATE,
            static function (BeforeSaveEvent $event) use ($I, $scheduler, $scheduled): void {
                $I->assertSame([], $event->errorMessages);
                $I->assertSame(1, $scheduler->publishDue($scheduled));
            },
        );
        $controller = $this->controller($I, $entity, $events);

        $response = $controller->editAction(Request::create($url, 'POST',
            [...$values, 'body' => '<p>Changes submitted before publication.</p>'], [], [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        ));
        $I->assertInstanceOf(Response::class, $response);
        $I->assertSame(422, $response->getStatusCode());

        $stored = $db->select('body, revision, published, published_at, scheduled_at')
            ->from(ContentSchema::TABLE_NAME)->where('id = :id')->setParameter('id', $id)->execute()->fetchAssoc();
        $I->assertIsArray($stored);
        $I->assertSame('<p>Scheduled page.</p>', $stored['body']);
        $I->assertSame((int)$values['revision'] + 1, (int)$stored['revision']);
        $I->assertSame(1, (int)$stored['published']);
        $I->assertSame($scheduled, (int)$stored['published_at']);
        $I->assertSame(0, (int)$stored['scheduled_at']);
    }

    public function competingEditorAfterValidationKeepsItsContentAndTags(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $db = $I->grabAdminService(DbLayer::class);
        $id = (int)$db->select('id')->from(ContentSchema::TABLE_NAME)
            ->where("content_type = 'page'")->andWhere('parent_id IS NULL')->execute()->result();
        $url = 'https://localhost/_admin/index.php?entity=Article&action=edit&id=' . $id;
        $I->amOnPage($url);
        $values = $I->grabFormValues('form[name="article-form"]');
        $I->sendAjaxPostRequest($url, [...$values, 'body' => '<p>Original page.</p>', 'tags' => 'Original']);
        $I->seeResponseCodeIs(200);
        $I->amOnPage($url);

        $values = $I->grabFormValues('form[name="article-form"]');
        $entity = $I->grabAdminService(AdminConfigProvider::class)->getAdminConfig()->findEntityByName('Article');
        $I->assertInstanceOf(EntityConfig::class, $entity);
        $events = new EventDispatcher();
        foreach ($entity->getListeners() as $eventName => $listeners) {
            foreach ($listeners as $listener) {
                $events->addListener('adminyard.' . $eventName, $listener);
            }
        }

        $interleaved = false;
        $events->addListener('adminyard.Article.' . EntityConfig::EVENT_BEFORE_UPDATE,
            function (BeforeSaveEvent $event) use ($I, $entity, $events, $url, $values, &$interleaved): void {
                if ($interleaved) {
                    return;
                }

                $interleaved = true;
                $I->assertSame([], $event->errorMessages);
                $response = $this->controller($I, $entity, $events)->editAction(Request::create($url, 'POST',
                    [...$values, 'body' => '<p>Saved by the other editor.</p>', 'tags' => 'Other editor'], [], [],
                    ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
                ));
                $I->assertInstanceOf(Response::class, $response);
                $I->assertSame(200, $response->getStatusCode());
            },
        );
        $response = $this->controller($I, $entity, $events)->editAction(Request::create($url, 'POST',
            [...$values, 'body' => '<p>Stale editor changes.</p>', 'tags' => 'Stale editor'], [], [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        ));
        $I->assertInstanceOf(Response::class, $response);
        $I->assertSame(422, $response->getStatusCode());

        $stored = $db->select('body, revision')->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $id)->execute()->fetchAssoc();
        $I->assertIsArray($stored);
        $I->assertSame('<p>Saved by the other editor.</p>', $stored['body']);
        $I->assertSame((int)$values['revision'] + 1, (int)$stored['revision']);
        $I->amOnPage($url);
        $I->assertSame('Other editor', $I->grabFormValues('form[name="article-form"]')['tags']);

        $fresh = $I->grabFormValues('form[name="article-form"]');
        $I->sendAjaxPostRequest($url, [...$fresh, 'body' => '<p>Changes after reloading.</p>']);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals((int)$values['revision'] + 2, ['revision']);
    }

    public function olderFormMatchingTheCurrentContentRemainsANoOp(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $db = $I->grabAdminService(DbLayer::class);
        $id = (int)$db->select('id')->from(ContentSchema::TABLE_NAME)
            ->where("content_type = 'page'")->andWhere('parent_id IS NULL')->execute()->result();
        $url = 'https://localhost/_admin/index.php?entity=Article&action=edit&id=' . $id;
        $I->amOnPage($url);
        $values = $I->grabFormValues('form[name="article-form"]');
        $I->sendAjaxPostRequest($url, [...$values, 'body' => '<p>Original page.</p>', 'tags' => 'Original']);
        $I->seeResponseCodeIs(200);
        $I->amOnPage($url);

        $older = $I->grabFormValues('form[name="article-form"]');
        $I->sendAjaxPostRequest($url, [...$older, 'body' => '<p>Temporary changes.</p>']);
        $I->seeResponseCodeIs(200);
        $I->amOnPage($url);

        $current = $I->grabFormValues('form[name="article-form"]');
        $I->sendAjaxPostRequest($url, [...$older, 'revision' => $current['revision']]);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals((int)$older['revision'] + 2, ['revision']);

        // The old form now matches the current content, although its revision is older.
        $I->sendAjaxPostRequest($url, $older);
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals((int)$older['revision'] + 2, ['revision']);
        $I->amOnPage($url);
        $I->assertSame('<p>Original page.</p>', $I->grabFormValues('form[name="article-form"]')['body']);
    }

    private function controller(\IntegrationTester $I, EntityConfig $entity, EventDispatcher $events): EntityController
    {
        $controllerClass = $entity->getControllerClassOrFactory() ?? EntityController::class;
        $factory = $controllerClass instanceof ControllerFactoryInterface
            ? $controllerClass : new DefaultControllerFactory($controllerClass);
        return $factory->create(
            $entity, $events, $I->grabAdminService(PdoDataProvider::class), new ViewTransformer(),
            $I->grabAdminService(Translator::class), $I->grabAdminService(TemplateRenderer::class),
            $I->grabAdminService(FormFactory::class), $I->grabAdminService(SettingStorageInterface::class),
        );
    }
}
