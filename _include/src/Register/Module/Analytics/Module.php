<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Psr\Log\LoggerInterface;
use Register\Core\Asset\AssetPack;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Register\Module\VisitorIdentity\JsonMutationGuard;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Controller\Rss\RssHitEvent;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerAwareListenerModuleInterface;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Framework\RoutingModuleInterface;
use Register\Core\Pdo\DbLayer;
use Register\Core\Queue\ScheduledMaintenanceTaskInterface;
use Register\Core\Template\TemplateEvent;
use Register\Core\Template\TemplateAssetEvent;
use Register\Live\LiveUpdatePolledEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class Module implements ContainerModuleInterface, ContainerAwareListenerModuleInterface, RoutingModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(AnalyticsRepository::class, static fn(Container $container): AnalyticsRepository => new AnalyticsRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(BotDetector::class, new BotDetector());
        $container->set(RssReaderParser::class, new RssReaderParser());
        $container->set(AnalyticsRecorder::class, static fn(Container $container): AnalyticsRecorder => new AnalyticsRecorder(
            $container->get(AnalyticsRepository::class),
            $container->get(BotDetector::class),
            $container->get(RssReaderParser::class),
            $container->get(DynamicConfigProvider::class)->getStringProxy(Manifest::SALT_CONFIG_KEY),
            $container->get(VisitorIdentityManager::class),
        ));
        $container->set(AnalyticsEventNormalizer::class, static fn(Container $container): AnalyticsEventNormalizer => new AnalyticsEventNormalizer(
            $container->get(DynamicConfigProvider::class)->getStringProxy(Manifest::SALT_CONFIG_KEY),
        ));
        $container->set(AnalyticsRateLimiter::class, static fn(Container $container): AnalyticsRateLimiter => new AnalyticsRateLimiter(
            $container->get(DynamicConfigProvider::class)->getStringProxy(Manifest::SALT_CONFIG_KEY),
            $container->getStringParameter('cache_dir') . 'analytics-rate-limit',
        ));
        $container->set(AnalyticsSpool::class, static fn(Container $container): AnalyticsSpool => new AnalyticsSpool(
            $container->getStringParameter('cache_dir') . 'analytics-spool',
            minimumSegmentAge: getenv('APP_ENV') === 'test' ? 0 : 10,
        ));
        $container->set(AnalyticsPresenceStore::class, static fn(Container $container): AnalyticsPresenceStore => new AnalyticsPresenceStore(
            $container->getStringParameter('cache_dir') . 'analytics-presence',
            substr(hash('sha256', $container->getStringParameter('root_dir')), 0, 16),
        ));
        $container->set(AnalyticsPresenceRecorder::class, static fn(Container $container): AnalyticsPresenceRecorder => new AnalyticsPresenceRecorder(
            $container->get(AnalyticsPresenceStore::class),
            $container->get(VisitorIdentityManager::class),
            $container->get(BotDetector::class),
            $container->get(DynamicConfigProvider::class)->getStringProxy(Manifest::SALT_CONFIG_KEY),
        ));
        $container->set(AnalyticsIngestor::class, static fn(Container $container): AnalyticsIngestor => new AnalyticsIngestor(
            $container->get(\PDO::class),
            $container->get(DbLayer::class),
            $container->get(AnalyticsRepository::class),
            $container->get(AnalyticsReportCache::class),
            $container->get(AnalyticsBlogProjector::class),
        ));
        $container->set(AnalyticsBlogProjector::class, static fn(Container $container): AnalyticsBlogProjector => new AnalyticsBlogProjector(
            $container->get(DbLayer::class),
        ));
        $container->set(AnalyticsReportCacheFactory::class, static fn(Container $container): AnalyticsReportCacheFactory => new AnalyticsReportCacheFactory(
            $container->get(LoggerInterface::class),
        ));
        $container->set(AnalyticsReportCache::class, static fn(Container $container): AnalyticsReportCache => $container
            ->get(AnalyticsReportCacheFactory::class)
            ->create(
                $container->getStringParameter('cache_dir'),
                $container->getStringParameter('root_dir'),
                $container->getBoolParameter('disable_cache'),
            ));
        $container->set(AnalyticsReportRepository::class, static fn(Container $container): AnalyticsReportRepository => new AnalyticsReportRepository(
            $container->get(DbLayer::class),
            $container->get(AnalyticsReportCache::class),
            $container->get(AnalyticsPresenceStore::class),
        ));
        $container->set(AnalyticsCollectorController::class, static fn(Container $container): AnalyticsCollectorController => new AnalyticsCollectorController(
            $container->get(VisitorIdentityManager::class),
            $container->get(JsonMutationGuard::class),
            $container->get(BotDetector::class),
            $container->get(AnalyticsEventNormalizer::class),
            $container->get(AnalyticsRateLimiter::class),
            $container->get(AnalyticsSpool::class),
            $container->get(AnalyticsIngestor::class),
            $container->get(LoggerInterface::class),
        ));
        $container->set(AnalyticsMaintenanceTask::class, static fn(Container $container): AnalyticsMaintenanceTask => new AnalyticsMaintenanceTask(
            $container->get(AnalyticsRepository::class),
            $container->get(AnalyticsSpool::class),
            $container->get(AnalyticsIngestor::class),
            $container->get(LoggerInterface::class),
        ), [ScheduledMaintenanceTaskInterface::class]);
        $container->set(CounterImageController::class, static fn(Container $container): CounterImageController => new CounterImageController(
            $container->get(AnalyticsRepository::class),
            __DIR__ . '/resources/counter-pattern.png',
        ));
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(TemplateAssetEvent::class, static function (TemplateAssetEvent $event) use ($container): void {
            $basePath = rtrim($container->getStringParameter('base_path'), '/');
            $event->assetPack
                ->addMeta(sprintf(
                    '<meta name="register-analytics" data-collect-url="%s">',
                    register_htmlencode($basePath . '/_analytics/collect'),
                ))
                ->addJs(
                    $basePath . '/_assets/register/analytics/collector.js?v=' . rawurlencode(Manifest::VERSION),
                    [AssetPack::OPTION_DEFER],
                )
            ;
        });

        $eventDispatcher->addListener(TemplateEvent::EVENT_PRE_REPLACE, static function (TemplateEvent $event) use ($container): void {
            $basePath = $container->getStringParameter('base_path');
            $event->htmlTemplate->registerPlaceholder(
                '<!-- register_counter_img -->',
                '<img class="register-analytics-counter" src="' . $basePath . '/_analytics/counter.png" width="88" height="31" alt="Page views" />',
            );
        });

        $eventDispatcher->addListener(RssHitEvent::class, static function (RssHitEvent $event) use ($container): void {
            $container->get(AnalyticsRecorder::class)->recordFeedRead($event->request, $event->rssStrategy);
        });

        $eventDispatcher->addListener(LiveUpdatePolledEvent::class, static function (LiveUpdatePolledEvent $event) use ($container): void {
            $container->get(AnalyticsPresenceRecorder::class)->record($event);
        });
    }

    #[\Override]
    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->add('register_analytics_counter', new Route(
            '/_analytics/counter.png',
            ['_controller' => CounterImageController::class],
            methods: ['GET'],
        ));
        $routes->add('register_analytics_collect', new Route(
            '/_analytics/collect',
            ['_controller' => AnalyticsCollectorController::class],
            methods: ['POST'],
        ));
    }
}
