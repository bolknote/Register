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

    public function renderHeader(Request $request): string
    {
        return $this->viewer->render('public_auth_header', [
            'account_html' => $this->renderAccount($request),
            'dialog_html'  => $this->renderDialog($request),
        ]);
    }

    public function renderAccount(Request $request, bool $asLivePatch = false): string
    {
        $user = $this->authProvider->getAuthenticatedPublicUser($request);
        $returnPath = PublicReturnPath::normalize($request->getRequestUri());
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

    public function renderDialog(Request $request): string
    {
        return $this->viewer->render('public_auth_dialog', $this->dialogVariables($request));
    }

    public function renderPanel(Request $request): string
    {
        return $this->viewer->render('public_auth_panel', $this->dialogVariables($request));
    }

    /** @return array<string, mixed> */
    private function dialogVariables(Request $request): array
    {
        $returnPath = PublicReturnPath::normalize(
            $request->query->getString('return', $request->getRequestUri()),
        );

        return [
            'email_enabled' => $this->settings->emailEnabled(),
            'vk_enabled'    => $this->settings->vkEnabled(),
            'yandex_enabled' => $this->settings->yandexEnabled(),
            'password_url'  => $this->urlBuilder->link('/auth/password'),
            'email_url'     => $this->urlBuilder->link('/auth/email'),
            'vk_url'        => $this->oauthUrl('vk', $returnPath),
            'mail_url'      => $this->oauthUrl('mail_ru', $returnPath),
            'ok_url'        => $this->oauthUrl('ok_ru', $returnPath),
            'yandex_url'    => $this->oauthUrl('yandex', $returnPath),
            'return_path'   => $returnPath,
            'form_token'    => $this->formToken->issue(),
        ];
    }

    private function oauthUrl(string $provider, string $returnPath): string
    {
        return $this->urlBuilder->rawLink('/auth/oauth/' . $provider, [
            'return=' . rawurlencode($returnPath),
        ]);
    }
}
