<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment;

use Register\Core\Comment\Antispam\CommentFormTokenManager;
use Register\Core\Model\AuthenticatedPublicUser;
use Register\Core\Model\AuthProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Template\TemplatePreCommentRenderEvent;
use Register\Core\Template\Viewer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Renders one request-bound comment form independently from the cacheable page shell. */
final readonly class CommentFormRenderer
{
    public function __construct(
        private UrlBuilder               $urlBuilder,
        private TranslatorInterface      $translator,
        private Viewer                   $viewer,
        private EventDispatcherInterface $eventDispatcher,
        private CommentFormTokenManager  $tokenManager,
        private AuthProvider             $authProvider,
    ) {
    }

    public function createSession(Request $request): CommentFormRenderSession
    {
        $visitorToken = $this->tokenManager->getOrCreateVisitorToken($request);

        return new CommentFormRenderSession(
            $visitorToken,
            $this->tokenManager->createVisitorCookie($visitorToken, $request),
        );
    }

    /**
     * @param array<string, mixed> $comment
     */
    public function render(
        Request                  $request,
        array                    $comment,
        CommentFormRenderSession $session,
    ): string {
        if (!array_key_exists('parent_id', $comment)) {
            $replyId = $request->query->getInt('reply_to');
            $comment += [
                'parent_id'    => $replyId > 0 ? $replyId : null,
                'reply_number' => max(0, $request->query->getInt('reply_number')),
                'reply_name'   => mb_substr(trim($request->query->getString('reply_name')), 0, 50),
            ];
        }

        $event = new TemplatePreCommentRenderEvent([$this->translator->trans('Comment syntax info')]);
        $this->eventDispatcher->dispatch($event);

        $authenticatedUser = $this->authProvider->getAuthenticatedPublicUser($request);
        $antispamToken = $this->tokenManager->issue(
            $request->getPathInfo(),
            $session->visitorToken,
        );

        return $this->viewer->render('comment_form', [
            ...$comment,
            'authenticatedUser' => $authenticatedUser instanceof AuthenticatedPublicUser
                ? $authenticatedUser
                : null,
            'syntaxHelpItems'    => $event->syntaxHelpItems,
            'action'             => $this->urlBuilder->link($request->getPathInfo()),
            'cancelReplyUrl'     => $this->urlBuilder->link($request->getPathInfo()) . '#add-comment',
            'antispamToken'      => $antispamToken,
            'commentFieldNames'  => $this->tokenManager->fieldNames($antispamToken),
        ]);
    }
}
