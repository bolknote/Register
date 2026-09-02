<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content\Controller;

use Register\Content\ContentRepository;
use Register\Content\ContentSitemapCache;
use Register\Content\ContentType;
use Register\Url\ContentUrlGenerator;
use Register\Core\Framework\ControllerInterface;
use Register\Core\Template\Viewer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Builds a sitemap from the same canonical content sources used by search. */
final readonly class ContentSitemapController implements ControllerInterface
{
    public const string SERVICE_ID = self::class . '.all';

    /**
     * Keeping each file well below both protocol limits (50,000 URLs and 50 MB)
     * leaves enough room even for URLs close to the 2,048-character limit.
     */
    private const int URLS_PER_SITEMAP = 10_000;

    private const int MAX_URL_LENGTH = 2_047;

    /** @var list<ContentType> */
    private array $contentTypes;

    public function __construct(
        private ContentRepository $contentRepository,
        private ContentUrlGenerator $contentUrlGenerator,
        private Viewer            $viewer,
        private ContentSitemapCache $cache,
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
        $part = $request->attributes->get('part');
        if ($part === null) {
            return $this->indexResponse($request);
        }

        $part = (int)$part;
        if ($part < 1 || $part > intdiv(PHP_INT_MAX, self::URLS_PER_SITEMAP)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return $this->partResponse($request, $part);
    }

    private function indexResponse(Request $request): Response
    {
        $output = $this->cache->rememberString('index', function (): string {
            $partCount = max(1, ceil($this->entryCount() / self::URLS_PER_SITEMAP));
            $items     = '';
            for ($part = 1; $part <= $partCount; ++$part) {
                $items .= $this->viewer->render('sitemap_index_item', [
                    'link' => $this->contentUrlGenerator->rawAbsolutePath('/sitemap-' . $part . '.xml'),
                ]);
            }

            return $this->viewer->render('sitemap_index', ['items' => $items]);
        });

        return $this->xmlResponse($output, $request);
    }

    private function partResponse(Request $request, int $part): Response
    {
        $partCount = max(1, (int)ceil($this->entryCount() / self::URLS_PER_SITEMAP));
        if ($part > $partCount) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $output = $this->cache->rememberString('part_' . $part, fn(): string => $this->renderPart($part));

        return $this->xmlResponse($output, $request);
    }

    private function renderPart(int $part): string
    {
        $start = ($part - 1) * self::URLS_PER_SITEMAP;
        $end   = $start + self::URLS_PER_SITEMAP;
        $index = 0;
        $items = '';

        foreach ($this->entries() as $entry) {
            if ($index >= $end) {
                break;
            }

            if ($index >= $start) {
                $items .= $this->viewer->render('sitemap_item', $entry);
            }

            ++$index;
        }

        return $this->viewer->render('sitemap', ['items' => $items]);
    }

    private function entryCount(): int
    {
        return $this->cache->rememberInt('entry_count', function (): int {
            $count = 0;
            foreach ($this->entries() as $_entry) {
                ++$count;
            }

            return $count;
        });
    }

    /**
     * @return \Generator<int, array{link: string, modify_time: int|null}>
     */
    private function entries(): \Generator
    {
        foreach ($this->contentRepository->published(...$this->contentTypes) as $content) {
            $link = $this->contentUrlGenerator->rawAbsolutePath($content->path);
            if (\strlen($link) > self::MAX_URL_LENGTH) {
                continue;
            }

            $publishedAt = $content->publishedAt ?? 0;
            $updatedAt   = max($content->updatedAt ?? 0, $publishedAt);

            yield [
                'link'        => $link,
                'modify_time' => $updatedAt > 0 ? $updatedAt : null,
            ];
        }
    }

    private function xmlResponse(string $output, Request $request): Response
    {
        $response = new Response($output);
        $response->headers->set('Content-Length', (string)\strlen($output));
        $response->headers->set('Content-Type', 'application/xml; charset=utf-8');
        $response->setEtag(hash('sha256', $output));
        $response->isNotModified($request);

        return $response;
    }
}
