<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Admin;

use Register\AdminYard\Config\EntityConfig;
use Register\AdminYard\Controller\EntityController;
use Register\AdminYard\Database\PdoDataProvider;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Transformer\ViewTransformer;
use Register\AdminYard\Translator;
use Register\Url\UrlHistoryService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runs editorial writes and their ActivityPub projection inside one portable DB transaction. */
final class ActivityPubContentEditorController extends EntityController
{
    public function __construct(
        EntityConfig            $entityConfig,
        EventDispatcher         $eventDispatcher,
        PdoDataProvider         $dataProvider,
        ViewTransformer         $viewTransformer,
        Translator              $translator,
        TemplateRenderer        $templateRenderer,
        FormFactory             $formFactory,
        SettingStorageInterface $settingStorage,
        private readonly UrlHistoryService $transaction,
    ) {
        parent::__construct(
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

    #[\Override]
    public function editAction(Request $request): string|Response
    {
        if ($request->getRealMethod() !== Request::METHOD_POST) {
            return parent::editAction($request);
        }

        // Acquire the URL mutex before form/before-save reads establish a MySQL RR snapshot.
        // This outer transaction still includes both the content write and its AP projection.
        return $this->transaction->run(fn(): string|Response => parent::editAction($request));
    }

    #[\Override]
    public function newAction(Request $request): string|Response
    {
        if ($request->getRealMethod() !== Request::METHOD_POST) {
            return parent::newAction($request);
        }

        return $this->transaction->run(fn(): string|Response => parent::newAction($request));
    }
}
