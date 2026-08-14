<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Register\Module\VisitorIdentity\VisitorResolvedEvent;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Controller\Rss\RssHitEvent;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ContainerAwareListenerModuleInterface;
use S2\Cms\Framework\ContainerModuleInterface;
use S2\Cms\Framework\RoutingModuleInterface;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Template\TemplateEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
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
        $container->set(CounterImageController::class, static fn(Container $container): CounterImageController => new CounterImageController(
            $container->get(AnalyticsRepository::class),
            __DIR__ . '/resources/counter-pattern.png',
        ));
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(TemplateEvent::EVENT_PRE_REPLACE, static function (TemplateEvent $event) use ($container): void {
            $basePath = $container->getStringParameter('base_path');
            $event->htmlTemplate->registerPlaceholder(
                '<!-- s2_counter_img -->',
                '<img class="register-analytics-counter" src="' . $basePath . '/_analytics/counter.png" width="88" height="31" alt="Page views" />',
            );

            if ($event->htmlTemplate->isNotFound()) {
                return;
            }

            $request = $container->get(RequestStack::class)->getCurrentRequest();
            if ($request !== null) {
                $needsVisitorResolution = $container->get(AnalyticsRecorder::class)->recordPageView($request);
                if ($needsVisitorResolution) {
                    $event->htmlTemplate->addMetaTag('<meta name="register-analytics-page" content="1">');
                }
            }
        });

        $eventDispatcher->addListener(VisitorResolvedEvent::class, static function (VisitorResolvedEvent $event) use ($container): void {
            if ($event->trackPageView) {
                $container->get(AnalyticsRecorder::class)->recordResolvedPageVisitor($event->request, $event->visitorId);
            }
        });

        $eventDispatcher->addListener(RssHitEvent::class, static function (RssHitEvent $event) use ($container): void {
            $container->get(AnalyticsRecorder::class)->recordFeedRead($event->request, $event->rssStrategy);
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
    }
}
