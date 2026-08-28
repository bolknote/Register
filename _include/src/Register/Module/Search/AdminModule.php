<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search;

use Psr\Log\LoggerInterface;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Translator;
use Register\Admin\Dashboard\SystemStatusProviderInterface;
use Register\Admin\DynamicConfigFormExtenderInterface;
use Register\Admin\Event\AdminAjaxControllerMapEvent;
use Register\Admin\TranslationProviderInterface;
use Register\Core\AdminYard\CustomMenuGeneratorEvent;
use Register\Core\AdminYard\Signal;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerAwareListenerModuleInterface;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Model\PermissionChecker;
use Register\Core\Security\Http\AdminMutationGuard;
use Register\Rose\Storage\Database\PdoStorage;
use Register\Module\Search\Admin\DashboardSearchProvider;
use Register\Module\Search\Admin\DynamicConfigFormExtender;
use Register\Module\Search\Admin\ReindexToken;
use Register\Module\Search\Admin\SearchIndexHealth;
use Register\Module\Search\Admin\TranslationProvider;
use Register\Module\Search\Service\SearchIndexRepairer;
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
            $container->get(SearchIndexHealth::class),
        ), [SystemStatusProviderInterface::class]);

    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(AdminAjaxControllerMapEvent::class, static function (AdminAjaxControllerMapEvent $event): void {
            $event->controllerMap['register_search_reindex'] = static function (PermissionChecker $p, Request $r, Container $c): JsonResponse {
                if (!$c->get(AdminMutationGuard::class)->isPost($r)) {
                    return new JsonResponse(['success' => false, 'message' => 'Only POST requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                if (!$p->isGrantedAny(PermissionChecker::PERMISSION_CREATE_ARTICLES, PermissionChecker::PERMISSION_EDIT_SITE)) {
                    return new JsonResponse(['success' => false, 'message' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
                }

                if (!$c->get(AdminMutationGuard::class)->hasValidCsrfToken(
                    $r,
                    $c->get(ReindexToken::class)->value(),
                )) {
                    return new JsonResponse(['success' => false, 'message' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
                }

                $c->get(SearchIndexRepairer::class)->schedule();
                return new JsonResponse([
                    'success' => true,
                    'status'  => 'queued',
                ]);
            };
        });

        $eventDispatcher->addListener(CustomMenuGeneratorEvent::class, function (CustomMenuGeneratorEvent $event) use ($container): void {
            if (!$container->get(SearchIndexHealth::class)->inspect()->repairRequired) {
                return;
            }

            try {
                $container->get(SearchIndexRepairer::class)->schedule();
            } catch (\Throwable $throwable) {
                $container->get(LoggerInterface::class)->error(
                    'Unable to schedule automatic search-index repair.',
                    ['exception' => $throwable],
                );
                $translator = $container->get(Translator::class);
                $event->addSignal('Dashboard', Signal::createEmpty($translator->trans('Search repair required')));
            }
        });
    }

}
