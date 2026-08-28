<?php

declare(strict_types = 1);

namespace Register\Admin;

use Psr\Log\LoggerInterface;
use Register\AdminYard\AdminPanel;
use Register\AdminYard\Config\AdminConfig;
use Register\AdminYard\Config\EntityConfig;
use Register\AdminYard\Database\PdoDataProvider;
use Register\AdminYard\Event\BeforeRenderEvent;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Transformer\ViewTransformer;
use Register\AdminYard\Translator;
use Register\Core\AdminYard\CustomMenuGenerator;
use Register\Core\AdminYard\BulkListActionProvider;
use Register\Core\AdminYard\SavedListViewManager;
use Register\Core\Framework\Container;
use Register\Core\Model\PermissionChecker;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Builds fresh AdminPanel instances per request to keep admin config and menu up to date.
 */
readonly class AdminPanelFactory
{
    public function __construct(private Container $container)
    {
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function create(): AdminPanel
    {
        $adminConfigProvider = $this->container->get(AdminConfigProvider::class);
        $adminConfig         = $adminConfigProvider->getAdminConfig();

        $eventDispatcher = new EventDispatcher();
        $this->registerEntityListeners($eventDispatcher, $adminConfig);
        $this->registerSavedListViewListeners($eventDispatcher, $adminConfig);

        $menuGenerator = new CustomMenuGenerator(
            $adminConfig,
            $this->container->get(TemplateRenderer::class),
            $this->container->get(PermissionChecker::class),
            $this->container->get(\Register\Core\Model\AuthManager::class),
            $this->container->get(EventDispatcherInterface::class),
            $this->container->get(RequestStack::class),
        );

        $adminPanel = new AdminPanel(
            $adminConfig,
            $eventDispatcher,
            $this->container->get(PdoDataProvider::class),
            new ViewTransformer(),
            $menuGenerator,
            $this->container->get(Translator::class),
            $this->container->get(TemplateRenderer::class),
            $this->container->get(FormFactory::class),
            $this->container->get(SettingStorageInterface::class),
        );
        $adminPanel->setLogger($this->container->get(LoggerInterface::class));

        return $adminPanel;
    }

    private function registerEntityListeners(EventDispatcher $eventDispatcher, AdminConfig $adminConfig): void
    {
        foreach ($adminConfig->getEntities() as $entityConfig) {
            foreach ($entityConfig->getListeners() as $eventName => $listeners) {
                foreach ($listeners as $listener) {
                    $eventDispatcher->addListener('adminyard.' . $eventName, $listener);
                }
            }
        }
    }

    private function registerSavedListViewListeners(EventDispatcher $eventDispatcher, AdminConfig $adminConfig): void
    {
        $manager = $this->container->get(SavedListViewManager::class);
        $bulkActionProvider = $this->container->get(BulkListActionProvider::class);
        foreach ($adminConfig->getEntities() as $entityConfig) {
            $entityName = $entityConfig->getName();
            $eventDispatcher->addListener(
                'adminyard.' . $entityName . '.' . EntityConfig::EVENT_BEFORE_LIST_RENDER,
                static function (BeforeRenderEvent $event) use ($entityName, $manager, $bulkActionProvider): void {
                    $filterData = $event->data['filterData'] ?? [];
                    if (!\is_array($filterData)) {
                        $filterData = [];
                    }

                    $sortField = $event->data['sortField'] ?? null;
                    if (!\is_string($sortField)) {
                        $sortField = null;
                    }

                    $sortDirection = $event->data['sortDirection'] ?? null;
                    if (!\is_string($sortDirection)) {
                        $sortDirection = null;
                    }

                    $state = $manager->createState($filterData, $sortField, $sortDirection);

                    $event->data['savedListViews']         = $manager->getViews($entityName);
                    $event->data['savedListViewState']     = $state;
                    $event->data['savedListViewCsrfToken'] = $manager->csrfToken($entityName);
                    $event->data['activeSavedListViewId']  = $manager->findMatchingViewId($entityName, $state);

                    $bulkActions = $bulkActionProvider->actionsFor($entityName);
                    if ($bulkActions !== []) {
                        $event->data['bulkListActions']   = $bulkActions;
                        $event->data['bulkListCsrfToken'] = $bulkActionProvider->csrfToken($entityName);
                    }
                },
            );
        }
    }
}
