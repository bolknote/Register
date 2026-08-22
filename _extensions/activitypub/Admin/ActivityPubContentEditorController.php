<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Admin;

use S2\AdminYard\Config\EntityConfig;
use S2\AdminYard\Controller\EntityController;
use S2\AdminYard\Database\PdoDataProvider;
use S2\AdminYard\Form\FormFactory;
use S2\AdminYard\SettingStorage\SettingStorageInterface;
use S2\AdminYard\TemplateRenderer;
use S2\AdminYard\Transformer\ViewTransformer;
use S2\AdminYard\Translator;
use s2_extensions\activitypub\Infrastructure\PortableDatabaseTransaction;
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
        private readonly PortableDatabaseTransaction $transaction,
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
