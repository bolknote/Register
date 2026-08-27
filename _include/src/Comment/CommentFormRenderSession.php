<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment;

use Symfony\Component\HttpFoundation\Cookie;

/** Request-bound state shared by every comment form rendered into one response. */
final readonly class CommentFormRenderSession
{
    public function __construct(
        public string $visitorToken,
        public Cookie $visitorCookie,
    ) {
    }
}
