<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   strikerstatapi
 */

declare(strict_types=1);

namespace Register\AdminYard\Controller;

use Register\AdminYard\Config\EntityConfig;
use Register\AdminYard\Database\PdoDataProvider;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Transformer\ViewTransformer;
use Register\AdminYard\Translator;
use Symfony\Component\EventDispatcher\EventDispatcher;

readonly class DefaultControllerFactory implements ControllerFactoryInterface
{
    public function __construct(public string $controllerClass)
    {
    }

    public function create(
        EntityConfig            $entityConfig,
        EventDispatcher         $eventDispatcher,
        PdoDataProvider         $dataProvider,
        ViewTransformer         $viewTransformer,
        Translator              $translator,
        TemplateRenderer        $templateRenderer,
        FormFactory             $formFactory,
        SettingStorageInterface $settingStorage,
    ): EntityController {
        return new ($this->controllerClass)(
            $entityConfig,
            $eventDispatcher,
            $dataProvider,
            $viewTransformer,
            $translator,
            $templateRenderer,
            $formFactory,
            $settingStorage,
        );
    }
}
