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
use S2\Cms\Model\AuthManager;
use S2\Cms\Model\PermissionChecker;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

readonly class CustomMenuGenerator extends MenuGenerator
{
    /** @var list<string> */
    private const array MATERIAL_ENTITY_ORDER = [
        'BlogPost',
        'Article',
        'Media',
        'Tag',
    ];

    /** @var list<string> */
    private const array MODERATION_ENTITY_ORDER = [
        'Comment',
        'SpamAssessment',
        'SpamRule',
        'SpamSignalPolicy',
        'SpamRatePolicy',
    ];

    /** @var list<string> */
    private const array SETTINGS_ENTITY_ORDER = [
        'Config',
        'SystemStatus',
        'LinkHealth',
        'SystemModules',
        'Extension',
        'Queue',
    ];

    public function __construct(
        AdminConfig              $config,
        TemplateRenderer         $templateRenderer,
        private PermissionChecker        $permissionChecker,
        private AuthManager              $authManager,
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
            : '';
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
                'key'     => $name,
                'name'    => $entity->getPluralName(),
                'url'     => $baseUrl . '?entity=' . urlencode($name) . '&action=list',
                'active'  => $currentEntity === $name && ($name !== 'BlogPost' || !$newPostActive),
                'signals' => $signals[$name] ?? [],
            ];
        }

        foreach ($this->config->getServicePageNames() as $page) {
            $links[$page] = [
                'key'     => $page,
                'name'    => $this->config->getReadableName($page),
                'url'     => $baseUrl . '?entity=' . urlencode($page),
                'active'  => $currentEntity === $page,
                'signals' => $signals[$page] ?? [],
            ];
        }

        $navigationItems = [];
        $postEntity   = $this->config->findEntityByName('BlogPost');
        if ($postEntity?->isAllowedAction(FieldConfig::ACTION_NEW) === true) {
            $navigationItems[] = [
                'kind'    => 'link',
                'key'     => 'NewPost',
                'name'    => 'New post',
                'url'     => $baseUrl . '?entity=BlogPost&action=new',
                'active'  => $newPostActive,
                'signals' => [],
            ];
        }

        if (isset($links['Dashboard'])) {
            $navigationItems[] = ['kind' => 'link', ...$links['Dashboard']];
            unset($links['Dashboard']);
        }

        // Pages are one user-facing section with list and tree views. The tree
        // remains a service page internally, but it must not become a separate
        // navigation concept.
        if (isset($links['Article']) && $currentEntity === 'Site') {
            $links['Article']['active'] = true;
        }
        unset($links['Site']);

        $navigationItems[] = $this->createGroup(
            'Materials',
            'Materials',
            $this->extractLinks($links, self::MATERIAL_ENTITY_ORDER),
        );
        $moderationLinks = $this->extractLinks($links, self::MODERATION_ENTITY_ORDER);
        foreach ($moderationLinks as &$moderationLink) {
            if (\in_array($moderationLink['key'], ['SpamSignalPolicy', 'SpamRatePolicy'], true)) {
                $moderationLink['menuHidden']  = true;
                $moderationLink['currentName'] = 'Expert settings';
            }
        }
        unset($moderationLink);
        $navigationItems[] = $this->createGroup('Moderation', 'Moderation', $moderationLinks);

        if (isset($links['Statistics'])) {
            $navigationItems[] = ['kind' => 'link', ...$links['Statistics']];
            unset($links['Statistics']);
        }

        $accountLinks = [];
        $userLink     = $links['User'] ?? null;
        $sessionLink  = $links['Session'] ?? null;
        unset($links['User'], $links['Session']);

        $currentUserId = $this->permissionChecker->getUserId();
        $userEntity    = $this->config->findEntityByName('User');
        $profileActive = $currentEntity === 'User'
            && $currentAction === FieldConfig::ACTION_EDIT
            && $request->query->getInt('id') === $currentUserId;
        if ($currentUserId !== null && $userEntity?->isAllowedAction(FieldConfig::ACTION_EDIT) === true) {
            $accountLinks[] = [
                'key'     => 'Profile',
                'name'    => 'Profile',
                'url'     => $baseUrl . '?entity=User&action=edit&id=' . $currentUserId,
                'active'  => $profileActive,
                'signals' => [],
            ];
        }
        if (\is_array($userLink)) {
            $userLink['active'] = $currentEntity === 'User' && !$profileActive;
            $accountLinks[]     = $userLink;
        }
        if (\is_array($sessionLink)) {
            $accountLinks[] = $sessionLink;
        }

        $settingsLinks = [
            ...$this->extractLinks($links, self::SETTINGS_ENTITY_ORDER),
            ...array_values($links),
        ];
        if ($settingsLinks !== []) {
            $navigationItems[] = $this->createGroup('Settings', 'Settings', $settingsLinks);
        }

        $navigationItems = array_values(array_filter(
            $navigationItems,
            static fn(array $item): bool => $item['kind'] !== 'group' || $item['links'] !== [],
        ));

        return $this->templateRenderer->render($this->config->getMenuTemplate(), [
            'navigationItems' => $navigationItems,
            'accountGroup'    => $this->createGroup(
                'Account',
                (string)$this->permissionChecker->getUserLogin(),
                $accountLinks,
            ),
            'logoutUrl'       => $baseUrl . '?action=logout',
            'logoutCsrfToken' => $this->authManager->getLogoutCsrfToken(),
            'userId'          => $currentUserId,
        ]);
    }

    /**
     * @param array<string, array<mixed>> $links
     * @param list<string>                $order
     * @return list<array<mixed>>
     */
    private function extractLinks(array &$links, array $order): array
    {
        $result = [];
        foreach ($order as $name) {
            if (!isset($links[$name])) {
                continue;
            }

            $result[] = $links[$name];
            unset($links[$name]);
        }

        return $result;
    }

    /**
     * @param list<array<mixed>> $links
     * @return array<mixed>
     */
    private function createGroup(string $key, string $name, array $links): array
    {
        $activeLink = null;
        foreach ($links as $link) {
            if (($link['active'] ?? false) === true) {
                $activeLink = $link;
                break;
            }
        }

        return [
            'kind'        => 'group',
            'key'         => $key,
            'name'        => $name,
            'active'      => $activeLink !== null,
            'currentName' => $activeLink['currentName'] ?? $activeLink['name'] ?? null,
            'links'       => $links,
        ];
    }
}
