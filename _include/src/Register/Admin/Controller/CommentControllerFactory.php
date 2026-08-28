<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Admin\Controller;

use Register\Comment\CommentRepository;
use Register\Live\LiveUpdateRepository;
use Register\AdminYard\Config\EntityConfig;
use Register\AdminYard\Controller\ControllerFactoryInterface;
use Register\AdminYard\Database\PdoDataProvider;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Transformer\ViewTransformer;
use Register\AdminYard\Translator;
use Register\Comment\Antispam\SpamFeedbackService;
use Register\Core\Security\Http\AdminMutationGuard;
use Symfony\Component\EventDispatcher\EventDispatcher;

final readonly class CommentControllerFactory implements ControllerFactoryInterface
{
    public function __construct(
        private SpamFeedbackService $spamFeedbackService,
        private AdminMutationGuard  $mutationGuard,
        private CommentRepository   $commentRepository,
        private LiveUpdateRepository $liveUpdateRepository,
    ) {
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
    ): \Register\Admin\Controller\CommentController {
        return new CommentController(
            $entityConfig,
            $eventDispatcher,
            $dataProvider,
            $viewTransformer,
            $translator,
            $templateRenderer,
            $formFactory,
            $settingStorage,
            $this->spamFeedbackService,
            $this->mutationGuard,
            $this->commentRepository,
            $this->liveUpdateRepository,
        );
    }
}
