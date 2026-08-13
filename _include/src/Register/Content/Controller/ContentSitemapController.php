<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content\Controller;

use Register\Content\ContentRepository;
use Register\Content\ContentType;
use S2\Cms\Framework\ControllerInterface;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Template\Viewer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Builds a sitemap from the same canonical content sources used by search. */
final readonly class ContentSitemapController implements ControllerInterface
{
    public const string PAGE_SERVICE_ID = self::class . '.pages';

    public const string BLOG_SERVICE_ID = self::class . '.blog';

    /** @var list<ContentType> */
    private array $contentTypes;

    public function __construct(
        private ContentRepository $contentRepository,
        private UrlBuilder        $urlBuilder,
        private Viewer            $viewer,
        ContentType ...$contentTypes,
    ) {
        if ($contentTypes === []) {
            throw new \InvalidArgumentException('A sitemap must contain at least one content type.');
        }

        $this->contentTypes = array_values($contentTypes);
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        $maxContentTime = 0;
        $items          = '';

        foreach ($this->contentRepository->published(...$this->contentTypes) as $content) {
            $publishedAt   = $content->publishedAt ?? 0;
            $updatedAt     = max($content->updatedAt ?? 0, $publishedAt);
            $maxContentTime = max($maxContentTime, $updatedAt);

            $items .= $this->viewer->render('sitemap_item', [
                'link'        => $this->urlBuilder->absLink($content->path),
                'time'        => $publishedAt,
                'modify_time' => $updatedAt,
            ]);
        }

        $output   = $this->viewer->render('sitemap', ['items' => $items]);
        $response = new Response($output);
        $response->headers->set('Content-Length', (string)\strlen($output));
        $response->headers->set('Content-Type', 'text/xml; charset=utf-8');
        $response->setLastModified(new \DateTimeImmutable('@' . $maxContentTime));
        $response->isNotModified($request);

        return $response;
    }
}
