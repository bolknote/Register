<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Admin;

use Register\AdminYard\Config\EntityConfig;
use Register\AdminYard\Controller\ControllerFactoryInterface;
use Register\AdminYard\Database\PdoDataProvider;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Transformer\ViewTransformer;
use Register\AdminYard\Translator;
use Register\Core\Model\PermissionChecker;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\Inplace\PostInplaceMediaStorage;
use Register\Module\Blog\Inplace\PostMediaRepository;
use Register\Url\ContentUrlGenerator;
use Symfony\Component\EventDispatcher\EventDispatcher;

final readonly class BlogPostListControllerFactory implements ControllerFactoryInterface
{
    public function __construct(
        private ContentUrlGenerator $urls,
        private BlogUrlBuilder $blogUrls,
        private PermissionChecker $permissions,
        private \PDO $pdo,
        private PostMediaRepository $mediaRepository,
        private PostInplaceMediaStorage $mediaStorage,
    ) {
    }

    #[\Override]
    public function create(
        EntityConfig $entityConfig,
        EventDispatcher $eventDispatcher,
        PdoDataProvider $dataProvider,
        ViewTransformer $viewTransformer,
        Translator $translator,
        TemplateRenderer $templateRenderer,
        FormFactory $formFactory,
        SettingStorageInterface $settingStorage,
    ): BlogPostListController {
        return new BlogPostListController(
            $entityConfig,
            $eventDispatcher,
            $dataProvider,
            $viewTransformer,
            $translator,
            $templateRenderer,
            $formFactory,
            $settingStorage,
            $this->urls,
            $this->blogUrls,
            $this->permissions,
            $this->pdo,
            $this->mediaRepository,
            $this->mediaStorage,
        );
    }
}
