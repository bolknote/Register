<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Runtime;

use Register\Comment\CommentRepository;
use Register\Comment\ContentCommentRenderer;
use Register\Content\TagRepository;
use Register\Controller\NotFoundController;
use Register\Controller\PageCommon;
use Register\Controller\PageFavorite;
use Register\Controller\PageTag;
use Register\Controller\PageTags;
use Register\Live\LiveUpdateContext;
use Register\Model\ArticleProvider;
use Register\Model\CommentProvider;
use Register\Model\FavoriteArticleProvider;
use Register\Model\TagsProvider;
use Register\Url\ContentUrlGenerator;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayer;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\Viewer;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** Registers the permanent-page models and their public controllers. */
final readonly class PageModule implements ContainerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(ArticleProvider::class, static function (Container $container): ArticleProvider {
            $provider = $container->get(DynamicConfigProvider::class);

            return new ArticleProvider(
                $container->get(DbLayer::class),
                $container->get(CommentRepository::class),
                $container->get(ContentUrlGenerator::class),
                $container->get(UrlBuilder::class),
                $container->get(Viewer::class),
                $provider->getStringProxy('REGISTER_FAVORITE_URL'),
                $provider->getBoolProxy('REGISTER_USE_HIERARCHY'),
            );
        });
        $container->set(TagsProvider::class, static function (Container $container): TagsProvider {
            $provider = $container->get(DynamicConfigProvider::class);

            return new TagsProvider(
                $container->get(TagRepository::class),
                $container->get(UrlBuilder::class),
                $provider->getStringProxy('REGISTER_TAGS_URL'),
            );
        }, [StatefulServiceInterface::class]);
        $container->set(CommentProvider::class, static function (Container $container): CommentProvider {
            $provider = $container->get(DynamicConfigProvider::class);

            return new CommentProvider(
                $container->get(DbLayer::class),
                $container->get(CommentRepository::class),
                $container->get(ArticleProvider::class),
                $container->get(UrlBuilder::class),
                $container->get(Viewer::class),
                $provider->getBoolProxy('REGISTER_SHOW_COMMENTS'),
            );
        });
        $container->set(FavoriteArticleProvider::class, static function (Container $container): FavoriteArticleProvider {
            $provider = $container->get(DynamicConfigProvider::class);

            return new FavoriteArticleProvider(
                $container->get(DbLayer::class),
                $container->get(ArticleProvider::class),
                $container->get(UrlBuilder::class),
                $container->get(Viewer::class),
                $provider->getStringProxy('REGISTER_FAVORITE_URL'),
                $provider->getBoolProxy('REGISTER_USE_HIERARCHY'),
            );
        });

        $container->set(NotFoundController::class, static fn(Container $container): NotFoundController => new NotFoundController(
            $container->get(ArticleProvider::class),
            $container->get(UrlBuilder::class),
            $container->get('translator'),
            $container->get(HtmlTemplateProvider::class),
        ));
        $container->set(PageFavorite::class, static fn(Container $container): PageFavorite => new PageFavorite(
            $container->get(FavoriteArticleProvider::class),
            $container->get(ArticleProvider::class),
            $container->get(UrlBuilder::class),
            $container->get('translator'),
            $container->get(HtmlTemplateProvider::class),
        ));
        $container->set(PageTags::class, static fn(Container $container): PageTags => new PageTags(
            $container->get(TagsProvider::class),
            $container->get(ArticleProvider::class),
            $container->get(UrlBuilder::class),
            $container->get('translator'),
            $container->get(HtmlTemplateProvider::class),
            $container->get(Viewer::class),
        ));
        $container->set(PageTag::class, static function (Container $container): PageTag {
            $provider = $container->get(DynamicConfigProvider::class);

            return new PageTag(
                $container->get(DbLayer::class),
                $container->get(TagRepository::class),
                $container->get(ArticleProvider::class),
                $container->get(UrlBuilder::class),
                $container->get('translator'),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $provider->getStringProxy('REGISTER_TAGS_URL'),
                $provider->getStringProxy('REGISTER_FAVORITE_URL'),
                $provider->getBoolProxy('REGISTER_USE_HIERARCHY'),
            );
        });
        $container->set(PageCommon::class, static function (Container $container): PageCommon {
            $provider = $container->get(DynamicConfigProvider::class);

            return new PageCommon(
                $container->get(DbLayer::class),
                $container->get(TagRepository::class),
                $container->get(ArticleProvider::class),
                $container->get(EventDispatcherInterface::class),
                $container->get(UrlBuilder::class),
                $container->get('translator'),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $container->get(ContentCommentRenderer::class),
                $container->get(LiveUpdateContext::class),
                $provider->getBoolProxy('REGISTER_USE_HIERARCHY'),
                $provider->getBoolProxy('REGISTER_SHOW_COMMENTS'),
                $provider->getStringProxy('REGISTER_TAGS_URL'),
                $provider->getStringProxy('REGISTER_FAVORITE_URL'),
                $provider->getIntProxy('REGISTER_MAX_ITEMS'),
                $container->getBoolParameter('debug'),
            );
        });
    }
}
