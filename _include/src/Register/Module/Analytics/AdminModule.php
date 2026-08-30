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
            ) use ($container): Response {
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
                        'dashboard' => $repository->dashboard($fromDay, $toDay),
                        'goals'     => $repository->topGoals($fromDay, $toDay),
                        'authors'   => $repository->topDimensions(
                            AnalyticsBlogProjector::DIMENSION_AUTHOR,
                            $fromDay,
                            $toDay,
                        ),
                        'sections'  => $repository->topDimensions(
                            AnalyticsBlogProjector::DIMENSION_SECTION,
                            $fromDay,
                            $toDay,
                        ),
                        'devices'   => $repository->topDimensions(
                            AnalyticsBlogProjector::DIMENSION_DEVICE,
                            $fromDay,
                            $toDay,
                        ),
                        'browsers'  => $repository->topDimensions(
                            AnalyticsBlogProjector::DIMENSION_BROWSER,
                            $fromDay,
                            $toDay,
                        ),
                        'systems'   => $repository->topDimensions(
                            AnalyticsBlogProjector::DIMENSION_OS,
                            $fromDay,
                            $toDay,
                        ),
                        'vitals'    => $repository->webVitals($fromDay, $toDay),
                        'realtime'  => $repository->realtime(time()),
                        default   => throw new \InvalidArgumentException('Unknown analytics report.'),
                    };
                } catch (\InvalidArgumentException $exception) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => $exception->getMessage(),
                    ], Response::HTTP_BAD_REQUEST);
                }

                if ($request->query->getString('format') === 'csv') {
                    if (!\in_array($report, [
                        'daily',
                        'pages',
                        'sources',
                        'goals',
                        'authors',
                        'sections',
                        'devices',
                        'browsers',
                        'systems',
                        'vitals',
                    ], true) || !array_is_list($data)) {
                        return new JsonResponse([
                            'success' => false,
                            'message' => 'This analytics report cannot be exported.',
                        ], Response::HTTP_BAD_REQUEST);
                    }

                    return self::csvResponse($report, $fromDay, $toDay, $data);
                }

                return new JsonResponse(['success' => true, 'data' => $data]);
            };
        });
    }

    /** @param list<array<string, mixed>> $rows */
    private static function csvResponse(string $report, string $fromDay, string $toDay, array $rows): Response
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Unable to create analytics export.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        fwrite($stream, "\xEF\xBB\xBF");
        if ($rows !== []) {
            $columns = array_keys($rows[0]);
            fputcsv($stream, $columns, ',', '"', '');
            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $value = self::csvCell($row[$column] ?? null);
                    if (preg_match('/^[=+\-@]/D', $value) === 1) {
                        $value = "'" . $value;
                    }

                    $values[] = $value;
                }

                fputcsv($stream, $values, ',', '"', '');
            }
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return new Response($contents === false ? '' : $contents, Response::HTTP_OK, [
            'Cache-Control'         => 'no-store, private',
            'Content-Disposition'  => sprintf(
                'attachment; filename="register-analytics-%s-%s-%s.csv"',
                $report,
                $fromDay,
                $toDay,
            ),
            'Content-Type'          => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function csvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (\is_scalar($value)) {
            return (string)$value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return \is_string($encoded) ? $encoded : '';
    }

}
