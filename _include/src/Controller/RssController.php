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
    public function __construct(
        private RssStrategyInterface     $rssStrategy,
        private UrlBuilder               $urlBuilder,
        private Viewer                   $viewer,
        private EventDispatcherInterface $eventDispatcher,
        private string                   $basePath,
        private string                   $baseUrl,
        private string                   $version,
        private StringProxy              $webmaster,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        $this->eventDispatcher->dispatch(new RssHitEvent($request, $this->rssStrategy));

        $modifiedSince   = $request->headers->get('If-Modified-Since');
        $lastRequestTime = $modifiedSince !== null ? strtotime($modifiedSince) : 0;

        $maxContentTime = 0;
        $items          = '';

        foreach ($this->rssStrategy->getFeedItems() as $item) {
            $itemUpdatedAt = max($item->modifyTime, $item->time);
            if ($itemUpdatedAt <= $lastRequestTime) {
                // We have already sent this item in the previous response
                continue;
            }

            $maxContentTime = max($maxContentTime, $itemUpdatedAt);

            // Fixing URLs without a domain
            $item->text = str_replace('href="' . $this->basePath . '/', 'href="' . $this->baseUrl . '/', $item->text);
            $item->text = str_replace('src="' . $this->basePath . '/', 'src="' . $this->baseUrl . '/', $item->text);

            $webmaster = $this->webmaster->get();
            if ($item->author === '' && $webmaster !== '') {
                $item->author = $webmaster;
            }

            $this->eventDispatcher->dispatch(new FeedItemRenderEvent($item));

            $items .= $this->viewer->render('rss_item', ['item' => $item]);
        }

        if ($items === '' && $lastRequestTime > 0) {
            return new Response(null, Response::HTTP_NOT_MODIFIED);
        }

        $feedInfo = $this->rssStrategy->getFeedInfo();
        $selfLink = $this->urlBuilder->absLink($request->getPathInfo());

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
        $response->headers->set('Content-Type', 'text/xml; charset=utf-8');
        $response->setLastModified(new \DateTimeImmutable('@' . $maxContentTime));

        return $response;
    }
}
