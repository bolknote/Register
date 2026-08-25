<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram\Admin;

use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Translator;
use Register\Import\ExternalImportMapRepository;

final readonly class TelegramImportAdminPage
{
    public function __construct(
        private ExternalImportMapRepository $mapRepository,
        private TelegramImportToken         $token,
        private TemplateRenderer            $templateRenderer,
        private Translator                  $translator,
        private string                      $basePath,
    ) {
    }

    public function title(): string
    {
        return $this->translator->trans('Telegram import');
    }

    public function render(): string
    {
        return $this->templateRenderer->render(__DIR__ . '/../resources/views/admin.php.inc', [
            'csrfToken'    => $this->token->value(),
            'importedCount' => $this->mapRepository->count('telegram'),
            'basePath'     => $this->basePath,
        ]);
    }
}
