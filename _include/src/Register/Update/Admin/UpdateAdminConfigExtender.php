<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update\Admin;

use Register\Update\ArchiveCapabilities;
use Register\Update\ReleaseManifest;
use Register\Update\UpdateDirectoryResolver;
use Register\Update\UpdateManager;
use Register\Update\UpdateStorage;
use Register\AdminYard\Config\AdminConfig;
use Register\AdminYard\TemplateRenderer;
use Register\Core\Admin\AdminConfigExtenderInterface;
use Register\Core\Model\PermissionChecker;

final readonly class UpdateAdminConfigExtender implements AdminConfigExtenderInterface
{
    public function __construct(
        private PermissionChecker    $permissionChecker,
        private TemplateRenderer     $templateRenderer,
        private UpdateManager        $updateManager,
        private UpdateToken          $updateToken,
        private ArchiveCapabilities  $archiveCapabilities,
        private string               $applicationRoot,
        private string               $publicRoot,
    ) {
    }

    #[\Override]
    public function extend(AdminConfig $adminConfig): void
    {
        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_USERS)) {
            return;
        }

        $adminConfig->setServicePage('Update', fn(): string => $this->render(), 56, 'Software update');
    }

    private function render(): string
    {
        $installed  = $this->updateManager->installedManifest();
        $formats    = $this->archiveCapabilities->formats();
        $applicationRoot = realpath($this->applicationRoot);
        $publicRoot = realpath($this->publicRoot);
        $splitLayout = $applicationRoot !== false
            && $publicRoot !== false
            && $applicationRoot !== $publicRoot;
        $writableLayout = $applicationRoot !== false
            && $publicRoot !== false
            && $applicationRoot !== $publicRoot
            && is_writable($applicationRoot)
            && is_writable($publicRoot);
        $updateDirectory = UpdateDirectoryResolver::resolve($this->applicationRoot);
        $storageWritable = is_dir($updateDirectory)
            ? !is_link($updateDirectory) && is_writable($updateDirectory)
            : is_writable(dirname($updateDirectory));

        return $this->templateRenderer->render('_admin/templates/update.php.inc', [
            'installed'       => $installed,
            'formats'         => $formats,
            'preferredFormat' => $this->archiveCapabilities->preferredFormat(),
            'available'       => $installed instanceof ReleaseManifest
                && $splitLayout
                && $writableLayout
                && $storageWritable
                && $this->archiveCapabilities->preferredFormat() !== null,
            'splitLayout'     => $splitLayout,
            'writableLayout'  => $writableLayout,
            'storageWritable' => $storageWritable,
            'csrfToken'       => $this->updateToken->value(),
            'chunkBytes'      => UpdateStorage::CHUNK_BYTES,
            'maxArchiveBytes' => UpdateStorage::MAX_ARCHIVE_BYTES,
            'recoverable'     => $this->updateManager->recoverable(),
        ]);
    }
}
