<?php
/**
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Controller;

use Psr\Log\LoggerInterface;
use S2\Cms\Config\BoolProxy;
use S2\Cms\Comment\Antispam\CommentFormTokenManager;
use S2\Cms\Comment\Antispam\CommentFormTokenValidation;
use S2\Cms\Comment\Antispam\SpamAssessmentRepository;
use S2\Cms\Comment\Antispam\SpamRateLimiter;
use S2\Cms\Comment\SpamDetectorComment;
use S2\Cms\Comment\SpamDecision;
use S2\Cms\Comment\SpamDecisionProviderInterface;
use S2\Cms\Controller\Comment\CommentStrategyInterface;
use S2\Cms\Controller\Comment\TargetDto;
use S2\Cms\Framework\ControllerInterface;
use S2\Cms\Helper\StringHelper;
use S2\Cms\Mail\CommentMailer;
use S2\Cms\Model\AuthProvider;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Model\User\UserProvider;
use S2\Cms\Template\HtmlTemplateProvider;
use S2\Cms\Template\Viewer;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use S2\Cms\Pdo\DbLayerException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

readonly class CommentController implements ControllerInterface
{
    public function __construct(
        private AuthProvider                  $authProvider,
        private UserProvider                  $userProvider,
        private CommentStrategyInterface      $commentStrategy,
        private TranslatorInterface           $translator,
        private UrlBuilder                    $urlBuilder,
        private HtmlTemplateProvider          $templateProvider,
        private Viewer                        $viewer,
        private LoggerInterface               $logger,
        private CommentMailer                 $commentMailer,
        private SpamDecisionProviderInterface $spamDecisionProvider,
        private CommentFormTokenManager        $commentFormTokenManager,
        private SpamRateLimiter                $spamRateLimiter,
        private SpamAssessmentRepository       $spamAssessmentRepository,
        private BoolProxy                     $commentsEnabled,
        private BoolProxy                     $premoderationEnabled,
    ) {
    }

    private const int S2_MAX_COMMENT_BYTES = 65535;

    public static function commentHash(int $commentId, int $targetId, string $email, string $ip, string $strategyClass): string
    {
        return md5(serialize([$commentId, $targetId, $email, $ip, $strategyClass]));
    }

    /**
     * @throws DbLayerException
     * @throws BadRequestException
     */
    #[\Override]
    public function handle(Request $request): Response
    {
        $showEmail  = $request->request->get('show_email', false) !== false;
        $subscribed = $request->request->get('subscribed', false) !== false;
        $id         = $request->request->getString('id', '');
        if (preg_match('#^[0-9a-f]{32}$#', $id) !== 1) {
            $id = '';
        }

        $requestedParentId = filter_var(
            $request->request->getString('parent_id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $requestedReplyNumber = filter_var(
            $request->request->getString('reply_number'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );
        $parentId    = \is_int($requestedParentId) ? $requestedParentId : null;
        $replyNumber = \is_int($requestedReplyNumber) ? $requestedReplyNumber : 0;
        $replyName   = mb_substr(trim($request->request->getString('reply_name')), 0, 50);

        $path = $request->getPathInfo();
        $isPreview = $request->request->get('preview') !== null;

        /**
         * Input validation
         */
        $errors = [];
        $errorStatus = Response::HTTP_OK;
        $retryAfter = 0;
        $forceModeration = false;
        $formValidation = null;

        if (!$this->commentsEnabled->get()) {
            $errors[] = $this->translator->trans('disabled');
        }

        $text = $request->request->getString('text');
        $text = trim($text);
        if ($text === '') {
            $errors[] = $this->translator->trans('missing_text');
        }

        if (\strlen($text) > self::S2_MAX_COMMENT_BYTES) {
            $errors[] = \sprintf($this->translator->trans('long_text'), self::S2_MAX_COMMENT_BYTES);
        }

        $email = $request->request->getString('email');
        $email = trim($email);
        if (!StringHelper::isValidEmail($email)) {
            $errors[] = $this->translator->trans('email');
        }

        $name = $request->request->getString('name');
        $name = trim($name);
        if ($name === '') {
            $errors[] = $this->translator->trans('missing_nick');
        } elseif (mb_strlen($name) > 50) {
            $errors[] = $this->translator->trans('long_nick');
        }

        if (\count($errors) === 0) {
            if (trim($request->request->getString('homepage')) !== '') {
                $errors[] = $this->translator->trans('spam_message_rejected');
                $this->logger->notice('Comment honeypot was filled.', ['path' => $path]);
            } else {
                $formValidation = $this->validateFormToken($request, !$isPreview, $forceModeration);
                if (!$formValidation->valid) {
                    $errors[] = $this->translator->trans('form_expired');
                    $this->logger->notice('Comment form token validation failed.', [
                        'path'   => $path,
                        'reason' => $formValidation->error,
                    ]);
                }
            }
        }

        if ($isPreview && \count($errors) === 0) {
            // Handling "Preview" button
            $text_preview = '<p>' . $this->translator->trans('Comment preview info') . '</p>' . "\n" .
                $this->viewer->render('comment', [
                    'text'           => $text,
                    'nick'           => $name,
                    'time'           => time(),
                    'email'          => $email,
                    'show_email'     => $showEmail,
                    'good'           => false,
                    'is_author'      => $this->authProvider->isOnline($email),
                    'id'             => 0,
                    'i'              => 0,
                    'depth'          => 0,
                    'visual_depth'   => 0,
                    'show_addressee' => false,
                    'parent'         => null,
                    'children'       => '',
                    'is_preview'     => true,
                ]);

            $template = $this->templateProvider->getTemplate('service.php');

            $template
                ->putInPlaceholder('head_title', $this->translator->trans('Comment preview'))
                ->putInPlaceholder('title', $this->translator->trans('Comment preview'))
                ->putInPlaceholder('text', $text_preview)
                ->putInPlaceholder('id', $id)
                ->putInPlaceholder('commented', true)
                ->putInPlaceholder('comment_form', [
                    'name'         => $name,
                    'email'        => $email,
                    'show_email'   => $showEmail,
                    'subscribed'   => $subscribed,
                    'text'         => $text,
                    'parent_id'    => $parentId,
                    'reply_number' => $replyNumber,
                    'reply_name'   => $replyName,
                ])
            ;

            return $template->toHttpResponse();
        }

        if (
            \count($errors) === 0
            && $formValidation instanceof CommentFormTokenValidation
            && $formValidation->visitorId !== null
        ) {
            $rateLimit = $this->spamRateLimiter->consume(
                (string)$request->getClientIp(),
                $email,
                $text,
                $formValidation->visitorId,
            );
            if ($rateLimit->isLimited()) {
                $errors[]    = $this->translator->trans('spam_message_rejected');
                $errorStatus = Response::HTTP_TOO_MANY_REQUESTS;
                $retryAfter  = $rateLimit->retryAfter;
                $this->logger->notice('Comment rate limit exceeded.', [
                    'path'       => $path,
                    'violations' => $rateLimit->violations,
                ]);
            }

            if (!$rateLimit->available) {
                $forceModeration = true;
            }
        }

        $spamDecision = SpamDecision::empty();
        if (\count($errors) === 0) {
            $spamDecision = $this->spamDecisionProvider->getVerdict(
                new SpamDetectorComment(
                    $name,
                    $email,
                    $text,
                    $request->headers->get('User-Agent'),
                    $request->headers->get('Referer'),
                    $this->urlBuilder->absLink($path),
                    $formValidation?->ageSeconds,
                ),
                (string)$request->getClientIp()
            );
            // Convert spam detection report to some validation errors
            if ($spamDecision->shouldRejectLinks()) {
                $errors[] = $this->translator->trans('links_in_text');
            } elseif ($spamDecision->shouldRejectAsSpam()) {
                $errors[] = $this->translator->trans('spam_message_rejected');
            }
        }

        // What are we going to comment?
        $target = $this->commentStrategy->getTargetByRequest($request);

        if (!$target instanceof \S2\Cms\Controller\Comment\TargetDto && \count($errors) === 0) {
            $errors[] = $this->translator->trans('no_item');
        }

        if ($target instanceof \S2\Cms\Controller\Comment\TargetDto && $parentId !== null && !$this->commentStrategy->isValidParent($target->id, $parentId)) {
            $errors[]    = $this->translator->trans('invalid_parent');
            $parentId    = null;
            $replyNumber = 0;
            $replyName   = '';
        }

        if (\count($errors) > 0) {
            $errorText = '<p>' . $this->translator->trans('Error message') . '</p><ul>';
            foreach ($errors as $error) {
                $errorText .= '<li>' . $error . '</li>';
            }

            $errorText .= '</ul>';

            $template = $this->templateProvider->getTemplate('service.php');

            $template
                ->putInPlaceholder('head_title', $this->translator->trans('Error'))
                ->putInPlaceholder('title', $this->translator->trans('Error'))
                ->putInPlaceholder('text', $errorText . ($target instanceof \S2\Cms\Controller\Comment\TargetDto ? '<p>' . $this->translator->trans('Fix error') . '</p>' : ''))
                ->putInPlaceholder('id', $id)
                ->putInPlaceholder('commented', $target instanceof \S2\Cms\Controller\Comment\TargetDto) // can be commented, i.e. render comment form
                ->putInPlaceholder('comment_form', [
                    'name'         => $name,
                    'email'        => $email,
                    'show_email'   => $showEmail,
                    'subscribed'   => $subscribed,
                    'text'         => $text,
                    'parent_id'    => $parentId,
                    'reply_number' => $replyNumber,
                    'reply_name'   => $replyName,
                ])
            ;

            $this->logger->notice('Comment was not saved due to errors.', [
                'errors' => $errors,
                'path'   => $path,
            ]);
            $response = $template->toHttpResponse();
            $response->setStatusCode($errorStatus);
            if ($errorStatus === Response::HTTP_TOO_MANY_REQUESTS) {
                $response->headers->set('Retry-After', (string)max(1, $retryAfter));
            }

            return $response;
        }

        $link = $this->urlBuilder->absLink($path);

        $target = $this->requireTarget($target);

        /**
         * Everything is ok, save and send the comment
         */

        // Detect if there is a user logged in
        $isOnline = $this->authProvider->isOnline($email);

        $moderationRequired = $forceModeration || $spamDecision->shouldModerate($this->premoderationEnabled->get());

        // Save the comment
        $commentId = $this->commentStrategy->save(
            $target->id,
            $name,
            $email,
            $showEmail,
            $subscribed,
            $text,
            (string)$request->getClientIp(),
            $parentId,
        );
        $assessmentId = $spamDecision->getReport()->getAssessmentId();
        if ($assessmentId !== null) {
            try {
                $this->spamAssessmentRepository->attachComment(
                    $assessmentId,
                    $this->commentStrategy->getContentType(),
                    $commentId,
                );
            } catch (\Throwable $throwable) {
                $this->logger->error('Unable to attach spam assessment to comment.', [
                    'comment_id'    => $commentId,
                    'assessment_id' => $assessmentId,
                    'exception'     => $throwable,
                ]);
            }
        }

        $message = StringHelper::bbcodeToMail($text);

        /**
         * Sending the comment to moderators.
         * We DO NOT SEND the comment to a moderator if his email is used and he is online.
         * We'll do it later if required in CommentSentController.
         * It cannot be done right now due to a special cookie is available in CommentSentController only.
         *
         * @see CommentSentController
         * @see \S2\Cms\Model\AuthManager::createCommentCookie
         */
        foreach ($this->userProvider->getModerators([], $moderationRequired && $isOnline ? [$email] : []) as $moderator) {
            $this->commentMailer->mailToModerator(
                $moderator->login,
                $moderator->email,
                $message,
                $target->title,
                $link,
                $name,
                $email,
                !$moderationRequired,
                $spamDecision->getStatus()
            );
        }

        if (!$moderationRequired) {
            // Sending the comment to subscribers
            $this->commentStrategy->notifySubscribers($commentId);
            $this->commentStrategy->publishComment($commentId);
            $hash = $this->commentStrategy->getHashForPublishedComment($target->id);
            // Redirect to the last comment
            $redirectLink = $this->urlBuilder->link($path) . ($hash !== null ? '#' . $hash : '');
        } else {
            $redirectLink = $this->urlBuilder->rawLink('/comment_sent', [
                'go=' . urlencode($path),
                'sign=' . self::commentHash($commentId, $target->id, $email, (string)$request->getClientIp(), $this->commentStrategy::class),
            ]);
        }

        $response = new RedirectResponse($redirectLink);

        // Command for client code to clear draft from localStorage
        $response->headers->setCookie(Cookie::create('comment_form_sent', $id, httpOnly: false));

        return $response;
    }

    private function validateFormToken(Request $request, bool $consume, bool &$forceModeration): CommentFormTokenValidation
    {
        $token = $request->request->getString('antispam_token');
        try {
            return $this->commentFormTokenManager->validateAndMaybeConsume(
                $token,
                $request,
                $consume,
            );
        } catch (\Throwable $throwable) {
            $this->logger->error('Comment form replay protection failed.', ['exception' => $throwable]);
            $forceModeration = true;

            try {
                return $this->commentFormTokenManager->validateAndMaybeConsume(
                    $token,
                    $request,
                    false,
                );
            } catch (\Throwable $validationThrowable) {
                $this->logger->error('Comment form token fallback validation failed.', ['exception' => $validationThrowable]);

                return CommentFormTokenValidation::invalid('unavailable');
            }
        }
    }

    private function requireTarget(?TargetDto $target): TargetDto
    {
        return $target ?? throw new \LogicException('A validated comment target must be available before saving.');
    }
}
