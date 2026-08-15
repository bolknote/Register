<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Admin\Picture;

use S2\AdminYard\Config\AdminConfig;
use S2\AdminYard\TemplateRenderer;
use S2\Cms\Admin\AdminConfigExtenderInterface;
use S2\Cms\Model\PermissionChecker;

final readonly class MediaConfigExtender implements AdminConfigExtenderInterface
{
    public function __construct(
        private PermissionChecker $permissionChecker,
        private TemplateRenderer  $templateRenderer,
        private string            $imagePath,
    ) {
    }

    #[\Override]
    public function extend(AdminConfig $adminConfig): void
    {
        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW)) {
            return;
        }

        $adminConfig->setServicePage(
            'Media',
            fn(): string => $this->templateRenderer->render(
                '_admin/templates/picture-manager-content.php.inc',
                [
                    'imagePath' => $this->imagePath,
                    'standalone' => false,
                ],
            ),
            -8,
            'Media',
        );
    }
}
