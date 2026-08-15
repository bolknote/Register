<?php

declare(strict_types = 1);

namespace S2\Cms\Admin;

use Psr\Log\LoggerInterface;
use S2\AdminYard\AdminPanel;
use S2\AdminYard\Config\AdminConfig;
use S2\AdminYard\Config\EntityConfig;
use S2\AdminYard\Database\PdoDataProvider;
use S2\AdminYard\Event\BeforeRenderEvent;
use S2\AdminYard\Form\FormFactory;
use S2\AdminYard\SettingStorage\SettingStorageInterface;
use S2\AdminYard\TemplateRenderer;
use S2\AdminYard\Transformer\ViewTransformer;
use S2\AdminYard\Translator;
use S2\Cms\AdminYard\CustomMenuGenerator;
use S2\Cms\AdminYard\SavedListViewManager;
use S2\Cms\Framework\Container;
use S2\Cms\Model\PermissionChecker;
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
        foreach ($adminConfig->getEntities() as $entityConfig) {
            $entityName = $entityConfig->getName();
            $eventDispatcher->addListener(
                'adminyard.' . $entityName . '.' . EntityConfig::EVENT_BEFORE_LIST_RENDER,
                static function (BeforeRenderEvent $event) use ($entityName, $manager): void {
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
                },
            );
        }
    }
}
