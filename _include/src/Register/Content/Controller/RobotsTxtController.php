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
        $adminPath = rtrim($this->basePath, '/') . '/_admin/';
        $output = "User-agent: *\n"
            . 'Disallow: ' . $adminPath . "\n"
            . 'Sitemap: ' . $this->contentUrlGenerator->rawAbsolutePath('/sitemap.xml') . "\n";

        $response = new Response($output);
        $response->headers->set('Content-Length', (string)\strlen($output));
        $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $response->setEtag(hash('sha256', $output));
        $response->isNotModified($request);

        return $response;
    }
}
