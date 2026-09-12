<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Admin;

use Register\AdminYard\Config\EntityConfig;
use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Controller\ControllerFactoryInterface;
use Register\AdminYard\Database\PdoDataProvider;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Transformer\ViewTransformer;
use Register\AdminYard\Translator;
use Register\Url\UrlHistoryService;
use Symfony\Component\EventDispatcher\EventDispatcher;

final readonly class ActivityPubContentEditorControllerFactory implements ControllerFactoryInterface
{
    public function __construct(private UrlHistoryService $transaction)
    {
    }

    /** List-only content uses its own public editing flow, so it needs no admin write transaction. */
    public function configure(EntityConfig $entity): void
    {
        if (!$entity->isAllowedAction(FieldConfig::ACTION_EDIT) && !$entity->isAllowedAction(FieldConfig::ACTION_NEW)) {
            return;
        }

        if ($entity->getControllerClassOrFactory() !== null) {
            throw new \LogicException('ActivityPub cannot compose with the configured content editor controller.');
        }

        $entity->setControllerClassOrFactory($this);
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
