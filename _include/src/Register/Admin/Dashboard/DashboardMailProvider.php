<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Admin\Dashboard;

use Register\AdminYard\TemplateRenderer;
use Register\Comment\CommentMailQueueHandler;
use Register\Admin\Mail\MailTestToken;
use Register\Core\Config\StringProxy;
use Register\Core\Mail\MailDeliveryInspector;
use Register\Core\Mail\MailDnsInspector;
use Register\Core\Mail\MailSettings;
use Register\Core\Model\PermissionChecker;
use Register\Core\Queue\QueueMonitor;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class DashboardMailProvider implements SystemStatusProviderInterface
{
    public function __construct(
        private TemplateRenderer      $templateRenderer,
        private MailSettings          $settings,
        private MailDeliveryInspector $deliveryInspector,
        private MailDnsInspector      $dnsInspector,
        private QueueMonitor          $queueMonitor,
        private MailTestToken         $testToken,
        private PermissionChecker     $permissionChecker,
        private StringProxy           $webmasterEmail,
        private RequestStack          $requestStack,
    ) {
    }

    #[\Override]
    public function getHtml(): string
    {
        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE)) {
            return '';
        }

        $request = $this->requestStack->getMainRequest();
        $testResult = $request === null ? '' : $request->query->getString('mail_test');
        if (!\in_array($testResult, ['accepted', 'failed'], true)) {
            $testResult = '';
        }

        return $this->templateRenderer->render('_admin/templates/dashboard/mail-item.php.inc', [
            'settings'      => $this->settings,
            'errors'        => $this->settings->validationErrors(),
            'delivery'      => $this->deliveryInspector->inspect(),
            'dns'           => $this->dnsInspector->inspect(),
            'queue'         => $this->queueMonitor->statusForCodes(CommentMailQueueHandler::CODES),
            'csrfToken'     => $this->testToken->value(),
            'testRecipient' => trim($this->webmasterEmail->get()),
            'testResult'    => $testResult,
        ]);
    }
}
