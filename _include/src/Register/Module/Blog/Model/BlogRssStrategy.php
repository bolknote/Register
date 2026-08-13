<?php
/**
 * RSS feed for blog.
 *
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Module\Blog\Module as BlogModule;
use S2\Cms\Config\StringProxy;
use S2\Cms\Controller\Rss\FeedDto;
use S2\Cms\Controller\Rss\FeedItemDto;
use S2\Cms\Controller\Rss\RssStrategyInterface;
use S2\Cms\Template\Viewer;
use Register\Module\Blog\BlogUrlBuilder;
use Symfony\Contracts\Translation\TranslatorInterface;
use S2\Cms\Pdo\DbLayerException;

readonly class BlogRssStrategy implements RssStrategyInterface
{
    public function __construct(
        private PostProvider        $postProvider,
        private BlogUrlBuilder      $blogUrlBuilder,
        private TranslatorInterface $translator,
        private Viewer              $viewer,
        private StringProxy         $blogTitle,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function getFeedInfo(): FeedDto
    {
        $blogTitle = $this->blogTitle->get();

        return new FeedDto(
            $blogTitle,
            \sprintf($this->translator->trans('RSS blog description'), $blogTitle),
            $this->blogUrlBuilder->absMain(),
        );
    }

    /**
     * @throws DbLayerException
     * @return \S2\Cms\Controller\Rss\FeedItemDto[]
     */
    #[\Override]
    public function getFeedItems(): array
    {
        $posts  = $this->postProvider->lastPostsArray();
        $viewer = $this->viewer;
        $items  = [];
        foreach ($posts as $post) {
            $items[] = new FeedItemDto(
                $post['title'],
                $post['author'],
                $this->blogUrlBuilder->absPost($post['url']),
                $post['text'] .
                ($post['see_also'] === [] ? '' : $viewer->render('see_also', [
                    'see_also' => $post['see_also']
                ], BlogModule::class)) .
                ($post['tags'] === [] ? '' : $viewer->render('tags', [
                    'title' => $this->translator->trans('Tags'),
                    'tags'  => $post['tags'],
                ], BlogModule::class)),
                $post['create_time'],
                $post['modify_time']
            );
        }

        return $items;
    }
}
