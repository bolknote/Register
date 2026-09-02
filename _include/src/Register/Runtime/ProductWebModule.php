<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Runtime;

use Register\Auth\PublicAuthController;
use Register\Auth\CommentNotificationRepository;
use Register\Comment\CommentChangedEvent;
use Register\Comment\CommentMutationSource;
use Register\Content\Controller\ContentSitemapController;
use Register\Content\ContentChangedEvent;
use Register\Content\ContentSitemapCache;
use Register\Content\Controller\RobotsTxtController;
use Register\Controller\CommentController;
use Register\Controller\CommentModerationController;
use Register\Controller\CommentSentController;
use Register\Controller\CommentUnsubscribeController;
use Register\Controller\NotFoundController;
use Register\Controller\PageCommon;
use Register\Controller\PageFavorite;
use Register\Controller\PageTag;
use Register\Controller\PageTags;
use Register\Import\Telegram\Admin\TelegramImportAdminController;
use Register\Live\LiveUpdateContext;
use Register\Live\LiveUpdateController;
use Register\Model\ArticleProvider;
use Register\Model\CommentProvider;
use Register\Model\TagsProvider;
use Register\Offline\OfflineCachePolicy;
use Register\Admin\Event\AdminAjaxControllerMapEvent;
use Register\Core\Asset\AssetPack;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerAwareListenerModuleInterface;
use Register\Core\Framework\ContainerAwareRoutingModuleInterface;
use Register\Core\Framework\Event\NotFoundEvent;
use Register\Core\Http\RedirectDetector;
use Register\Core\Model\AuthProvider;
use Register\Core\Model\PermissionChecker;
use Register\Core\Model\UrlBuilder;
use Register\Core\Template\TemplateAssetEvent;
use Register\Core\Template\TemplateEvent;
use Register\Core\Template\Viewer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ProductWebModule implements ContainerAwareListenerModuleInterface, ContainerAwareRoutingModuleInterface
{
    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(ContentChangedEvent::class, static function (ContentChangedEvent $_event) use ($container): void {
            $container->get(ContentSitemapCache::class)->invalidate();
        });
        $eventDispatcher->addListener(CommentChangedEvent::class, static function (CommentChangedEvent $event) use ($container): void {
            $container->get(CommentNotificationRepository::class)->invalidateAll(
                $event->source === CommentMutationSource::IMPORTED,
            );
        });

        $eventDispatcher->addListener(NotFoundEvent::class, static function (NotFoundEvent $event) use ($container): void {
            $redirectResponse = $container->get(RedirectDetector::class)->getRedirectResponse($event->request);
            if ($redirectResponse !== null) {
                $event->response = $redirectResponse;
                return;
            }

            if (!$event->response instanceof \Symfony\Component\HttpFoundation\Response) {
                $event->response = $container->get(NotFoundController::class)->handle($event->request);
            }
        });

        $eventDispatcher->addListener(TemplateEvent::EVENT_CREATED, static function (TemplateEvent $event) use ($container): void {
            $template = $event->htmlTemplate;

            if ($template->hasPlaceholder('<!-- register_last_articles -->')) {
                $template->registerPlaceholder(
                    '<!-- register_last_articles -->',
                    $container->get(ArticleProvider::class)->lastArticlesPlaceholder(5),
                );
            }

            if ($template->hasPlaceholder('<!-- register_tags_list -->')) {
                $tagsList = $container->get(TagsProvider::class)->tagsList();
                $template->registerPlaceholder(
                    '<!-- register_tags_list -->',
                    $tagsList === []
                        ? ''
                        : $container->get(Viewer::class)->render('tags_list', ['tags' => $tagsList]),
                );
            }

            if ($template->hasPlaceholder('<!-- register_last_comments -->')) {
                $lastComments = $container->get(CommentProvider::class)->lastArticleComments();
                /** @var TranslatorInterface $translator */
                $translator = $container->get('translator');
                $template->registerPlaceholder(
                    '<!-- register_last_comments -->',
                    $lastComments === []
                        ? ''
                        : $container->get(Viewer::class)->render('menu_comments', [
                            'title' => $translator->trans('Last comments'),
                            'menu'  => $lastComments,
                        ]),
                );
            }

            if ($template->hasPlaceholder('<!-- register_last_discussions -->')) {
                $lastDiscussions = $container->get(CommentProvider::class)->lastDiscussions();
                /** @var TranslatorInterface $translator */
                $translator = $container->get('translator');
                $template->registerPlaceholder(
                    '<!-- register_last_discussions -->',
                    $lastDiscussions === []
                        ? ''
                        : $container->get(Viewer::class)->render('menu_block', [
                            'title' => $translator->trans('Last discussions'),
                            'menu'  => $lastDiscussions,
                        ]),
                );
            }
        });

        $eventDispatcher->addListener(AdminAjaxControllerMapEvent::class, static function (AdminAjaxControllerMapEvent $event) use ($container): void {
            $event->controllerMap['register_telegram_import'] = static function (
                PermissionChecker $permissionChecker,
                Request           $request,
            ) use ($container): \Symfony\Component\HttpFoundation\JsonResponse {
                unset($permissionChecker);

                return $container->get(TelegramImportAdminController::class)->handle($request);
            };
        });

        $eventDispatcher->addListener(TemplateAssetEvent::class, static function (TemplateAssetEvent $event) use ($container): void {
            $basePath = rtrim($container->getStringParameter('base_path'), '/');
            $publicRoot = $container->getStringParameter('public_root_dir');
            $versionedAsset = static function (string $path) use ($basePath, $publicRoot): string {
                $modifiedAt = \filemtime($publicRoot . ltrim($path, '/'));
                if ($modifiedAt === false) {
                    throw new \LogicException(\sprintf('Unable to read the modification time of "%s".', $path));
                }

                return $basePath . $path . '?v=' . $modifiedAt;
            };
            $event->assetPack
                ->addCss($versionedAsset('/_assets/register/comment-editor.css'))
                ->addCss($basePath . '/_assets/register/offline.css')
                ->addCss($versionedAsset('/_assets/register/partial-navigation.css'))
                ->addJs($versionedAsset('/_assets/register/comment-editor.js'), [AssetPack::OPTION_DEFER])
                ->addJs($versionedAsset('/_assets/register/offline.js'), [AssetPack::OPTION_DEFER])
                ->addJs($versionedAsset('/_assets/register/live-updates.js'), [AssetPack::OPTION_DEFER])
                ->addJs($versionedAsset('/_assets/register/partial-navigation.js'), [AssetPack::OPTION_DEFER])
                ->addCss($versionedAsset('/_assets/register/public-auth.css'))
                ->addJs($versionedAsset('/_assets/register/public-auth.js'), [AssetPack::OPTION_DEFER])
            ;
        });

        $eventDispatcher->addListener(TemplateEvent::EVENT_PRE_REPLACE, static function (TemplateEvent $event) use ($container): void {
            $basePath = rtrim($container->getStringParameter('base_path'), '/');
            $publicRoot = $container->getStringParameter('public_root_dir');
            $workerPath = '/service-worker.js';
            $workerModifiedAt = \filemtime($publicRoot . ltrim($workerPath, '/'));
            if ($workerModifiedAt === false) {
                throw new \LogicException('Unable to read the modification time of the service worker.');
            }

            $workerUrl = $basePath . $workerPath . '?v=' . $workerModifiedAt;
            $request = $container->get(RequestStack::class)->getCurrentRequest();
            $allowsInitialSeed = $request instanceof Request && OfflineCachePolicy::allowsInitialSeed(
                $request,
                $container->get(AuthProvider::class)->hasAuthenticatedPublicSession($request),
            );
            /** @var TranslatorInterface $translator */
            $translator = $container->get('translator');
            $event->htmlTemplate->addMetaTag(sprintf(
                '<meta name="register-offline" data-worker="%s" data-scope="%s/"'
                    . ' data-seed="%s" data-warning="%s" data-syncing="%s" data-reload="%s">',
                register_htmlencode($workerUrl),
                register_htmlencode($basePath),
                $allowsInitialSeed ? '1' : '0',
                register_htmlencode($translator->trans('Offline cache warning')),
                register_htmlencode($translator->trans('Offline cache syncing')),
                register_htmlencode($translator->trans('Reload current page')),
            ));

            $context = $container->get(LiveUpdateContext::class);
            $cursor  = $context->cursor();
            $regions = $context->regions();
            if ($cursor === null || $regions === []) {
                return;
            }

            $event->htmlTemplate->addMetaTag(sprintf(
                '<meta name="register-live-updates" data-endpoint="%s" data-cursor="%d" data-regions="%s">',
                register_htmlencode($container->get(UrlBuilder::class)->link('/_live')),
                $cursor,
                register_htmlencode(json_encode($regions, JSON_THROW_ON_ERROR)),
            ));
        }, -100);
    }

    #[\Override]
    public function registerRoutes(RouteCollection $routes, Container $container): void
    {
        $configProvider = $container->get(DynamicConfigProvider::class);
        $favoriteUrl    = $configProvider->getStringProxy('REGISTER_FAVORITE_URL')->get();
        $tagsUrl        = $configProvider->getStringProxy('REGISTER_TAGS_URL')->get();

        $routes->add('sitemap', new Route(
            '/sitemap.xml',
            ['_controller' => ContentSitemapController::SERVICE_ID],
            methods: ['GET'],
        ));
        $routes->add('sitemap_part', new Route(
            '/sitemap-{part<[1-9]\\d*>}.xml',
            ['_controller' => ContentSitemapController::SERVICE_ID],
            methods: ['GET'],
        ));
        $routes->add('robots', new Route(
            '/robots.txt',
            ['_controller' => RobotsTxtController::class],
            methods: ['GET'],
        ));
        $routes->add('favorite', new Route(
            '/' . $favoriteUrl . '{slash</?>}',
            ['_controller' => PageFavorite::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ));
        $routes->add('tags', new Route(
            '/' . $tagsUrl . '{slash</?>}',
            ['_controller' => PageTags::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ));
        $routes->add('tag', new Route(
            '/' . $tagsUrl . '/{name}{slash</?>}',
            ['_controller' => PageTag::class],
            options: ['utf8' => true],
            methods: ['GET'],
        ));
        $routes->add('common', new Route(
            '/{path<.*>}',
            ['_controller' => PageCommon::class],
            methods: ['GET'],
        ), -1024);
        $routes->add('comment_sent', new Route(
            '/comment_sent',
            ['_controller' => CommentSentController::class],
            methods: ['GET'],
        ));
        $routes->add('comment_unsubscribe', new Route(
            '/comment_unsubscribe',
            ['_controller' => CommentUnsubscribeController::class],
            methods: ['GET', 'POST'],
        ));
        $routes->add('comment_moderate', new Route(
            '/comment-moderate',
            ['_controller' => CommentModerationController::class],
            methods: ['POST'],
        ));
        $routes->add('comment', new Route(
            '/{path<.*>}',
            ['_controller' => CommentController::class],
            methods: ['POST'],
        ), -1024);

        // Public service endpoints must win over the blog's catch-all content/comment routes.
        $authPriority = 2;

        $routes->add('register_public_auth', new Route(
            '/auth',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'page'],
            methods: ['GET'],
        ), $authPriority);
        $routes->add('register_public_auth_password', new Route(
            '/auth/password',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'password'],
            methods: ['POST'],
        ), $authPriority);
        $routes->add('register_public_auth_logout', new Route(
            '/auth/logout',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'logout'],
            methods: ['POST'],
        ), $authPriority);
        $routes->add('register_public_auth_email', new Route(
            '/auth/email',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'email'],
            methods: ['POST'],
        ), $authPriority);
        $routes->add('register_public_auth_email_callback', new Route(
            '/auth/email/callback',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'email_callback'],
            methods: ['GET'],
        ), $authPriority);
        $routes->add('register_public_auth_check_email', new Route(
            '/auth/check-email',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'check_email'],
            methods: ['GET'],
        ), $authPriority);
        $routes->add('register_public_auth_oauth_start', new Route(
            '/auth/oauth/{provider<vk|mail_ru|ok_ru|yandex>}',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'oauth_start'],
            methods: ['GET', 'POST'],
        ), $authPriority);
        $routes->add('register_public_auth_oauth_callback', new Route(
            '/auth/oauth/{provider<vk|mail_ru|ok_ru|yandex>}/callback',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'oauth_callback'],
            methods: ['GET'],
        ), $authPriority);
        $routes->add('register_public_auth_unread', new Route(
            '/auth/unread',
            ['_controller' => PublicAuthController::class, 'auth_action' => 'unread'],
            methods: ['GET'],
        ), $authPriority);
        $routes->add('register_live_updates', new Route(
            '/_live',
            ['_controller' => LiveUpdateController::class],
            methods: ['GET'],
        ));
    }
}
