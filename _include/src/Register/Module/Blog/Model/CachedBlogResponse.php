<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Symfony\Component\HttpFoundation\Response;

/** Serializable HTTP response snapshot stored before request-level CSP and compression processing. */
final readonly class CachedBlogResponse
{
    /** @param array<string, list<string|null>> $headers */
    private function __construct(
        private string $content,
        private int    $status,
        private array  $headers,
    ) {
    }

    public static function fromResponse(Response $response): ?self
    {
        $content = $response->getContent();
        if (!\is_string($content)
            || $response->getStatusCode() !== Response::HTTP_OK
            || $response->headers->getCookies() !== []
        ) {
            return null;
        }

        return new self($content, $response->getStatusCode(), $response->headers->all());
    }

    public function toResponse(): Response
    {
        return new Response($this->content, $this->status, $this->headers);
    }
}
