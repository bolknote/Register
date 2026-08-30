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
use Register\AdminYard\TemplateRenderer;
use Register\Admin\Dashboard\DashboardBlockProviderInterface;
use Register\Admin\Event\AdminAjaxControllerMapEvent;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerAwareListenerModuleInterface;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Model\PermissionChecker;
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
                $container->get(AnalyticsReportRepository::class),
            ),
            [DashboardBlockProviderInterface::class],
        );
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(AdminAjaxControllerMapEvent::class, static function (AdminAjaxControllerMapEvent $event) use ($container): void {
            $event->allowGet('register_analytics_series');
            $event->allowGet('register_analytics_report');
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
            $event->controllerMap['register_analytics_report'] = static function (
                PermissionChecker $permissionChecker,
                Request $request,
            ) use ($container): JsonResponse {
                if (!$permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW_HIDDEN)) {
                    return new JsonResponse(['success' => false, 'message' => 'Permission denied.'], Response::HTTP_FORBIDDEN);
                }

                if (!$request->isMethod(Request::METHOD_GET)) {
                    return new JsonResponse(['success' => false, 'message' => 'Only GET requests are allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
                }

                $report = $request->query->getString('report');
                $toDay  = $request->query->getString('to', date('Y-m-d'));
                $fromDay = $request->query->getString('from', date('Y-m-d', time() - 29 * 86400));
                $repository = $container->get(AnalyticsReportRepository::class);
                try {
                    $data = match ($report) {
                        'daily'   => $repository->dailyOverview(),
                        'pages'   => $repository->topPages($fromDay, $toDay),
                        'sources' => $repository->topSources($fromDay, $toDay),
                        default   => throw new \InvalidArgumentException('Unknown analytics report.'),
                    };
                } catch (\InvalidArgumentException $exception) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => $exception->getMessage(),
                    ], Response::HTTP_BAD_REQUEST);
                }

                return new JsonResponse(['success' => true, 'data' => $data]);
            };
        });
    }

}
