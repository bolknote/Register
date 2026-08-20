<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Admin\Controller;

use Register\Comment\CommentRepository;
use Register\Live\LiveUpdateRepository;
use S2\AdminYard\Config\EntityConfig;
use S2\AdminYard\Controller\ControllerFactoryInterface;
use S2\AdminYard\Database\PdoDataProvider;
use S2\AdminYard\Form\FormFactory;
use S2\AdminYard\SettingStorage\SettingStorageInterface;
use S2\AdminYard\TemplateRenderer;
use S2\AdminYard\Transformer\ViewTransformer;
use S2\AdminYard\Translator;
use S2\Cms\Comment\Antispam\SpamFeedbackService;
use S2\Cms\Security\Http\AdminMutationGuard;
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
    ): \S2\Cms\Admin\Controller\CommentController {
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
