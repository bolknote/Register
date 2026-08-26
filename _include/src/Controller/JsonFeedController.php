<?php
/**
 * @copyright 2026 Register contributors
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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** Renders any Register feed strategy as JSON Feed 1.1. */
final readonly class JsonFeedController implements ControllerInterface
{
    private const int CACHE_TTL = 600;

    public function __construct(
        private RssStrategyInterface     $feedStrategy,
        private UrlBuilder               $urlBuilder,
        private EventDispatcherInterface $eventDispatcher,
        private string                   $baseUrl,
        private StringProxy              $webmaster,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        $this->eventDispatcher->dispatch(new RssHitEvent($request, $this->feedStrategy));

        $feedInfo = $this->feedStrategy->getFeedInfo();
        $this->eventDispatcher->dispatch(new FeedRenderEvent($feedInfo));
        $items = [];
        foreach ($this->feedStrategy->getFeedItems() as $item) {
            $this->eventDispatcher->dispatch(new FeedItemRenderEvent($item));
            $content = $this->absoluteHtml($item->text);
            $entry = [
                'id'             => $item->link,
                'url'            => $item->link,
                'title'          => $item->title,
                'content_html'   => $content,
                'date_published' => gmdate(DATE_ATOM, $item->time),
                'date_modified'  => gmdate(DATE_ATOM, max($item->time, $item->modifyTime)),
            ];

            if ($item->summary !== '') {
                $entry['summary'] = $item->summary;
            }

            $author = $item->author !== '' ? $item->author : $this->webmaster->get();
            if ($author !== '') {
                $entry['authors'] = [['name' => $author]];
            }

            if ($item->image !== '') {
                $entry['image'] = $item->image;
            }

            if ($item->tags !== []) {
                $entry['tags'] = $item->tags;
            }

            $items[] = $entry;
        }

        $feedUrl = $feedInfo->jsonFeedLink;
        if ($feedUrl === '') {
            $query = $request->getQueryString();
            $canonicalPath = rtrim($request->getPathInfo(), '/');
            if ($canonicalPath === '') {
                $canonicalPath = '/';
            }

            $feedUrl = $this->urlBuilder->rawAbsLink(
                $canonicalPath,
                $query === null || $query === '' ? [] : [$query],
            );
        }

        $data = [
            'version'       => 'https://jsonfeed.org/version/1.1',
            'title'         => $feedInfo->title,
            'home_page_url' => $feedInfo->link,
            'feed_url'      => $feedUrl,
            'description'   => $feedInfo->description,
            'items'         => $items,
        ];

        $output = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $response = new Response($output);
        $response->headers->set('Content-Type', 'application/feed+json; charset=utf-8');
        $response->headers->set('Content-Length', (string)\strlen($output));
        $response->setPublic();
        $response->setMaxAge(self::CACHE_TTL);
        $response->setSharedMaxAge(self::CACHE_TTL);
        $response->setEtag(hash('sha256', $output), true);
        $response->isNotModified($request);

        return $response;
    }

    private function absoluteHtml(string $html): string
    {
        if (preg_match('#^https?://[^/]+#i', $this->baseUrl, $originMatch) !== 1) {
            return $html;
        }

        return preg_replace_callback(
            '#\b(href|src)(\s*=\s*)(["\'])/(?!/)#i',
            static fn(array $match): string => $match[1] . $match[2] . $match[3] . $originMatch[0] . '/',
            $html,
        ) ?? $html;
    }
}
