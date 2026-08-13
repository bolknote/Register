<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Http;

use S2\Cms\Model\UrlBuilder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

readonly class RedirectDetector
{
    /**
     * @param array<mixed> $redirectMap
     */
    public function __construct(
        private UrlBuilder $urlBuilder,
        private array      $redirectMap,
    ) {
    }

    public function getRedirectResponse(Request $request): ?RedirectResponse
    {
        if ($this->redirectMap === []) {
            return null;
        }

        $requestUri = $request->getPathInfo();
        $patterns = [];
        $replacements = [];
        foreach ($this->redirectMap as $pattern => $replacement) {
            if (!\is_string($pattern) || !\is_string($replacement)) {
                throw new \LogicException('Redirect rules must be a string-to-string map.');
            }

            $patterns[]     = $pattern;
            $replacements[] = $replacement;
        }

        /** @var non-empty-list<non-empty-string> $patterns */
        $newUrl = preg_replace($patterns, $replacements, $requestUri);
        if ($newUrl === null) {
            throw new \RuntimeException('Unable to apply redirect rules.');
        }

        if ($newUrl === $requestUri) {
            return null;
        }

        $url = str_starts_with($newUrl, 'http://') || str_starts_with($newUrl, 'https://') ? $newUrl : $this->urlBuilder->link($newUrl);

        return new RedirectResponse($url, Response::HTTP_MOVED_PERMANENTLY);
    }
}
