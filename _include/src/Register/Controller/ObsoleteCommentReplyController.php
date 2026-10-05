<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Controller;

use Register\Core\Framework\ControllerInterface;
use Register\Core\Comment\ObsoleteReplyUrl;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Old reply-query URLs are not pages and must not render content or resolve aliases. */
final readonly class ObsoleteCommentReplyController implements ControllerInterface
{
    public const string ROUTE = 'obsolete_comment_reply';

    #[\Override]
    public function handle(Request $request): Response
    {
        return new Response(
            $request->isMethod(Request::METHOD_HEAD) ? '' : ObsoleteReplyUrl::BODY,
            Response::HTTP_NOT_FOUND,
            ObsoleteReplyUrl::HEADERS,
        );
    }
}
