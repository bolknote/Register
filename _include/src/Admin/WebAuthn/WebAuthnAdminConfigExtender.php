<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin\WebAuthn;

use Register\AdminYard\Config\AdminConfig;
use Register\AdminYard\TemplateRenderer;
use Register\Core\Admin\AdminConfigExtenderInterface;
use Register\Core\Model\PermissionChecker;
use Register\Core\Security\WebAuthn\RecoveryCodeRepository;
use Register\Core\Security\WebAuthn\WebAuthnCredentialRepository;

final readonly class WebAuthnAdminConfigExtender implements AdminConfigExtenderInterface
{
    public function __construct(
        private PermissionChecker            $permissionChecker,
        private WebAuthnCredentialRepository $credentialRepository,
        private RecoveryCodeRepository       $recoveryCodeRepository,
        private WebAuthnAdminController       $controller,
        private TemplateRenderer              $templateRenderer,
    ) {
    }

    #[\Override]
    public function extend(AdminConfig $adminConfig): void
    {
        $userId = $this->permissionChecker->getUserId();
        if ($userId === null || !$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW)) {
            return;
        }

        $adminConfig->setServicePage('Security', fn(): string => $this->templateRenderer->render(
            '_admin/templates/security/passkeys.php.inc',
            [
                'passkeysAvailable'  => $this->controller->isAvailable(),
                'credentials'        => $this->credentialRepository->forUser($userId),
                'recoveryStatus'     => $this->recoveryCodeRepository->status($userId),
                'registerCsrfToken'  => $this->controller->registerCsrfToken(),
                'deleteCsrfToken'    => $this->controller->deleteCsrfToken(),
                'recoveryCsrfToken'  => $this->controller->recoveryCsrfToken(),
            ],
        ), 100, 'Security');
    }
}
