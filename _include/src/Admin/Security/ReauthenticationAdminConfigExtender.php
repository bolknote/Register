<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Admin\Security;

use S2\AdminYard\Config\AdminConfig;
use S2\AdminYard\Config\EntityConfig;
use S2\AdminYard\Config\FieldConfig;
use S2\AdminYard\Config\VirtualFieldType;
use S2\AdminYard\Database\Key;
use S2\AdminYard\Event\BeforeSaveEvent;
use S2\AdminYard\Translator;
use S2\Cms\Admin\AdminConfigExtenderInterface;
use S2\Cms\Admin\DynamicConfigFormBuilder;
use S2\Cms\Model\AuthManager;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** Enforces a fresh password check at the server boundary for sensitive admin writes. */
final readonly class ReauthenticationAdminConfigExtender implements AdminConfigExtenderInterface
{
    private const string CURRENT_PASSWORD_FIELD = 'current_password';

    private const array USER_SENSITIVE_FIELDS = [
        'login',
        'password',
        'view',
        'view_hidden',
        'hide_comments',
        'edit_comments',
        'create_articles',
        'edit_site',
        'edit_users',
    ];

    public function __construct(
        private AuthManager              $authManager,
        private DynamicConfigFormBuilder $dynamicConfigFormBuilder,
        private RequestStack             $requestStack,
        private Translator               $translator,
    ) {
    }

    #[\Override]
    public function extend(AdminConfig $adminConfig): void
    {
        $userEntity = $adminConfig->findEntityByName('User');
        if ($userEntity instanceof EntityConfig) {
            $this->protectUserChanges($userEntity);
        }

        $configEntity = $adminConfig->findEntityByName('Config');
        if ($configEntity instanceof EntityConfig) {
            $configEntity->addListener(
                EntityConfig::EVENT_BEFORE_PATCH,
                function (BeforeSaveEvent $event): void {
                    $parameter = $event->primaryKey?->toArray()['name'] ?? null;
                    if (\is_string($parameter) && $this->dynamicConfigFormBuilder->isSecretParameter($parameter)) {
                        $this->verifyCurrentPassword($event);
                    }
                },
            );
        }
    }

    private function protectUserChanges(EntityConfig $userEntity): void
    {
        $userEntity->addField(new FieldConfig(
            name: self::CURRENT_PASSWORD_FIELD,
            label: $this->translator->trans('Current password'),
            hint: $this->translator->trans('Current password reauthentication help'),
            type: new VirtualFieldType("''"),
            control: 'password',
            useOnActions: [FieldConfig::ACTION_NEW, FieldConfig::ACTION_EDIT],
        ), 'password');
        $userEntity->addListener(
            [
                EntityConfig::EVENT_BEFORE_CREATE,
                EntityConfig::EVENT_BEFORE_PATCH,
                EntityConfig::EVENT_BEFORE_UPDATE,
            ],
            function (BeforeSaveEvent $event): void {
                $requiresReauthentication = !$event->primaryKey instanceof Key
                    || $this->hasSensitiveUserChanges($event);
                if ($requiresReauthentication) {
                    $this->verifyCurrentPassword($event);
                } else {
                    unset($event->data[self::CURRENT_PASSWORD_FIELD]);
                }
            },
        );
    }

    private function hasSensitiveUserChanges(BeforeSaveEvent $event): bool
    {
        $changedFields = $event->context['security_changed_fields'] ?? null;
        if (!\is_array($changedFields)) {
            $changedFields = array_keys($event->data);
        }

        foreach ($changedFields as $field) {
            if (\is_string($field) && \in_array($field, self::USER_SENSITIVE_FIELDS, true)) {
                return true;
            }
        }

        return false;
    }

    private function verifyCurrentPassword(BeforeSaveEvent $event): void
    {
        $request  = $this->requestStack->getCurrentRequest();
        $password = $request instanceof Request ? $this->currentPassword($request) : '';
        unset($event->data[self::CURRENT_PASSWORD_FIELD]);

        if (!$request instanceof Request || !$this->authManager->verifyCurrentPassword($request, $password)) {
            $event->errorMessages[] = $this->translator->trans('Invalid current password');
        }
    }

    private function currentPassword(Request $request): string
    {
        try {
            return $request->request->getString(self::CURRENT_PASSWORD_FIELD);
        } catch (BadRequestException) {
            return '';
        }
    }
}
