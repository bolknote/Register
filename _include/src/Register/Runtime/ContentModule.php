<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Runtime;

use Register\Author\AuthorProfileRepository;
use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentDetailsRepository;
use Register\Content\ContentPublicationQueueHandler;
use Register\Content\ContentPublicationScheduler;
use Register\Content\ContentRepository;
use Register\Content\ContentSourceInterface;
use Register\Content\ContentStatisticsRepository;
use Register\Content\ContentSitemapCache;
use Register\Content\ContentType;
use Register\Content\ContentViewRepository;
use Register\Content\ContentViewMaintenanceTask;
use Register\Content\ContentViewRecorderInterface;
use Register\Content\ContentViewSpool;
use Register\Content\ContentViewSpoolProcessor;
use Register\Content\ContentViewSpoolRecorder;
use Register\Content\Admin\ContentRevisionService;
use Register\Content\Controller\ContentSitemapController;
use Register\Content\Controller\RobotsTxtController;
use Register\Content\PageContentSource;
use Register\Content\TagRepository;
use Register\Live\LiveUpdateContext;
use Register\Live\LiveUpdateRepository;
use Register\Url\ContentUrlAliasRepository;
use Register\Url\ContentUrlGenerator;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\PDO as TrackedPDO;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Core\Queue\QueuePublisher;
use Register\Core\Queue\ScheduledMaintenanceTaskInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class ContentModule implements ContainerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(ContentViewRepository::class, static function (Container $container): ContentViewRepository {
            $pdo = $container->get(\PDO::class);
            if (!$pdo instanceof TrackedPDO) {
                throw new \LogicException("Content view caching requires Register's transaction-aware PDO service.");
            }

            return new ContentViewRepository(
                $container->get(DbLayer::class),
                new FilesystemAdapter('content_view_totals', 0, $container->getStringParameter('cache_dir')),
                $pdo,
            );
        }, [StatefulServiceInterface::class]);
        $container->set(ContentViewSpool::class, static fn(Container $container): ContentViewSpool => new ContentViewSpool(
            $container->getStringParameter('cache_dir') . 'content-view-spool',
            minimumSegmentAge: getenv('APP_ENV') === 'test' ? 0 : 10,
        ));
        $container->set(ContentViewSpoolProcessor::class, static function (Container $container): ContentViewSpoolProcessor {
            $pdo = $container->get(\PDO::class);
            if (!$pdo instanceof TrackedPDO) {
                throw new \LogicException("Content-view spool processing requires Register's transaction-aware PDO service.");
            }

            return new ContentViewSpoolProcessor(
                $pdo,
                $container->get(DbLayer::class),
                $container->get(ContentViewRepository::class),
                $container->get(ContentViewSpool::class),
                $container->get(LoggerInterface::class),
            );
        });
        $container->set(ContentViewRecorderInterface::class, static fn(Container $container): ContentViewRecorderInterface => new ContentViewSpoolRecorder(
            $container->get(ContentViewSpool::class),
            $container->get(ContentViewRepository::class),
            $container->get(LoggerInterface::class),
        ));
        $container->set(ContentViewMaintenanceTask::class, static fn(Container $container): ContentViewMaintenanceTask => new ContentViewMaintenanceTask(
            $container->get(ContentViewSpool::class),
            $container->get(ContentViewSpoolProcessor::class),
        ), [ScheduledMaintenanceTaskInterface::class]);
        $container->set(ContentSitemapCache::class, static function (Container $container): ContentSitemapCache {
            $pdo = $container->get(\PDO::class);
            if (!$pdo instanceof TrackedPDO) {
                throw new \LogicException("Content sitemap caching requires Register's transaction-aware PDO service.");
            }

            return new ContentSitemapCache(
                new FilesystemAdapter('content_sitemap', 0, $container->getStringParameter('cache_dir')),
                $container->getBoolParameter('disable_cache'),
                $pdo,
            );
        }, [StatefulServiceInterface::class]);
        $container->set(ContentUrlGenerator::class, static fn(Container $container): ContentUrlGenerator => new ContentUrlGenerator(
            $container->get(DbLayer::class),
            $container->get(UrlBuilder::class),
        ));
        $container->set(ContentUrlAliasRepository::class, static fn(Container $container): ContentUrlAliasRepository => new ContentUrlAliasRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(PageContentSource::class, static fn(Container $container): PageContentSource => new PageContentSource(
            $container->get(DbLayer::class),
            $container->get(ContentUrlGenerator::class),
        ), [ContentSourceInterface::class]);
        $container->set(ContentRepository::class, static fn(Container $container): ContentRepository => new ContentRepository(
            ...$container->getByTag(ContentSourceInterface::class),
        ));
        $container->set(AuthorProfileRepository::class, static fn(Container $container): AuthorProfileRepository => new AuthorProfileRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(LiveUpdateRepository::class, static fn(Container $container): LiveUpdateRepository => new LiveUpdateRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(LiveUpdateContext::class, static fn(Container $container): LiveUpdateContext => new LiveUpdateContext(
            $container->get(LiveUpdateRepository::class),
        ), [StatefulServiceInterface::class]);
        $container->set(ContentChangeDispatcher::class, static fn(Container $container): ContentChangeDispatcher => new ContentChangeDispatcher(
            $container->get(DbLayer::class),
            $container->get(EventDispatcherInterface::class),
            $container->get(LiveUpdateRepository::class),
        ), [StatefulServiceInterface::class]);
        $container->set(ContentPublicationScheduler::class, static fn(Container $container): ContentPublicationScheduler => new ContentPublicationScheduler(
            $container->get(DbLayer::class),
            $container->get(\PDO::class),
            $container->get(ContentChangeDispatcher::class),
        ));
        $container->set(ContentPublicationQueueHandler::class, static fn(Container $container): ContentPublicationQueueHandler => new ContentPublicationQueueHandler(
            $container->get(ContentPublicationScheduler::class),
            $container->get(QueuePublisher::class),
        ), [QueueHandlerInterface::class]);
        $container->set(ContentStatisticsRepository::class, static fn(Container $container): ContentStatisticsRepository => new ContentStatisticsRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(ContentRevisionService::class, new ContentRevisionService());
        $container->set(ContentSitemapController::SERVICE_ID, static fn(Container $container): ContentSitemapController => new ContentSitemapController(
            $container->get(ContentRepository::class),
            $container->get(ContentUrlGenerator::class),
            $container->get('strict_viewer'),
            $container->get(ContentSitemapCache::class),
            ContentType::PAGE,
            ContentType::POST,
        ));
        $container->set(RobotsTxtController::class, static fn(Container $container): RobotsTxtController => new RobotsTxtController(
            $container->get(ContentUrlGenerator::class),
            $container->getStringParameter('base_path'),
        ));
        $container->set(TagRepository::class, static fn(Container $container): TagRepository => new TagRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(ContentDetailsRepository::class, static fn(Container $container): ContentDetailsRepository => new ContentDetailsRepository(
            $container->get(ContentRepository::class),
            $container->get(AuthorProfileRepository::class),
            $container->get(TagRepository::class),
        ));
    }
}
