<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   https://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Register\Module\Blog\Controller\AllPostsController;
use Register\Module\Blog\Controller\DayPageController;
use Register\Module\Blog\Controller\FavoritePageController;
use Register\Module\Blog\Controller\FlatCommentController;
use Register\Module\Blog\Controller\FlatContentController;
use Register\Module\Blog\Controller\LegacyRssRedirectController;
use Register\Module\Blog\Controller\MainPageController;
use Register\Module\Blog\Controller\MonthPageController;
use Register\Module\Blog\Controller\RandomPostController;
use Register\Module\Blog\Controller\RankedPostsController;
use Register\Module\Blog\Controller\TagPageController;
use Register\Module\Blog\Controller\TagsPageController;
use Register\Module\Blog\Controller\YearPageController;
use Register\Module\Blog\Inplace\PostInplaceController;
use Register\Module\Blog\Inplace\PostTagSuggestionsController;
use Register\Url\ContentUrlAliasController;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Controller\RssController;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerAwareRoutingModuleInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final readonly class RoutingModule implements ContainerAwareRoutingModuleInterface
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    #[\Override]
    public function registerRoutes(RouteCollection $routes, Container $container): void
    {
        $configProvider = $container->get(DynamicConfigProvider::class);
        $favoriteUrl    = $configProvider->getStringProxy('REGISTER_FAVORITE_URL')->get();
        $tagsUrl        = $configProvider->getStringProxy('REGISTER_TAGS_URL')->get();
        $priority       = 1;
        $flatPriority   = -1;

        $routes->add('blog_post_inplace', new Route(
            '/_inplace/post/{id<[1-9][0-9]*>}',
            ['_controller' => PostInplaceController::class],
            methods: ['POST'],
        ), $priority + 1);
        $routes->add('blog_post_inplace_create', new Route(
            '/_inplace/post/new',
            ['_controller' => PostInplaceController::class, 'create' => true],
            methods: ['POST'],
        ), $priority + 1);
        $routes->add('blog_post_tag_suggestions', new Route(
            '/_inplace/tags',
            ['_controller' => PostTagSuggestionsController::class],
            methods: ['GET'],
        ), $priority + 1);
        $routes->add('blog_main', new Route(
            '/',
            ['_controller' => MainPageController::class, 'page' => 0, 'slash' => '/'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_main_pages', new Route(
            '/skip/{page<\d+>}',
            ['_controller' => MainPageController::class, 'slash' => '/'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_rss', new Route(
            '/rss',
            ['_controller' => RssController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_rss_trailing_slash', new Route(
            '/rss/',
            ['_controller' => RssController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_json_feed', new Route(
            '/feed.json',
            ['_controller' => 'register_blog.json_feed_controller'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_rss_legacy', new Route(
            '/rss.xml',
            ['_controller' => LegacyRssRedirectController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_all', new Route(
            '/all{slash</?>}',
            ['_controller' => AllPostsController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_favorite', new Route(
            '/' . $favoriteUrl . '{slash</?>}',
            ['_controller' => FavoritePageController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_popular', new Route(
            '/popular{slash</?>}',
            ['_controller' => RankedPostsController::class, 'ranking' => 'popular'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_hot', new Route(
            '/hot{slash</?>}',
            ['_controller' => RankedPostsController::class, 'ranking' => 'hot'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_random', new Route(
            '/random{slash</?>}',
            ['_controller' => RandomPostController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_tags', new Route(
            '/' . $tagsUrl . '{slash</?>}',
            ['_controller' => TagsPageController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_tag_rss', new Route(
            '/' . $tagsUrl . '/{tag}/rss',
            ['_controller' => 'register_blog.tag_rss_controller'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority + 1);
        $routes->add('blog_tag_json_feed', new Route(
            '/' . $tagsUrl . '/{tag}/feed.json',
            ['_controller' => 'register_blog.tag_json_feed_controller'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority + 1);
        $routes->add('blog_tag', new Route(
            '/' . $tagsUrl . '/{tag}{slash</?>}',
            ['_controller' => TagPageController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_year', new Route(
            '/archive/{year<\d{4}>}/',
            ['_controller' => YearPageController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_month', new Route(
            '/archive/{year<\d{4}>}/{month<\d{2}>}/',
            ['_controller' => MonthPageController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_day', new Route(
            '/archive/{year<\d{4}>}/{month<\d{2}>}/{day<\d{2}>}/',
            ['_controller' => DayPageController::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $priority);
        $routes->add('blog_post', new Route(
            '/{url}',
            ['_controller' => FlatContentController::class],
            requirements: ['url' => '.+'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $flatPriority);
        $routes->add('blog_comment', new Route(
            '/{url}',
            ['_controller' => FlatCommentController::class],
            requirements: ['url' => '.+'],
            options: ['utf8' => true],
            methods: ['POST'],
        ), $flatPriority);
        $routes->add('blog_url_alias', new Route(
            '/{alias}',
            ['_controller' => ContentUrlAliasController::class],
            requirements: ['alias' => '.+'],
            options: ['utf8' => true],
            methods: ['GET'],
        ), $flatPriority - 1);
    }
}
