<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\HttpClient\Remote;

use S2\Cms\HttpClient\HttpResponse;

/** Result of one safe, DNS-pinned HTTP hop. */
final readonly class SafeRemoteResponse
{
    public function __construct(
        public string       $effectiveUrl,
        public HttpResponse $response,
        public ?string      $redirectUrl,
    ) {
        if ($effectiveUrl === '') {
            throw new \InvalidArgumentException('A safe remote response URL cannot be empty.');
        }
    }
}
