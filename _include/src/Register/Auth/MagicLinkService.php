<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Register\Comment\Antispam\SpamAssessmentRepository;
use Register\Comment\CommentMailPublisher;
use Register\Comment\CommentPublicationTrustPolicy;
use Register\Content\ContentType;
use Register\Controller\Comment\CommentStrategyInterface;
use Register\Controller\Comment\PendingEmailComment;
use Register\Controller\Comment\PendingEmailCommentServiceInterface;
use Register\Core\Helper\StringHelper;
use Register\Core\Model\UrlBuilder;
use Register\Core\Model\User\UserProvider;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Issues and consumes one-time email links, optionally carrying one validated comment. */
final readonly class MagicLinkService implements PendingEmailCommentServiceInterface
{
    /** @var array<string, CommentStrategyInterface> */
    private array $strategies;

    public function __construct(
        private PublicAuthSettings   $settings,
        private PublicAuthRepository $repository,
        private PublicAuthMailer     $mailer,
        private UrlBuilder           $urlBuilder,
        private MagicLinkRateLimiter $rateLimiter,
        private TranslatorInterface  $translator,
        private VisitorIdentityManager $visitorIdentityManager,
        private SpamAssessmentRepository $spamAssessmentRepository,
        private CommentMailPublisher $commentMailPublisher,
        private UserProvider         $userProvider,
        private CommentPublicationTrustPolicy $publicationTrustPolicy,
        CommentStrategyInterface ...$strategies,
    ) {
        $indexed = [];
        foreach ($strategies as $strategy) {
            $indexed[$strategy->getContentType()->value] = $strategy;
        }

        $this->strategies = $indexed;
    }

    public function requestLogin(Request $request, string $email, string $displayName, string $returnPath): void
    {
        $this->request($request, $email, $displayName, $returnPath, null);
    }

    #[\Override]
    public function requestVerification(Request $request, PendingEmailComment $comment): Response
    {
        try {
            $this->request($request, $comment->email, $comment->name, $comment->returnPath, [
                'content_type'        => $comment->contentType->value,
                'content_id'          => $comment->targetId,
                'parent_id'           => $comment->parentId,
                'text'                => $comment->text,
                'subscribed'          => $comment->subscribed,
                'ip'                  => $comment->ip,
                'moderation_required' => $comment->moderationRequired,
                'spam_assessment_id'  => $comment->spamAssessmentId,
                'spam_status'         => $comment->spamStatus,
            ], $comment->visitorId);
        } catch (MagicLinkRateLimitException $exception) {
            return new Response(
                $this->translator->trans('Too many sign-in links. Try again later.'),
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string)$exception->retryAfter],
            );
        }

        return new RedirectResponse($this->urlBuilder->rawLink('/auth/check-email'));
    }

    /**
     * @return array{user_id: int, return_path: string, comment_id: int|null, published: bool}
     */
    public function consume(string $token): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{40,100}$/D', $token) !== 1) {
            throw new \RuntimeException('The sign-in link is invalid or has expired.');
        }

        $row = $this->repository->consumeMagicLink($token);
        if ($row === null) {
            throw new \RuntimeException('The sign-in link is invalid, expired or has already been used.');
        }

        $email = (string)$row['email'];
        $name = (string)$row['display_name'];
        $userId = $this->repository->findOrCreateIdentity('email', mb_strtolower($email), $email, $name);
        $visitorId = \is_string($row['visitor_id'] ?? null) && $row['visitor_id'] !== ''
            ? $row['visitor_id']
            : null;
        if ($visitorId !== null) {
            $this->visitorIdentityManager->linkStoredVisitor($visitorId, $userId);
        }

        $commentId = null;
        $published = false;
        $contentType = ContentType::tryFrom((string)$row['content_type']);
        $targetId = isset($row['content_id']) ? (int)$row['content_id'] : 0;
        if ($contentType instanceof ContentType && $targetId > 0 && \is_string($row['comment_text'])) {
            $strategy = $this->strategies[$contentType->value] ?? null;
            if (!$strategy instanceof CommentStrategyInterface || $strategy->getTargetById($targetId) === null) {
                throw new \RuntimeException('The page for the pending comment no longer exists.');
            }

            $parentId = isset($row['parent_id']) ? (int)$row['parent_id'] : null;
            if ($parentId !== null && !$strategy->isValidParent($targetId, $parentId)) {
                $parentId = null;
            }

            $commentId = $strategy->save(
                $targetId,
                $name,
                $email,
                (bool)$row['subscribed'],
                $row['comment_text'],
                (string)$row['ip'],
                $parentId,
                $userId,
                $visitorId,
            );
            $assessmentId = isset($row['spam_assessment_id']) ? (int)$row['spam_assessment_id'] : 0;
            if ($assessmentId > 0) {
                $this->spamAssessmentRepository->attachComment($assessmentId, $contentType, $commentId);
            }

            $moderationRequired = (bool)($row['moderation_required'] ?? false)
                || $this->publicationTrustPolicy->requiresModeration($userId);
            if (!$moderationRequired) {
                $strategy->publishComment($commentId);
                $strategy->notifySubscribers($commentId);
                $published = true;
            }

            $spamStatus = mb_substr((string)($row['spam_status'] ?? ''), 0, 80);
            // Email identities are deliberately kept separate from privileged local accounts,
            // even when both use the same address. A verified public identity therefore cannot
            // suppress moderation mail for a local moderator with that address.
            foreach ($this->userProvider->getModerators() as $moderator) {
                $this->commentMailPublisher->moderator(
                    $commentId,
                    $contentType,
                    $moderator->email,
                    $published,
                    $spamStatus,
                );
            }
        }

        return [
            'user_id'     => $userId,
            'return_path' => PublicReturnPath::normalize((string)$row['return_path']),
            'comment_id'  => $commentId,
            'published'   => $published,
        ];
    }

    /** @param array{content_type?: string, content_id?: int|null, parent_id?: int|null, text?: string, subscribed?: bool, moderation_required?: bool, spam_assessment_id?: int|null, spam_status?: string, ip?: string}|null $pendingComment */
    private function request(
        Request $request,
        string $email,
        string $displayName,
        string $returnPath,
        ?array $pendingComment,
        ?string $visitorId = null,
    ): void
    {
        if (!$this->settings->emailEnabled()) {
            throw new \RuntimeException('Email sign-in is disabled.');
        }

        $email = mb_strtolower(trim($email));
        $displayName = trim($displayName);
        if (!StringHelper::isValidEmail($email)) {
            throw new \InvalidArgumentException('Enter a valid email address.');
        }

        $this->rateLimiter->consume($request->getClientIp() ?? '', $email);
        if ($displayName === '') {
            $localPart = strstr($email, '@', true);
            $displayName = \is_string($localPart) && $localPart !== '' ? $localPart : $email;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
        $returnPath = PublicReturnPath::normalize($returnPath);
        $visitorId ??= $this->visitorIdentityManager->recordInteraction($request);
        $this->repository->storeMagicLink(
            $token,
            $email,
            mb_substr($displayName, 0, 80),
            $returnPath,
            $pendingComment,
            visitorId: $visitorId,
        );
        $url = html_entity_decode($this->urlBuilder->absLink('/auth/email/callback', [
            'token=' . rawurlencode($token),
        ]));
        if (!$this->mailer->sendMagicLink($email, $url, $pendingComment !== null)) {
            throw new \RuntimeException('Unable to send the sign-in email.');
        }
    }
}
