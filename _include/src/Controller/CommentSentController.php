<?php
/**
 * @copyright 2007-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Controller;

use Register\Core\Controller\Comment\CommentStrategyInterface;
use Register\Core\Comment\CommentHtml;
use Register\Core\Framework\ControllerInterface;
use Register\Core\Mail\CommentMailer;
use Register\Core\Model\AuthProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Model\User\UserProvider;
use Register\Core\Template\HtmlTemplateProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Register\Core\Pdo\DbLayerException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

/**
 * Outputs "comment saved" message (used if the pre-moderation mode is enabled)
 */
readonly class CommentSentController implements ControllerInterface
{
    /**
     * @var CommentStrategyInterface[]
     */
    private array $commentStrategies;

    public function __construct(
        private AuthProvider          $authProvider,
        private UserProvider          $userProvider,
        private TranslatorInterface   $translator,
        private UrlBuilder            $urlBuilder,
        private HtmlTemplateProvider  $templateProvider,
        private CommentMailer         $commentMailer,
        CommentStrategyInterface      ...$strategies
    ) {
        $this->commentStrategies = $strategies;
    }

    /**
     * @throws DbLayerException
     * @throws BadRequestException
     */
    #[\Override]
    public function handle(Request $request): Response
    {
        $targetPath     = $request->query->getString('go');
        $commentHash    = $request->query->getString('sign');
        $moderatorEmail = $this->authProvider->getAuthenticatedModeratorEmail($request);
        $authorIp       = (string)$request->getClientIp();

        foreach ($this->commentStrategies as $commentStrategy) {
            $comment = $commentStrategy->getRecentComment($commentHash, $authorIp);
            if (!$comment instanceof \Register\Core\Controller\Comment\CommentDto) {
                continue;
            }

            if ($moderatorEmail === $comment->email) {
                // We have confirmed that the moderator is the one who has really sent the comment
                return $this->privateResponse(
                    $this->publishAndNotifyAndGetRedirectResponse($commentStrategy, $comment, $targetPath),
                );
            }

            $moderators = $this->userProvider->getModerators([$comment->email]);
            if (\count($moderators) > 0) {
                /**
                 * The comment was sent with a moderator email but the moderator is not logged in.
                 * We assume that this comment has been written by somebody else.
                 * So we have to notify this moderator.
                 */
                $link    = $this->urlBuilder->absLink($targetPath);
                $message = CommentHtml::plainText($comment->text);
                $target  = $commentStrategy->getTargetById($comment->targetId);
                foreach ($moderators as $moderator) {
                    $this->commentMailer->mailToModerator(
                        $moderator->login,
                        $moderator->email,
                        $message,
                            $target->title ?? 'unknown item',
                        $link,
                        $comment->name,
                        $comment->email,
                        false,
                        'unknown'
                    );
                }
            }

            break;
        }

        $template = $this->templateProvider->getTemplate('service.php');

        $template
            ->putInPlaceholder('head_title', '✅ ' . $this->translator->trans('Comment sent'))
            ->putInPlaceholder('title', $this->translator->trans('Comment sent'))
            ->putInPlaceholder('text', \sprintf($this->translator->trans('Comment sent info'), register_htmlencode($this->urlBuilder->link($targetPath)), $this->urlBuilder->link('/')))
        ;

        return $this->privateResponse($template->toHttpResponse());
    }

    private function publishAndNotifyAndGetRedirectResponse(
        CommentStrategyInterface $commentStrategy,
        Comment\CommentDto       $comment,
        string                    $targetPath
    ): RedirectResponse {
        $commentStrategy->notifySubscribers($comment->id);
        $commentStrategy->publishComment($comment->id);

        $hash = $commentStrategy->getHashForPublishedComment($comment->targetId);

        // Redirect to the last comment
        $redirectLink = $this->urlBuilder->link($targetPath) . ($hash !== null ? '#' . $hash : '');

        return new RedirectResponse($redirectLink);
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
