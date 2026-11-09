<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Symfony\Component\HttpFoundation\Response;

/** Publishes a canonical template only when expansion exactly reproduces the ordinary response. */
final readonly class PublicAuthPageSnapshot
{
    public const string ATTRIBUTE = '_register_auth_page_snapshot';

    public function __construct(public PublicAuthCacheRequest $parameters, private string $template)
    {
    }

    public function publicResponse(Response $response): Response
    {
        $public = clone $response;
        if ($response->getContent() !== $this->parameters->expand($this->template)) {
            $public->headers->addCacheControlDirective('no-store');
            return $public;
        }

        $public->setContent($this->template);
        $public->headers->set('X-Register-Page-Cache', 'stored');
        return $public;
    }
}
