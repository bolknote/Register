<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http;

use Symfony\Component\HttpFoundation\Response;

/** Author-controlled HTML previews run in an opaque-origin document, never in the editor. */
final class SandboxedHtmlResponse extends Response
{
    public const string POLICY = "sandbox allow-scripts allow-forms allow-modals allow-downloads allow-popups allow-presentation; "
        . "default-src * data: blob: 'unsafe-inline' 'unsafe-eval'; frame-ancestors 'self'";

    /** @param array<string, string> $headers */
    public function __construct(?string $content = '', int $status = Response::HTTP_OK, array $headers = [])
    {
        parent::__construct($content, $status, $headers);
        ContentSecurityPolicy::apply($this);
    }
}
