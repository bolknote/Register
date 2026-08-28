<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Admin\Mail;

use Psr\Log\LoggerInterface;
use Register\AdminYard\Translator;
use Register\Core\Helper\StringHelper;
use Register\Core\Mail\ApplicationMailerInterface;
use Register\Core\Mail\MailMessage;
use Register\Core\Mail\MailSettings;
use Register\Core\Model\PermissionChecker;
use Register\Core\Model\UrlBuilder;
use Register\Core\Security\Http\AdminMutationGuard;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class MailTestController
{
    public function __construct(
        private ApplicationMailerInterface $mailer,
        private MailSettings               $settings,
        private MailTestToken              $token,
        private PermissionChecker          $permissionChecker,
        private AdminMutationGuard         $mutationGuard,
        private UrlBuilder                 $urlBuilder,
        private Translator                 $translator,
        private LoggerInterface            $logger,
    ) {
    }

    public function send(Request $request): Response
    {
        if (!$this->mutationGuard->isPost($request)) {
            return new Response(
                $this->translator->trans('Only POST requests are allowed.'),
                Response::HTTP_METHOD_NOT_ALLOWED,
                ['Allow' => Request::METHOD_POST],
            );
        }

        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE)) {
            return new Response($this->translator->trans('No permission'), Response::HTTP_FORBIDDEN);
        }

        if (!$this->mutationGuard->hasValidCsrfToken($request, $this->token->value())) {
            return new Response($this->translator->trans('Invalid mail test token'), Response::HTTP_FORBIDDEN);
        }

        $recipient = mb_strtolower(trim($request->request->getString('recipient')));
        if (!StringHelper::isValidEmail($recipient) || mb_strlen($recipient) > 254) {
            return new Response($this->translator->trans('Invalid email address'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = 'accepted';
        try {
            $timestamp = gmdate(\DateTimeInterface::ATOM);
            $body = $this->translator->trans('Mail test body', [
                '{{ transport }}' => $this->settings->resolvedTransport(),
                '{{ timestamp }}' => $timestamp,
            ]);
            $this->mailer->send(new MailMessage(
                type: 'diagnostic_test',
                recipientEmail: $recipient,
                recipientName: '',
                subject: $this->translator->trans('Mail test subject'),
                textBody: $body,
                htmlBody: '<div>' . nl2br(htmlspecialchars(
                    $body,
                    ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                    'UTF-8',
                )) . '</div>',
            ));
        } catch (\Throwable $throwable) {
            $result = 'failed';
            $this->logger->warning('Register mail test failed.', [
                'error_class' => $throwable::class,
                'error'       => mb_substr($throwable->getMessage(), 0, 500),
                'user_id'     => $this->permissionChecker->getUserId(),
            ]);
        }

        return new RedirectResponse(
            $this->urlBuilder->rawLink('/_admin/index.php', [
                'entity=SystemStatus',
                'mail_test=' . $result,
            ]) . '#mail-status',
            Response::HTTP_SEE_OTHER,
        );
    }
}
