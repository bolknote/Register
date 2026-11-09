<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

use Register\Core\Model\AuthenticatedPublicUser;
use Register\Core\Model\AuthProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Comment\CommentHtml;
use Register\Core\Template\Viewer;
use Register\Live\LiveUpdateContext;
use Symfony\Component\HttpFoundation\Request;

/** Shared public account UI for the site header, auth page and comment form. */
final readonly class PublicAuthRenderer
{
    public function __construct(
        private Viewer                        $viewer,
        private UrlBuilder                    $urlBuilder,
        private AuthProvider                  $authProvider,
        private PublicAuthSettings            $settings,
        private PublicAuthFormToken           $formToken,
        private CommentNotificationRepository $notifications,
        private LiveUpdateContext             $liveUpdateContext,
    ) {
    }

    /** @param (\Closure(int): void)|null $expireResponseAt */
    public function renderHeader(Request $request, ?\Closure $expireResponseAt = null): string
    {
        return $this->viewer->render('public_auth_header', [
            'account_html' => $this->renderAccount($request),
            'dialog_html'  => $this->renderDialog($request, $expireResponseAt),
        ]);
    }

    public function renderAccount(Request $request, bool $asLivePatch = false): string
    {
        $user = $this->authProvider->getAuthenticatedPublicUser($request);
        $returnPath = $request->attributes->getString(PublicAuthCacheRequest::RETURN_ATTRIBUTE,
            PublicReturnPath::normalize($request->getRequestUri()));
        $liveRegion = null;
        $unread = 0;
        if ($user instanceof AuthenticatedPublicUser) {
            $unread = $this->notifications->countUnread($user);
            $liveRegion = 'site-account';
            if (!$asLivePatch) {
                $this->liveUpdateContext->subscribeSiteAccount();
            }
        }

        return $this->viewer->render('public_auth_account', [
            'user'         => $user,
            'unread'       => $unread,
            'live_region'  => $liveRegion,
            'login_url'    => $this->urlBuilder->rawLink('/auth', ['return=' . rawurlencode($returnPath)]),
            'unread_url'   => $this->urlBuilder->link('/auth/unread'),
            'logout_url'   => $this->urlBuilder->link('/auth/logout'),
            'logout_token' => $user instanceof AuthenticatedPublicUser
                ? $user->publicLogoutCsrfToken()
                : '',
            'admin_url'    => $this->urlBuilder->link('/_admin/index.php'),
            'return_path'  => $returnPath,
        ]);
    }

    /** @param (\Closure(int): void)|null $expireResponseAt */
    public function renderDialog(Request $request, ?\Closure $expireResponseAt = null): string
    {
        return $this->viewer->render('public_auth_dialog', $this->dialogVariables($request, $expireResponseAt));
    }

    /** @param (\Closure(int): void)|null $expireResponseAt */
    public function renderPanel(Request $request, ?\Closure $expireResponseAt = null): string
    {
        return $this->viewer->render('public_auth_panel', [
            ...$this->dialogVariables($request, $expireResponseAt),
            'initial_mode' => $request->query->getString('mode', 'methods'),
        ]);
    }

    /** @param array<string, mixed> $draft */
    public function renderPendingComment(array $draft, PendingCommentRecovery $recovery, string $email, string $error): string
    {
        return $this->viewer->render('pending_comment', [
            'comment_html' => CommentHtml::render((string)$draft['comment_text'], ''),
            'email' => $email,
            'error' => $error,
            'draft_id' => $recovery->draftId,
            'form_token' => $recovery->formToken(),
            'submit_url' => $this->urlBuilder->rawLink('/auth/check-email'),
            'return_url' => PublicReturnPath::normalize((string)$draft['return_path']),
        ]);
    }

    /**
     * @param (\Closure(int): void)|null $expireResponseAt
     * @return array<string, mixed>
     */
    private function dialogVariables(Request $request, ?\Closure $expireResponseAt = null): array
    {
        $returnPath = $request->attributes->getString(PublicAuthCacheRequest::RETURN_ATTRIBUTE, PublicReturnPath::normalize(
            $request->query->getString('return', $request->getRequestUri()),
        ));
        $credential = $this->formToken->issueWithExpiry();
        if ($expireResponseAt !== null) {
            $expireResponseAt($credential['expires']);
        }

        return [
            'email_enabled' => $this->settings->emailEnabled(),
            'vk_enabled'    => $this->settings->vkEnabled(),
            'yandex_enabled' => $this->settings->yandexEnabled(),
            'password_url'  => $this->urlBuilder->link('/auth/password'),
            'email_url'     => $this->urlBuilder->link('/auth/email'),
            'vk_url'        => $this->oauthUrl('vk'),
            'mail_url'      => $this->oauthUrl('mail_ru'),
            'ok_url'        => $this->oauthUrl('ok_ru'),
            'yandex_url'    => $this->oauthUrl('yandex'),
            'return_path'   => $returnPath,
            'form_token'    => $credential['token'],
            'initial_mode'  => 'methods',
            'method_urls'   => [
                'methods' => $this->urlBuilder->rawLink('/auth', ['return=' . rawurlencode($returnPath)]),
                'email' => $this->urlBuilder->rawLink('/auth', ['mode=email', 'return=' . rawurlencode($returnPath)]),
                'password' => $this->urlBuilder->rawLink('/auth', ['mode=password', 'return=' . rawurlencode($returnPath)]),
            ],
        ];
    }

    private function oauthUrl(string $provider): string
    {
        return $this->urlBuilder->rawLink('/auth/oauth/' . $provider);
    }
}
