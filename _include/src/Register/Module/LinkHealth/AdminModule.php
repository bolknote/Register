<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use Register\Content\ContentChangeDispatcher;
use Register\Module\LinkHealth\Admin\AdminConfigExtender;
use Register\Module\LinkHealth\Admin\DynamicConfigFormExtender;
use Register\Module\LinkHealth\Admin\LinkHealthActionController;
use Register\Module\LinkHealth\Admin\LinkHealthAdminPage;
use Register\Module\LinkHealth\Admin\LinkHealthAdminRepository;
use Register\Module\LinkHealth\Admin\LinkHealthToken;
use Register\Module\LinkHealth\Admin\LocalLinkDeletionGuard;
use Register\Module\LinkHealth\Admin\TranslationProvider;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Translator;
use Register\Core\Admin\AdminConfigExtenderInterface;
use Register\Core\Admin\DynamicConfigFormExtenderInterface;
use Register\Core\Admin\Event\AdminAjaxControllerMapEvent;
use Register\Core\Admin\TranslationProviderInterface;
use Register\Core\AdminYard\CustomMenuGeneratorEvent;
use Register\Core\AdminYard\Signal;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerAwareListenerModuleInterface;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Model\PermissionChecker;
use Register\Core\Pdo\DbLayer;
use Register\Core\Queue\QueuePublisher;
use Register\Core\Security\Http\AdminMutationGuard;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AdminModule implements ContainerModuleInterface, ContainerAwareListenerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(TranslationProvider::class, new TranslationProvider(), [TranslationProviderInterface::class]);
        $container->set(DynamicConfigFormExtender::class, new DynamicConfigFormExtender(), [DynamicConfigFormExtenderInterface::class]);
        $container->set(LinkHealthAdminRepository::class, static fn(Container $container): LinkHealthAdminRepository => new LinkHealthAdminRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(LinkHealthToken::class, static fn(Container $container): LinkHealthToken => new LinkHealthToken(
            $container->get(SettingStorageInterface::class),
        ));
        $container->set(LinkHealthAdminPage::class, static fn(Container $container): LinkHealthAdminPage => new LinkHealthAdminPage(
            $container->get(LinkHealthAdminRepository::class),
            $container->get(LinkHealthToken::class),
            $container->get(TemplateRenderer::class),
            $container->get(Translator::class),
            $container->get(RequestStack::class),
            $container->get(DynamicConfigProvider::class)->getBoolProxy(Manifest::AUTO_REPAIR_CONFIG_KEY),
            $container->get(PermissionChecker::class),
            $container->getStringParameter('base_path'),
        ));
        $container->set(AdminConfigExtender::class, static fn(Container $container): AdminConfigExtender => new AdminConfigExtender(
            $container->get(PermissionChecker::class),
            $container->get(LocalLinkDeletionGuard::class),
            $container->get(ContentChangeDispatcher::class),
            $container->get(LinkHealthAdminPage::class),
        ), [AdminConfigExtenderInterface::class]);
        $container->set(LinkHealthActionController::class, static fn(Container $container): LinkHealthActionController => new LinkHealthActionController(
            $container->get(PermissionChecker::class),
            $container->get(LinkHealthToken::class),
            $container->get(LinkHealthRepository::class),
            $container->get(LinkHealthAdminRepository::class),
            $container->get(QueuePublisher::class),
            $container->get(Translator::class),
            $container->get(AdminMutationGuard::class),
        ));
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(AdminAjaxControllerMapEvent::class, static function (AdminAjaxControllerMapEvent $event) use ($container): void {
            $event->controllerMap['register_link_health_action'] = static function (
                PermissionChecker $permissionChecker,
                Request           $request,
            ) use ($container): \Symfony\Component\HttpFoundation\JsonResponse {
                unset($permissionChecker);

                return $container->get(LinkHealthActionController::class)->handle($request);
            };
        });

        $eventDispatcher->addListener(CustomMenuGeneratorEvent::class, static function (CustomMenuGeneratorEvent $event) use ($container): void {
            if (!$container->get(PermissionChecker::class)->isGranted(PermissionChecker::PERMISSION_VIEW_HIDDEN)) {
                return;
            }

            $count = $container->get(LinkHealthAdminRepository::class)->brokenCount();
            if ($count > 0) {
                $event->addSignal('LinkHealth', new Signal(
                    (string)$count,
                    $container->get(Translator::class)->trans('Broken links'),
                    '?entity=LinkHealth&status=' . LinkHealthStatus::BROKEN->value,
                ));
            }
        });
    }
}
