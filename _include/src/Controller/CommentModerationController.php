<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Controller;

use Register\Comment\CommentRepository;
use Register\Content\ContentType;
use Register\Core\Comment\CommentHtml;
use Register\Core\Comment\Antispam\SpamFeedbackService;
use Register\Core\Framework\ControllerInterface;
use Register\Core\Model\AuthProvider;
use Register\Core\Model\Comment\CommentModerationTokenManager;
use Register\Core\Model\Comment\CommentModerator;
use Register\Core\Model\UrlBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class CommentModerationController implements ControllerInterface
{
    private const int MAX_COMMENT_BYTES = 65535;

    public function __construct(
        private CommentRepository             $commentRepository,
        private AuthProvider                  $authProvider,
        private CommentModerationTokenManager $tokenManager,
        private SpamFeedbackService           $spamFeedbackService,
        private UrlBuilder                    $urlBuilder,
        private TranslatorInterface           $translator,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        $moderator = $this->authProvider->getAuthenticatedCommentModerator($request);
        if (!$moderator instanceof CommentModerator) {
            return $this->error($request, $this->translator->trans('Comment moderation forbidden'), Response::HTTP_FORBIDDEN);
        }

        $contentType = ContentType::tryFrom($request->request->getString('target_type'));
        $commentId  = $request->request->getInt('comment_id');
        $action     = $request->request->getString('moderation_action');
        $token      = $request->request->getString('moderation_token');

        if ($contentType === null || $commentId <= 0 || !in_array($action, ['edit', 'delete', 'spam', 'ham'], true)) {
            return $this->error($request, $this->translator->trans('Invalid comment moderation request'), Response::HTTP_BAD_REQUEST);
        }

        if (!$this->tokenManager->isValid($token, $moderator, $contentType, $commentId)) {
            return $this->error($request, $this->translator->trans('Comment moderation token expired'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($action === 'edit') {
            if (!$moderator->canEdit) {
                return $this->error($request, $this->translator->trans('Comment moderation forbidden'), Response::HTTP_FORBIDDEN);
            }

            $submittedText = trim($request->request->getString('text'));
            $text = CommentHtml::sanitizeForStorage($submittedText);
            if ($text === '' || strlen($submittedText) > self::MAX_COMMENT_BYTES || strlen($text) > self::MAX_COMMENT_BYTES) {
                return $this->error($request, $this->translator->trans('Invalid edited comment'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (!$this->commentRepository->edit($commentId, $contentType, $text)) {
                return $this->error($request, $this->translator->trans('Comment not found'), Response::HTTP_NOT_FOUND);
            }
        } else {
            if (!$moderator->canHide) {
                return $this->error($request, $this->translator->trans('Comment moderation forbidden'), Response::HTTP_FORBIDDEN);
            }

            $comment = $this->commentRepository->findOfType($commentId, $contentType);
            if (!$comment instanceof \Register\Comment\Comment || ($comment->deleted && in_array($action, ['spam', 'ham'], true))) {
                return $this->error($request, $this->translator->trans('Comment not found'), Response::HTTP_NOT_FOUND);
            }

            if ($action === 'delete') {
                $this->commentRepository->tombstone($commentId, $contentType);
            } elseif ($action === 'spam' && !$this->spamFeedbackService->markSpam($commentId, $contentType)) {
                return $this->error($request, $this->translator->trans('Comment not found'), Response::HTTP_NOT_FOUND);
            } elseif ($action === 'ham' && !$this->markHam($commentId, $contentType)) {
                return $this->error($request, $this->translator->trans('Comment not found'), Response::HTTP_NOT_FOUND);
            }
        }

        if ($request->isXmlHttpRequest() || str_contains($request->headers->get('Accept') ?? '', 'application/json')) {
            return new JsonResponse(['success' => true, 'action' => $action]);
        }

        $returnPath = $this->safeReturnPath($request->request->getString('return_to'));
        $anchor     = $request->request->getInt('comment_anchor');
        $location   = $this->urlBuilder->link($returnPath) . ($anchor > 0 ? '#' . $anchor : '#comments-title');

        return new RedirectResponse($location, Response::HTTP_SEE_OTHER);
    }

    private function markHam(int $commentId, ContentType $contentType): bool
    {
        return $this->spamFeedbackService->markHam($commentId, $contentType);
    }

    private function safeReturnPath(string $path): string
    {
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, "\n") || str_contains($path, "\r")) {
            return '/';
        }

        return $path;
    }

    private function error(Request $request, string $message, int $status): Response
    {
        if ($request->isXmlHttpRequest() || str_contains($request->headers->get('Accept') ?? '', 'application/json')) {
            return new JsonResponse(['success' => false, 'message' => $message], $status);
        }

        return new Response($message, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
