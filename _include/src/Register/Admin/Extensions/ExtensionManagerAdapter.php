<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Admin\Extensions;

use Register\AdminYard\Config\AdminConfig;
use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Form\FormParams;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Translator;
use Register\Admin\AdminConfigExtenderInterface;
use Register\Core\Extensions\ExtensionManager;
use Register\Core\Framework\Exception\AccessDeniedException;
use Register\Core\Model\PermissionChecker;
use Register\Core\Security\Audit\SecurityAuditLogger;
use Register\Core\Security\Http\AdminMutationGuard;
use Psr\Cache\InvalidArgumentException;
use Register\Core\Pdo\DbLayerException;

readonly class ExtensionManagerAdapter implements AdminConfigExtenderInterface
{
    public function __construct(
        private ExtensionManager        $extensionManager,
        private PermissionChecker       $permissionChecker,
        private Translator              $translator,
        private SettingStorageInterface  $settingStorage,
        private TemplateRenderer        $templateRenderer,
        private SecurityAuditLogger      $securityAuditLogger,
    ) {
    }

    #[\Override]
    public function extend(AdminConfig $adminConfig): void
    {
        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW_HIDDEN)) {
            return;
        }

        $adminConfig
            ->setServicePage('SystemModules', fn(): string => $this->getBaseModuleList(), 60, $this->translator->trans('System modules'))
            ->setServicePage('Extension', fn(): string => $this->getExtensionList(), 61, $this->translator->trans('Optional modules'))
        ;
    }

    public function getBaseModuleList(): string
    {
        return $this->templateRenderer->render('_admin/templates/extension/base-modules.php.inc', [
            'baseModules' => $this->extensionManager->getBaseModules(),
        ]);
    }

    /**
     * @throws DbLayerException
     */
    public function getExtensionList(): string
    {
        return $this->templateRenderer->render('_admin/templates/extension/extension.php.inc', [
            ... $this->extensionManager->getExtensionList(),
            'csrfTokenGenerator' => $this->getCsrfToken(...),
        ]);
    }

    /**
     * @throws DbLayerException
     * @throws InvalidArgumentException
     * @return array<mixed>
     */
    public function installExtension(string $id, string $csrfToken): array
    {
        $id = $this->cleanupExtensionId($id);

        if (!AdminMutationGuard::tokensMatch($this->getCsrfToken($id), $csrfToken)) {
            $this->audit($id, 'install', SecurityAuditLogger::OUTCOME_DENIED);
            throw new AccessDeniedException('Invalid CSRF token!');
        }

        try {
            $errors = $this->extensionManager->installExtension($id);
            $this->audit(
                $id,
                'install',
                $errors === [] ? SecurityAuditLogger::OUTCOME_SUCCESS : SecurityAuditLogger::OUTCOME_FAILURE,
            );

            return $errors;
        } catch (\Throwable $throwable) {
            $this->audit($id, 'install', SecurityAuditLogger::OUTCOME_FAILURE);

            throw $throwable;
        }
    }

    /**
     * @throws DbLayerException
     */
    public function uninstallExtension(string $id, string $csrfToken): ?string
    {
        $id = $this->cleanupExtensionId($id);

        if (!AdminMutationGuard::tokensMatch($this->getCsrfToken($id), $csrfToken)) {
            $this->audit($id, 'uninstall', SecurityAuditLogger::OUTCOME_DENIED);
            throw new AccessDeniedException('Invalid CSRF token!');
        }

        try {
            $error = $this->extensionManager->uninstallExtension($id);
            $this->audit(
                $id,
                'uninstall',
                $error === null ? SecurityAuditLogger::OUTCOME_SUCCESS : SecurityAuditLogger::OUTCOME_FAILURE,
            );

            return $error;
        } catch (\Throwable $throwable) {
            $this->audit($id, 'uninstall', SecurityAuditLogger::OUTCOME_FAILURE);

            throw $throwable;
        }
    }

    /**
     * @throws DbLayerException
     */
    public function flipExtension(string $id, string $csrfToken): ?string
    {
        $id = $this->cleanupExtensionId($id);

        if (!AdminMutationGuard::tokensMatch($this->getCsrfToken($id), $csrfToken)) {
            $this->audit($id, 'toggle', SecurityAuditLogger::OUTCOME_DENIED);
            throw new AccessDeniedException('Invalid CSRF token!');
        }

        try {
            $error = $this->extensionManager->flipExtension($id);
            $this->audit(
                $id,
                'toggle',
                $error === null ? SecurityAuditLogger::OUTCOME_SUCCESS : SecurityAuditLogger::OUTCOME_FAILURE,
            );

            return $error;
        } catch (\Throwable $throwable) {
            $this->audit($id, 'toggle', SecurityAuditLogger::OUTCOME_FAILURE);

            throw $throwable;
        }
    }

    private function getCsrfToken(string $id): string
    {
        // This token is used for every action in the extension actions.
        // I chose to use ACTION_DELETE since then it would be compatible with the AdminYard delete token.
        $formParams = new FormParams('Extension', [], $this->settingStorage, FieldConfig::ACTION_DELETE, ['id' => $id]);

        return $formParams->getCsrfToken();
    }

    private function cleanupExtensionId(string $id): string
    {
        return preg_replace('/[^0-9a-z_]/', '', $id)
            ?? throw new \RuntimeException('Unable to normalize the extension identifier.');
    }

    private function audit(string $id, string $action, string $outcome): void
    {
        $userId = $this->permissionChecker->getUserId();
        if ($userId !== null && $id !== '') {
            $this->securityAuditLogger->extensionChanged($userId, $id, $action, $outcome);
        }
    }
}
