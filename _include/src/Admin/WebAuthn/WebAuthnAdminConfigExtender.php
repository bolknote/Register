<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Admin\WebAuthn;

use S2\AdminYard\Config\AdminConfig;
use S2\AdminYard\TemplateRenderer;
use S2\Cms\Admin\AdminConfigExtenderInterface;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Security\WebAuthn\RecoveryCodeRepository;
use S2\Cms\Security\WebAuthn\WebAuthnCredentialRepository;

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
