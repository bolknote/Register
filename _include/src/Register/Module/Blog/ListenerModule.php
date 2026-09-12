<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   https://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog;

use Register\Comment\CommentChangedEvent;
use Register\Comment\CommentMutationSource;
use Register\Content\ContentChangedEvent;
use Register\Content\ContentRenderedEvent;
use Register\Content\ContentViewRecorderInterface;
use Register\Module\Analytics\BotDetector;
use Register\Module\Blog\Controller\FlatContentController;
use Register\Module\Blog\Model\BlogPageCache;
use Register\Module\Blog\Model\BlogPlaceholderProvider;
use Register\Module\Blog\Model\DeferredBlogSidebar;
use Register\Module\Blog\Model\SiteHeaderRenderer;
use Register\Module\Blog\Service\TagsSearchProvider;
use Register\Module\Search\Event\TagsSearchEvent;
use Register\Core\Asset\AssetPack;
use Register\Core\Asset\PublicAssetUrl;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerAwareListenerModuleInterface;
use Register\Core\Model\Article\ArticleRenderedEvent;
use Register\Core\Template\TemplateAssetEvent;
use Register\Core\Template\TemplateEvent;
use Register\Core\Template\Viewer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ListenerModule implements ContainerAwareListenerModuleInterface
{
    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(ContentChangedEvent::class, static function (ContentChangedEvent $event) use ($container): void {
            $container->get(BlogPageCache::class)->invalidateContentChange($event->contentId);
        });

        $eventDispatcher->addListener(CommentChangedEvent::class, static function (CommentChangedEvent $event) use ($container): void {
            $pageCache = $container->get(BlogPageCache::class);
            $pageCache->invalidateCommentChange(
                $event->contentId,
                deferUntilCommit: $event->source === CommentMutationSource::IMPORTED,
            );
        });

        $eventDispatcher->addListener(ContentRenderedEvent::class, static function (ContentRenderedEvent $event) use ($container): void {
            $request = $container->get(RequestStack::class)->getCurrentRequest();
            $purpose = strtolower(trim(implode(' ', [
                $request?->headers->get('Purpose', '') ?? '',
                $request?->headers->get('Sec-Purpose', '') ?? '',
            ])));
            if (!$request instanceof Request
                || !$request->isMethod('GET')
                || $request->attributes->getBoolean(FlatContentController::DEFER_VIEW_RECORDING_ATTRIBUTE)
                || str_contains($purpose, 'prefetch')
                || $container->get(BotDetector::class)->isBot($request->headers->get('User-Agent', '') ?? '')
            ) {
                return;
            }

            $container->get(ContentViewRecorderInterface::class)->record($event->contentId);
        });

        $eventDispatcher->addListener(TemplateEvent::EVENT_PRE_REPLACE, static function (TemplateEvent $event) use ($container): void {
            if (!$event->htmlTemplate->hasPlaceholder('<!-- register_site_header -->')) {
                return;
            }

            $request = $container->get(RequestStack::class)->getCurrentRequest();
            if (!$request instanceof Request) {
                return;
            }

            $event->htmlTemplate->registerPlaceholder(
                '<!-- register_site_header -->',
                $container->get(SiteHeaderRenderer::class)->render($request),
            );
        });

        $eventDispatcher->addListener(TemplateEvent::EVENT_CREATED, static function (TemplateEvent $event): void {
            $blogPlaceholders = [];
            $template         = $event->htmlTemplate;

            foreach (['register_blog_last_comments', 'register_blog_last_discussions', 'register_blog_last_post', 'register_blog_navigation'] as $blogPlaceholder) {
                if ($template->hasPlaceholder('<!-- ' . $blogPlaceholder . ' -->')) {
                    $blogPlaceholders[$blogPlaceholder] = 1;
                }
            }

            if (\count($blogPlaceholders) === 0) {
                return;
            }

            if (isset($blogPlaceholders['register_blog_last_comments'])) {
                $template->registerPlaceholder(
                    '<!-- register_blog_last_comments -->',
                    DeferredBlogSidebar::placeholder(DeferredBlogSidebar::RECENT_COMMENTS),
                );
            }

            if (isset($blogPlaceholders['register_blog_last_discussions'])) {
                $template->registerPlaceholder(
                    '<!-- register_blog_last_discussions -->',
                    DeferredBlogSidebar::placeholder(DeferredBlogSidebar::RECENT_DISCUSSIONS),
                );
            }

            if (isset($blogPlaceholders['register_blog_last_post'])) {
                $template->registerPlaceholder(
                    '<!-- register_blog_last_post -->',
                    DeferredBlogSidebar::placeholder(DeferredBlogSidebar::LAST_POST),
                );
            }

            if (isset($blogPlaceholders['register_blog_navigation'])) {
                $template->registerPlaceholder(
                    '<!-- register_blog_navigation -->',
                    DeferredBlogSidebar::placeholder(DeferredBlogSidebar::NAVIGATION),
                );
            }
        });

        $eventDispatcher->addListener(ArticleRenderedEvent::class, static function (ArticleRenderedEvent $event) use ($container): void {
            if ($event->template->hasPlaceholder('<!-- register_blog_tags -->')) {
                $viewer = $container->get(Viewer::class);
                /** @var TranslatorInterface $translator */
                $translator = $container->get('register_blog_translator');
                $placeholderProvider = $container->get(BlogPlaceholderProvider::class);

                $register_blog_tags = $placeholderProvider->getBlogTagsForArticle($event->articleId);
                $event->template->registerPlaceholder('<!-- register_blog_tags -->', $register_blog_tags === [] ? '' : $viewer->render('menu_block', [
                    'title' => $translator->trans('See in blog'),
                    'menu'  => $register_blog_tags,
                    'class' => 'register_blog_tags',
                ]));
            }
        });

        $eventDispatcher->addListener(TagsSearchEvent::class, static function (TagsSearchEvent $event) use ($container): void {
            $tagsSearchProvider = $container->get(TagsSearchProvider::class);
            $blogTagLinks       = $tagsSearchProvider->findBlogTags($event->words);

            if (\count($blogTagLinks) > 0) {
                /** @var TranslatorInterface $translator */
                $translator = $container->get('register_blog_translator');
                if ($event->getLine() !== null) {
                    $event->addShortLine(\sprintf($translator->trans('Found blog tags short'), implode(', ', $blogTagLinks)));
                } else {
                    $event->addLine(\sprintf($translator->trans('Found blog tags'), implode(', ', $blogTagLinks)));
                }
            }
        });

        $eventDispatcher->addListener(TemplateAssetEvent::class, static function (TemplateAssetEvent $event) use ($container): void {
            $assetUrl = new PublicAssetUrl(
                $container->getStringParameter('public_root_dir'),
                $container->getStringParameter('base_path'),
            );

            $event->assetPack
                ->addCss('../../_assets/register/blog/site.css', [AssetPack::OPTION_MERGE])
                ->addCss('../../_assets/register/post-recovery.css', [AssetPack::OPTION_MERGE])
                ->addJs($assetUrl->versioned('/_assets/register/post-recovery.js'), [AssetPack::OPTION_DEFER])
                ->addJs($assetUrl->versioned('/_assets/register/post-inplace.js'), [AssetPack::OPTION_DEFER])
            ;
        });
    }
}
