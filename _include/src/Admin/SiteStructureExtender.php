<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin;

use Register\AdminYard\Config\AdminConfig;
use Register\AdminYard\TemplateRenderer;

readonly class SiteStructureExtender implements AdminConfigExtenderInterface
{
    public function __construct(
        private TemplateRenderer $templateRenderer
    ) {
    }

    #[\Override]
    public function extend(AdminConfig $adminConfig): void
    {
        $adminConfig
            ->setServicePage(
                'Site',
                fn(): string => $this->templateRenderer->render('_admin/templates/structure/structure.php.inc'),
                -10,
                'Page structure',
            )
        ;
    }
}
