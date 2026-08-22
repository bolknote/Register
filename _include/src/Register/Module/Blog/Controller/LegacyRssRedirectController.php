<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Controller;

use Register\Core\Framework\ControllerInterface;
use Register\Core\Model\UrlBuilder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keeps existing RSS subscriptions working at the historical feed address. */
final readonly class LegacyRssRedirectController implements ControllerInterface
{
    public function __construct(
        private UrlBuilder $urlBuilder,
        private string     $urlPrefix,
    ) {
    }

    #[\Override]
    public function handle(Request $request): RedirectResponse
    {
        $target = $this->urlBuilder->rawLink('/rss');
        $query  = $request->server->getString('QUERY_STRING');
        if (str_contains($this->urlPrefix, '?')) {
            $delimiter = strpos($query, '&');
            $query     = $delimiter === false ? '' : substr($query, $delimiter + 1);
        }

        $query = Request::normalizeQueryString($query);
        if ($query !== '') {
            $target .= (str_contains($target, '?') ? '&' : '?') . $query;
        }

        return new RedirectResponse($target, Response::HTTP_MOVED_PERMANENTLY);
    }
}
