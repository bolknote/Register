<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Url;

use S2\Cms\Framework\ControllerInterface;
use S2\Cms\Framework\Exception\NotFoundException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Redirects historical paths directly to the current canonical post URL. */
final readonly class ContentUrlAliasController implements ControllerInterface
{
    public function __construct(
        private ContentUrlAliasRepository $aliases,
        private ContentUrlGenerator       $urlGenerator,
    ) {
    }

    public function redirect(Request $request): ?RedirectResponse
    {
        try {
            $path = ContentUrlAliasRepository::normalizePath($request->getPathInfo());
        } catch (\InvalidArgumentException) {
            return null;
        }

        $slug = $this->aliases->publishedPostSlug($path);
        if ($slug === null || $slug === $path) {
            return null;
        }

        $target = $this->urlGenerator->post($slug);
        $query  = $request->getQueryString();
        if (is_string($query) && $query !== '') {
            $target .= (str_contains($target, '?') ? '&' : '?') . $query;
        }

        return new RedirectResponse($target, Response::HTTP_MOVED_PERMANENTLY);
    }

    #[\Override]
    public function handle(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirect($request) ?? throw new NotFoundException();
    }
}
