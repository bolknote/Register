<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Module\Analytics\Admin\DashboardAnalyticsProvider;
use S2\AdminYard\TemplateRenderer;
use S2\Cms\Admin\Dashboard\DashboardBlockProviderInterface;
use S2\Cms\Admin\Event\AdminAjaxControllerMapEvent;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ContainerAwareListenerModuleInterface;
use S2\Cms\Framework\ContainerModuleInterface;
use S2\Cms\Model\PermissionChecker;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminModule implements ContainerModuleInterface, ContainerAwareListenerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(
            DashboardAnalyticsProvider::class,
            static fn(Container $container): DashboardAnalyticsProvider => new DashboardAnalyticsProvider(
                $container->get(TemplateRenderer::class),
                $container->get(\Register\Module\VisitorIdentity\VisitorIdentityRepository::class),
            ),
            [DashboardBlockProviderInterface::class],
        );
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(AdminAjaxControllerMapEvent::class, static function (AdminAjaxControllerMapEvent $event) use ($container): void {
            $event->controllerMap['register_analytics_series'] = static function (
                PermissionChecker $permissionChecker,
                Request $request,
            ) use ($container): JsonResponse {
                if (!$permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW_HIDDEN)) {
                    return new JsonResponse(['success' => false, 'message' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
                }

                if (!$request->isMethod(Request::METHOD_GET)) {
                    return new JsonResponse(['success' => false, 'message' => 'Only GET requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                $channel = $request->query->getString('channel');
                if (!\in_array($channel, [
                    AnalyticsRepository::PAGE_CHANNEL,
                    AnalyticsRecorder::BLOG_FEED_CHANNEL,
                ], true)) {
                    return new JsonResponse(['success' => false, 'message' => 'Unknown analytics channel.'], Response::HTTP_BAD_REQUEST);
                }

                return new JsonResponse([
                    'success' => true,
                    'series'  => $container->get(AnalyticsRepository::class)->dailySeries($channel),
                ]);
            };
        });
    }

}
