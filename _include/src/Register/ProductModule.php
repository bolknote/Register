<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register;

use Register\Comment\CommentRepository;
use Register\Comment\ContentCommentNotifier;
use Register\Comment\ContentCommentStrategy;
use Register\Comment\ContentCommentTargetResolver;
use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentPublicationScheduler;
use Register\Content\ContentRepository;
use Register\Content\Admin\ContentRevisionService;
use Register\Content\ContentSourceInterface;
use Register\Content\ContentStatisticsRepository;
use Register\Content\ContentType;
use Register\Content\Controller\ContentSitemapController;
use Register\Content\PageContentSource;
use Register\Content\TagRepository;
use Register\Module\BaseModuleInstaller;
use Register\Module\BaseModuleRegistry;
use Register\Schema\SchemaManager;
use Register\Url\ContentSlugService;
use Register\Url\ContentUrlGenerator;
use Register\Url\IcuTransliterator;
use Register\Url\PortableAsciiTransliterator;
use Register\Url\ReservedRouteRegistry;
use Register\Url\SlugGenerator;
use Register\Url\UniqueSlugGenerator;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ContainerModuleInterface;
use S2\Cms\Framework\StatefulServiceInterface;
use S2\Cms\Controller\Comment\CommentStrategyInterface;
use S2\Cms\Mail\CommentMailer;
use S2\Cms\Model\ArticleProvider;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Pdo\DbLayer;

/**
 * Registers services owned by the Register product rather than the reusable S2 foundation.
 */
readonly class ProductModule implements ContainerModuleInterface
{
    public function __construct(private BaseModuleRegistry $baseModuleRegistry)
    {
    }

    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(BaseModuleRegistry::class, $this->baseModuleRegistry);
        $container->set(ContentUrlGenerator::class, static fn(Container $container): ContentUrlGenerator => new ContentUrlGenerator(
            $container->get(DbLayer::class),
            $container->get(UrlBuilder::class),
        ));
        $container->set(PageContentSource::class, static fn(Container $container): PageContentSource => new PageContentSource(
            $container->get(DbLayer::class),
            $container->get(ContentUrlGenerator::class),
        ), [ContentSourceInterface::class]);
        $container->set(ContentRepository::class, static fn(Container $container): ContentRepository => new ContentRepository(
            ...$container->getByTag(ContentSourceInterface::class),
        ));
        $container->set(ContentChangeDispatcher::class, static fn(Container $container): ContentChangeDispatcher => new ContentChangeDispatcher(
            $container->get(DbLayer::class),
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
        ), [StatefulServiceInterface::class]);
        $container->set(ContentPublicationScheduler::class, static fn(Container $container): ContentPublicationScheduler => new ContentPublicationScheduler(
            $container->get(DbLayer::class),
            $container->get(\PDO::class),
            $container->get(ContentChangeDispatcher::class),
        ));
        $container->set(ContentStatisticsRepository::class, static fn(Container $container): ContentStatisticsRepository => new ContentStatisticsRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(ContentRevisionService::class, new ContentRevisionService());
        $container->set(ContentSitemapController::SERVICE_ID, static fn(Container $container): ContentSitemapController => new ContentSitemapController(
            $container->get(ContentRepository::class),
            $container->get(ContentUrlGenerator::class),
            $container->get('strict_viewer'),
            ContentType::PAGE,
            ContentType::POST,
        ));
        $container->set(TagRepository::class, static fn(Container $container): TagRepository => new TagRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(CommentRepository::class, static fn(Container $container): CommentRepository => new CommentRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(ContentCommentTargetResolver::class, static fn(Container $container): ContentCommentTargetResolver => new ContentCommentTargetResolver(
            $container->get(DbLayer::class),
            $container->get(ArticleProvider::class),
        ));
        $container->set(ContentCommentNotifier::class, static fn(Container $container): ContentCommentNotifier => new ContentCommentNotifier(
            $container->get(CommentRepository::class),
            $container->get(\Register\Comment\CommentSubscriptionService::class),
            $container->get(ContentRepository::class),
            $container->get(ContentUrlGenerator::class),
            $container->get(CommentMailer::class),
        ));
        $container->set(ContentCommentStrategy::PAGE_SERVICE_ID, static fn(Container $container): ContentCommentStrategy => new ContentCommentStrategy(
            ContentType::PAGE,
            $container->get(CommentRepository::class),
            $container->get(ContentCommentTargetResolver::class),
            $container->get(ContentCommentNotifier::class),
        ), [CommentStrategyInterface::class]);
        $container->set(ContentCommentStrategy::POST_SERVICE_ID, static fn(Container $container): ContentCommentStrategy => new ContentCommentStrategy(
            ContentType::POST,
            $container->get(CommentRepository::class),
            $container->get(ContentCommentTargetResolver::class),
            $container->get(ContentCommentNotifier::class),
        ), [CommentStrategyInterface::class]);
        $container->set(
            BaseModuleInstaller::class,
            fn(Container $container): BaseModuleInstaller => new BaseModuleInstaller(
                $container->get(BaseModuleRegistry::class),
            ),
        );
        $container->set(SchemaManager::class, fn(Container $container): SchemaManager => new SchemaManager(
            $container->get(DbLayer::class),
            $container,
            $container->get(BaseModuleInstaller::class),
        ));
        $container->set(SlugGenerator::class, new SlugGenerator(
            new PortableAsciiTransliterator(),
            IcuTransliterator::create(),
        ));
        $container->set(UniqueSlugGenerator::class, static fn(Container $container): UniqueSlugGenerator => new UniqueSlugGenerator(
            $container->get(SlugGenerator::class),
        ));
        $container->set(ReservedRouteRegistry::class, static function (Container $container): ReservedRouteRegistry {
            $provider = $container->get(DynamicConfigProvider::class);

            return new ReservedRouteRegistry(
                $provider->getStringProxy('S2_TAGS_URL'),
                $provider->getStringProxy('S2_FAVORITE_URL'),
            );
        });
        $container->set(ContentSlugService::class, static fn(Container $container): ContentSlugService => new ContentSlugService(
            $container->get(DbLayer::class),
            $container->get(UniqueSlugGenerator::class),
            $container->get(ReservedRouteRegistry::class),
        ));
    }

}
