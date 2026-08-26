<?php
/**
 * Creates RSS feeds.
 *
 * @copyright 2009-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Controller;

use Register\Core\Config\StringProxy;
use Register\Core\Controller\Rss\FeedItemRenderEvent;
use Register\Core\Controller\Rss\FeedRenderEvent;
use Register\Core\Controller\Rss\RssHitEvent;
use Register\Core\Controller\Rss\RssStrategyInterface;
use Register\Core\Framework\ControllerInterface;
use Register\Core\Model\UrlBuilder;
use Register\Core\Template\Viewer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

readonly class RssController implements ControllerInterface
{
    private const int CACHE_TTL = 600;

    public function __construct(
        private RssStrategyInterface     $rssStrategy,
        private UrlBuilder               $urlBuilder,
        private Viewer                   $viewer,
        private EventDispatcherInterface $eventDispatcher,
        private string                   $baseUrl,
        private string                   $version,
        private StringProxy              $webmaster,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        $this->eventDispatcher->dispatch(new RssHitEvent($request, $this->rssStrategy));

        $maxContentTime = 0;
        $items          = '';

        foreach ($this->rssStrategy->getFeedItems() as $item) {
            $itemUpdatedAt = max($item->modifyTime, $item->time);
            $maxContentTime = max($maxContentTime, $itemUpdatedAt);

            $webmaster = $this->webmaster->get();
            if ($item->author === '' && $webmaster !== '') {
                $item->author = $webmaster;
            }

            $this->eventDispatcher->dispatch(new FeedItemRenderEvent($item));
            $item->text = $this->feedCompatibleHtml($this->absoluteHtml($item->text));

            $items .= $this->viewer->render('rss_item', ['item' => $item]);
        }

        $feedInfo = $this->rssStrategy->getFeedInfo();
        $query = $request->getQueryString();
        $selfLink = $this->urlBuilder->absLink(
            $request->getPathInfo(),
            $query === null || $query === '' ? [] : explode('&', $query),
        );

        $this->eventDispatcher->dispatch(new FeedRenderEvent($feedInfo));

        $output = $this->viewer->render(
            'rss',
            ['items' => $items, 'maxContentTime' => $maxContentTime, 'feedInfo' => $feedInfo, 'selfLink' => $selfLink] + [
                'baseUrl' => $this->baseUrl,
                'version' => $this->version,
            ]
        );

        $response = new Response($output);
        $response->headers->set('Content-Length', (string)\strlen($output));
        $response->headers->set('Content-Type', 'application/rss+xml; charset=utf-8');
        $response->setPublic();
        $response->setMaxAge(self::CACHE_TTL);
        $response->setSharedMaxAge(self::CACHE_TTL);
        if ($maxContentTime > 0) {
            $response->setLastModified(new \DateTimeImmutable('@' . $maxContentTime));
            $response->isNotModified($request);
        }

        return $response;
    }

    /** Removes markup that commonly makes otherwise valid RSS fail in stricter readers. */
    private function feedCompatibleHtml(string $html): string
    {
        $html = preg_replace('#<style\b[^>]*>.*?</style\s*>#is', '', $html) ?? $html;

        return preg_replace('#</?nobr\b[^>]*>#i', '', $html) ?? $html;
    }

    private function absoluteHtml(string $html): string
    {
        if (preg_match('#^https?://[^/]+#i', $this->baseUrl, $originMatch) !== 1) {
            return $html;
        }

        return preg_replace_callback(
            '#\b(href|src)(\s*=\s*)(["\'])/(?!/)#i',
            static function (array $match) use ($originMatch): string {
                $attributePrefix = $match[1] . $match[2] . $match[3];

                return $attributePrefix . $originMatch[0] . '/';
            },
            $html,
        ) ?? $html;
    }
}
