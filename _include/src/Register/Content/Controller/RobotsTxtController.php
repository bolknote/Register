<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content\Controller;

use Register\Url\ContentUrlGenerator;
use Register\Core\Framework\ControllerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Publishes crawler rules and advertises Register's canonical sitemap. */
final readonly class RobotsTxtController implements ControllerInterface
{
    public function __construct(
        private ContentUrlGenerator $contentUrlGenerator,
        private string              $basePath,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        $basePath = rtrim($this->basePath, '/');
        // Exact and query rules avoid blocking unrelated content such as /author.
        // Leave page assets, feeds and public discovery endpoints crawlable.
        $disallowedPaths = [
            '/_admin$',
            '/_admin?',
            '/_admin/',
            '/_analytics/collect$',
            '/_analytics/collect?',
            '/_inplace/',
            '/_live$',
            '/_live?',
            '/_reactions$',
            '/_reactions?',
            '/_reactions/',
            '/_visitor/resolve$',
            '/_visitor/resolve?',
            '/auth$',
            '/auth?',
            '/auth/',
            '/comment-moderate$',
            '/comment-moderate?',
            '/comment_sent$',
            '/comment_sent?',
            '/comment_unsubscribe$',
            '/comment_unsubscribe?',
        ];
        $output = "User-agent: *\n";
        foreach ($disallowedPaths as $path) {
            $output .= 'Disallow: ' . $basePath . $path . "\n";
        }

        $output .= 'Sitemap: ' . $this->contentUrlGenerator->rawAbsolutePath('/sitemap.xml') . "\n";

        $response = new Response($output);
        $response->headers->set('Content-Length', (string)\strlen($output));
        $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $response->setEtag(hash('sha256', $output));
        $response->isNotModified($request);

        return $response;
    }
}
