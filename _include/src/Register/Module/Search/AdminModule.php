<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search;

use Psr\Log\LoggerInterface;
use Register\Content\ContentId;
use S2\AdminYard\SettingStorage\SettingStorageInterface;
use S2\AdminYard\TemplateRenderer;
use S2\AdminYard\Translator;
use S2\Cms\Admin\Dashboard\DashboardStatProviderInterface;
use S2\Cms\Admin\DynamicConfigFormExtenderInterface;
use S2\Cms\Admin\Event\AdminAjaxControllerMapEvent;
use S2\Cms\Admin\Event\VisibleEntityChangedEvent;
use S2\Cms\Admin\TranslationProviderInterface;
use S2\Cms\AdminYard\CustomMenuGeneratorEvent;
use S2\Cms\AdminYard\Signal;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ContainerAwareListenerModuleInterface;
use S2\Cms\Framework\ContainerModuleInterface;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Queue\QueuePublisher;
use S2\Rose\Indexer;
use S2\Rose\Storage\Database\PdoStorage;
use Register\Module\Search\Admin\DashboardSearchProvider;
use Register\Module\Search\Admin\DynamicConfigFormExtender;
use Register\Module\Search\Admin\IndexManager;
use Register\Module\Search\Admin\ReindexToken;
use Register\Module\Search\Admin\TranslationProvider;
use Register\Module\Search\Service\BulkIndexingProviderInterface;
use Register\Module\Search\Service\ContentIndexer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminModule implements ContainerModuleInterface, ContainerAwareListenerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(DynamicConfigFormExtender::class, new DynamicConfigFormExtender(), [DynamicConfigFormExtenderInterface::class]);

        $container->set(TranslationProvider::class, new TranslationProvider(), [TranslationProviderInterface::class]);

        $container->set(ReindexToken::class, fn(Container $container): ReindexToken => new ReindexToken(
            $container->get(SettingStorageInterface::class),
        ));

        $container->set(DashboardSearchProvider::class, fn(Container $container): DashboardSearchProvider => new DashboardSearchProvider(
            $container->get(TemplateRenderer::class),
            $container->get(PdoStorage::class),
            $container->get(ReindexToken::class),
        ), [DashboardStatProviderInterface::class]);

        $container->set(IndexManager::class, fn(Container $container): IndexManager => new IndexManager(
            $container->getStringParameter('cache_dir'),
            $container->get(Indexer::class),
            $container->get(PdoStorage::class),
            $container->get('recommendations_cache'),
            $container->get(LoggerInterface::class),
            ...$container->getByTag(BulkIndexingProviderInterface::class),
        ));
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(VisibleEntityChangedEvent::class, function (VisibleEntityChangedEvent $event) use ($container): void {
            $contentId = match ($event->entityName) {
                'Article' => ContentId::page($event->entityId),
                'BlogPost' => ContentId::post($event->entityId),
                default => null,
            };
            if (!$contentId instanceof ContentId) {
                return;
            }

            $queuePublisher = $container->get(QueuePublisher::class);
            $queuePublisher->publish((string)$contentId, ContentIndexer::QUEUE_CODE);
        });

        $eventDispatcher->addListener(AdminAjaxControllerMapEvent::class, static function (AdminAjaxControllerMapEvent $event): void {
            $event->controllerMap['register_search_reindex'] = static function (PermissionChecker $p, Request $r, Container $c): JsonResponse {
                if ($r->getRealMethod() !== Request::METHOD_POST) {
                    return new JsonResponse(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$p->isGrantedAny(PermissionChecker::PERMISSION_CREATE_ARTICLES, PermissionChecker::PERMISSION_EDIT_SITE)) {
                    return new JsonResponse(['success' => false, 'message' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
                }

                if (!$c->get(ReindexToken::class)->matches($r->request->getString('csrf_token'))) {
                    return new JsonResponse(['success' => false, 'message' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
                }

                $indexManager = $c->get(IndexManager::class);
                return new JsonResponse([
                    'success' => true,
                    'status'  => $indexManager->index(),
                ]);
            };
        });

        $eventDispatcher->addListener(CustomMenuGeneratorEvent::class, function (CustomMenuGeneratorEvent $event) use ($container): void {
            try {
                $pdoStorage = $container->get(PdoStorage::class);
                $size       = $pdoStorage->getTocSize(null);
            } catch (\Throwable) {
                $size = 0;
            }

            if ($size === 0) {
                $translator = $container->get(Translator::class);
                $event->addSignal('Dashboard', Signal::createEmpty($translator->trans('Indexing required')));
            }
        });
    }

}
