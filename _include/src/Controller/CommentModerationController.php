<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Controller;

use S2\Cms\Comment\Antispam\SpamFeedbackService;
use S2\Cms\Framework\ControllerInterface;
use S2\Cms\Model\AuthProvider;
use S2\Cms\Model\Comment\CommentModerationTokenManager;
use S2\Cms\Model\Comment\CommentModerator;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class CommentModerationController implements ControllerInterface
{
    private const int MAX_COMMENT_BYTES = 65535;

    /** @var array<string, string> */
    private const array COMMENT_TABLES = [
        'article' => 'art_comments',
        'blog'    => 's2_blog_comments',
    ];

    public function __construct(
        private DbLayer                       $dbLayer,
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

        $targetType = $request->request->getString('target_type');
        $commentId  = $request->request->getInt('comment_id');
        $action     = $request->request->getString('moderation_action');
        $token      = $request->request->getString('moderation_token');
        $table      = self::COMMENT_TABLES[$targetType] ?? null;

        if ($table === null || $commentId <= 0 || !in_array($action, ['edit', 'delete', 'spam'], true)) {
            return $this->error($request, $this->translator->trans('Invalid comment moderation request'), Response::HTTP_BAD_REQUEST);
        }

        if (!$this->tokenManager->isValid($token, $moderator, $targetType, $commentId)) {
            return $this->error($request, $this->translator->trans('Comment moderation token expired'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($action === 'edit') {
            if (!$moderator->canEdit) {
                return $this->error($request, $this->translator->trans('Comment moderation forbidden'), Response::HTTP_FORBIDDEN);
            }

            $text = trim($request->request->getString('text'));
            if ($text === '' || strlen($text) > self::MAX_COMMENT_BYTES) {
                return $this->error($request, $this->translator->trans('Invalid edited comment'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (!$this->commentExists($table, $commentId, true)) {
                return $this->error($request, $this->translator->trans('Comment not found'), Response::HTTP_NOT_FOUND);
            }

            $this->dbLayer
                ->update($table)
                ->set('text', ':text')->setParameter('text', $text)
                ->where('id = :id')->setParameter('id', $commentId)
                ->execute()
            ;
        } else {
            if (!$moderator->canHide) {
                return $this->error($request, $this->translator->trans('Comment moderation forbidden'), Response::HTTP_FORBIDDEN);
            }

            if (!$this->commentExists($table, $commentId, $action === 'spam')) {
                return $this->error($request, $this->translator->trans('Comment not found'), Response::HTTP_NOT_FOUND);
            }

            if ($action === 'delete') {
                $this->dbLayer
                    ->update($table)
                    ->set('deleted', '1')
                    ->set('shown', '0')
                    ->set('sent', '1')
                    ->set('subscribed', '0')
                    ->where('id = :id')->setParameter('id', $commentId)
                    ->execute()
                ;
            } elseif (!$this->spamFeedbackService->markSpam($commentId, $targetType, $table)) {
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

    private function commentExists(string $table, int $commentId, bool $requireNotDeleted): bool
    {
        $query = $this->dbLayer
            ->select('COUNT(*)')
            ->from($table)
            ->where('id = :id')->setParameter('id', $commentId)
        ;
        if ($requireNotDeleted) {
            $query->andWhere('deleted = 0');
        }

        return (int)$query->execute()->result() > 0;
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
