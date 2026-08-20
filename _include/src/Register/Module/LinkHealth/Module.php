<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use Register\Content\ContentChangedEvent;
use Register\Content\ContentDeletionGuardInterface;
use Register\Content\ContentRepository;
use Register\Module\LinkHealth\Admin\LocalLinkDeletionGuard;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ContainerAwareListenerModuleInterface;
use S2\Cms\Framework\ContainerModuleInterface;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\HttpClient\HttpClient;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueuePublisher;
use S2\Cms\Queue\ScheduledMaintenanceTaskInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class Module implements ContainerModuleInterface, ContainerAwareListenerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(HtmlLinkExtractor::class, new HtmlLinkExtractor());
        $container->set(HostResolverInterface::class, new NativeHostResolver());
        $container->set(PublicAddressGuard::class, static fn(Container $container): PublicAddressGuard => new PublicAddressGuard(
            $container->get(HostResolverInterface::class),
        ));
        $container->set(LinkHttpClientInterface::class, static fn(Container $container): LinkHttpClient => new LinkHttpClient(
            $container->get(HttpClient::class),
        ));
        $container->set(SafeHttpProbe::class, static fn(Container $container): SafeHttpProbe => new SafeHttpProbe(
            $container->get(LinkHttpClientInterface::class),
            $container->get(PublicAddressGuard::class),
        ));
        $container->set(LinkProbeInterface::class, static fn(Container $container): SafeHttpProbe => $container->get(SafeHttpProbe::class));
        $container->set(WaybackClientInterface::class, static fn(Container $container): WaybackClient => new WaybackClient(
            $container->get(LinkHttpClientInterface::class),
            $container->get(PublicAddressGuard::class),
        ));
        $container->set(WaybackRequestThrottle::class, static fn(Container $container): WaybackRequestThrottle => new WaybackRequestThrottle(
            $container->get(\PDO::class),
            $container->getStringParameter('db_prefix'),
        ));
        $container->set(HostRequestThrottle::class, static fn(Container $container): HostRequestThrottle => new HostRequestThrottle(
            $container->get(\PDO::class),
            $container->getStringParameter('db_prefix'),
        ));
        $container->set(LinkHealthRepository::class, static fn(Container $container): LinkHealthRepository => new LinkHealthRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(LinkHealthTransaction::class, static fn(Container $container): LinkHealthTransaction => new LinkHealthTransaction(
            $container->get(\PDO::class),
        ));
        $container->set(LinkHealthResultRecorder::class, static fn(Container $container): LinkHealthResultRecorder => new LinkHealthResultRecorder(
            $container->get(LinkHealthRepository::class),
            $container->get(LinkHealthTransaction::class),
            $container->get(QueuePublisher::class),
        ));
        $container->set(LinkHealthPolicy::class, new LinkHealthPolicy());
        $container->set(LinkUrlNormalizer::class, static fn(Container $container): LinkUrlNormalizer => new LinkUrlNormalizer(
            $container->getStringParameter('base_url'),
            $container->getStringParameter('base_path'),
        ));
        $container->set(HtmlLinkRewriter::class, static fn(Container $container): HtmlLinkRewriter => new HtmlLinkRewriter(
            $container->get(LinkUrlNormalizer::class),
        ));
        $container->set(ContentPathResolver::class, static fn(Container $container): ContentPathResolver => new ContentPathResolver(
            $container->get(ContentRepository::class),
            $container->get(LinkUrlNormalizer::class),
        ));
        $container->set(LocalLinkDeletionGuard::class, static function (Container $container): LocalLinkDeletionGuard {
            $translator = $container->getIfDefined(\S2\AdminYard\Translator::class);
            if (!$translator instanceof \Symfony\Contracts\Translation\TranslatorInterface) {
                $translator = $container->get('translator');
            }

            return new LocalLinkDeletionGuard(
                $container->get(DbLayer::class),
                $container->get(ContentPathResolver::class),
                $translator,
            );
        }, [ContentDeletionGuardInterface::class]);
        $container->set(LinkInventoryRepository::class, static fn(Container $container): LinkInventoryRepository => new LinkInventoryRepository(
            $container->get(DbLayer::class),
            $container->get(\PDO::class),
            $container->getStringParameter('db_prefix'),
        ));
        $container->set(LinkInventory::class, static fn(Container $container): LinkInventory => new LinkInventory(
            $container->get(ContentRepository::class),
            $container->get(HtmlLinkExtractor::class),
            $container->get(LinkUrlNormalizer::class),
            $container->get(ContentPathResolver::class),
            $container->get(LinkInventoryRepository::class),
            $container->get(QueuePublisher::class),
        ));
        $container->set(LinkInventoryQueueHandler::class, static fn(Container $container): LinkInventoryQueueHandler => new LinkInventoryQueueHandler(
            $container->get(DbLayer::class),
            $container->get(LinkInventory::class),
            $container->get(QueuePublisher::class),
        ), [QueueHandlerInterface::class]);
        $container->set(LinkCheckQueueHandler::class, static fn(Container $container): LinkCheckQueueHandler => new LinkCheckQueueHandler(
            $container->get(LinkHealthRepository::class),
            $container->get(LinkHealthPolicy::class),
            $container->get(LinkProbeInterface::class),
            $container->get(HostRequestThrottle::class),
            $container->get(LinkHealthResultRecorder::class),
            $container->get(QueuePublisher::class),
        ), [QueueHandlerInterface::class]);
        $container->set(LinkArchiveQueueHandler::class, static fn(Container $container): LinkArchiveQueueHandler => new LinkArchiveQueueHandler(
            $container->get(LinkHealthRepository::class),
            $container->get(WaybackClientInterface::class),
            $container->get(WaybackRequestThrottle::class),
            $container->get(LinkHealthResultRecorder::class),
            $container->get(QueuePublisher::class),
            $container->get(DynamicConfigProvider::class)->getBoolProxy(Manifest::AUTO_REPAIR_CONFIG_KEY),
        ), [QueueHandlerInterface::class]);
        $container->set(LinkRepairService::class, static fn(Container $container): LinkRepairService => new LinkRepairService(
            $container->get(DbLayer::class),
            $container->get(\PDO::class),
            $container->get(ContentRepository::class),
            $container->get(\Register\Content\ContentChangeDispatcher::class),
            $container->get(HtmlLinkRewriter::class),
            $container->get(LinkUrlNormalizer::class),
        ));
        $container->set(LinkRepairQueueHandler::class, static fn(Container $container): LinkRepairQueueHandler => new LinkRepairQueueHandler(
            $container->get(LinkHealthRepository::class),
            $container->get(LinkRepairService::class),
        ), [QueueHandlerInterface::class]);
        $container->set(LinkMaintenanceTask::class, static fn(Container $container): LinkMaintenanceTask => new LinkMaintenanceTask(
            $container->get(DbLayer::class),
            $container->get(LinkInventoryRepository::class),
            $container->get(LinkHealthRepository::class),
            $container->get(HostRequestThrottle::class),
            $container->get(QueuePublisher::class),
            $container->get(DynamicConfigProvider::class)->getBoolProxy(Manifest::AUTO_REPAIR_CONFIG_KEY),
        ), [ScheduledMaintenanceTaskInterface::class]);
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(ContentChangedEvent::class, static function (ContentChangedEvent $event) use ($container): void {
            $container->get(LinkInventory::class)->synchronize($event->contentId);
        });
    }
}
