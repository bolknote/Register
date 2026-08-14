<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\AdminYard;

use S2\AdminYard\Config\AdminConfig;
use S2\AdminYard\Config\FieldConfig;
use S2\AdminYard\MenuGenerator;
use S2\AdminYard\TemplateRenderer;
use S2\Cms\Model\PermissionChecker;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

readonly class CustomMenuGenerator extends MenuGenerator
{
    /** @var list<string> */
    private const array PRIMARY_ENTITY_ORDER = [
        'BlogPost',
        'Comment',
        'Article',
        'Tag',
        'Dashboard',
        'Config',
        'User',
    ];

    public function __construct(
        AdminConfig              $config,
        TemplateRenderer         $templateRenderer,
        private PermissionChecker        $permissionChecker,
        private EventDispatcherInterface $eventDispatcher,
        private RequestStack             $requestStack,
    ) {
        parent::__construct($config, $templateRenderer);
    }

    #[\Override]
    public function generateMainMenu(string $baseUrl, ?string $currentEntity = null): string
    {
        $request       = $this->requestStack->getCurrentRequest();
        $currentAction = $request instanceof \Symfony\Component\HttpFoundation\Request
            ? $request->query->getString('action')
            : null;
        $newPostActive = $currentEntity === 'BlogPost' && $currentAction === FieldConfig::ACTION_NEW;
        $links = $this->config->getPriorities();
        asort($links);

        $event = new CustomMenuGeneratorEvent(array_keys($links));
        $this->eventDispatcher->dispatch($event);
        $signals = $event->getSignals();

        foreach ($this->config->getEntities() as $entity) {
            $name = $entity->getName();
            if (!$entity->isAllowedAction(FieldConfig::ACTION_LIST)) {
                unset($links[$name]);
                continue;
            }

            $links[$name] = [
                'name'    => $entity->getPluralName(),
                'url'     => $baseUrl . '?entity=' . urlencode($name) . '&action=list',
                'active'  => $currentEntity === $name && ($name !== 'BlogPost' || !$newPostActive),
                'signals' => $signals[$name] ?? [],
            ];
        }

        foreach ($this->config->getServicePageNames() as $page) {
            $links[$page] = [
                'name'    => $this->config->getReadableName($page),
                'url'     => $baseUrl . '?entity=' . urlencode($page),
                'active'  => $currentEntity === $page,
                'signals' => $signals[$page] ?? [],
            ];
        }

        $primaryLinks = [];
        $postEntity   = $this->config->findEntityByName('BlogPost');
        if ($postEntity?->isAllowedAction(FieldConfig::ACTION_NEW) === true) {
            $primaryLinks['NewPost'] = [
                'name'    => 'New post',
                'url'     => $baseUrl . '?entity=BlogPost&action=new',
                'active'  => $newPostActive,
                'signals' => [],
            ];
        }

        foreach (self::PRIMARY_ENTITY_ORDER as $name) {
            if (isset($links[$name])) {
                $primaryLinks[$name] = $links[$name];
                unset($links[$name]);
            }

            if ($name === 'Tag') {
                $primaryLinks['Media'] = [
                    'name'    => 'Media',
                    'url'     => $baseUrl . 'pictman.php',
                    'active'  => false,
                    'signals' => [],
                ];
            }
        }

        $systemActive = false;
        foreach ($links as $link) {
            if (($link['active'] ?? false) === true) {
                $systemActive = true;
                break;
            }
        }

        return $this->templateRenderer->render($this->config->getMenuTemplate(), [
            'primaryLinks' => $primaryLinks,
            'systemLinks'  => $links,
            'systemActive' => $systemActive,
            'login'    => $this->permissionChecker->getUserLogin(),
            'userId'   => $this->permissionChecker->getUserId(),
            'seeUsers' => $this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW_HIDDEN),
        ]);
    }
}
