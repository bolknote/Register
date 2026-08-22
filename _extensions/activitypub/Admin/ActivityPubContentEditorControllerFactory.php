<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Admin;

use S2\AdminYard\Config\EntityConfig;
use S2\AdminYard\Controller\ControllerFactoryInterface;
use S2\AdminYard\Database\PdoDataProvider;
use S2\AdminYard\Form\FormFactory;
use S2\AdminYard\SettingStorage\SettingStorageInterface;
use S2\AdminYard\TemplateRenderer;
use S2\AdminYard\Transformer\ViewTransformer;
use S2\AdminYard\Translator;
use s2_extensions\activitypub\Infrastructure\PortableDatabaseTransaction;
use Symfony\Component\EventDispatcher\EventDispatcher;

final readonly class ActivityPubContentEditorControllerFactory implements ControllerFactoryInterface
{
    public function __construct(private PortableDatabaseTransaction $transaction)
    {
    }

    #[\Override]
    public function create(
        EntityConfig            $entityConfig,
        EventDispatcher         $eventDispatcher,
        PdoDataProvider         $dataProvider,
        ViewTransformer         $viewTransformer,
        Translator              $translator,
        TemplateRenderer        $templateRenderer,
        FormFactory             $formFactory,
        SettingStorageInterface $settingStorage,
    ): ActivityPubContentEditorController {
        return new ActivityPubContentEditorController(
            $entityConfig,
            $eventDispatcher,
            $dataProvider,
            $viewTransformer,
            $translator,
            $templateRenderer,
            $formFactory,
            $settingStorage,
            $this->transaction,
        );
    }
}
