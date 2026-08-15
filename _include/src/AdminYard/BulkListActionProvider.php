<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\AdminYard;

use S2\AdminYard\Form\FormParams;
use S2\AdminYard\SettingStorage\SettingStorageInterface;
use S2\Cms\Model\PermissionChecker;

final readonly class BulkListActionProvider
{
    public const string ACTION_DELETE = 'delete';

    public const string ACTION_HAM = 'ham';

    public const string ACTION_PUBLISH = 'publish';

    public const string ACTION_REJECT = 'reject';

    public const string ACTION_SPAM = 'spam';

    public const string ACTION_UNPUBLISH = 'unpublish';

    public function __construct(
        private SettingStorageInterface $settingStorage,
        private PermissionChecker        $permissionChecker,
    ) {
    }

    /** @return list<string> */
    public function actionsFor(string $entityName): array
    {
        $canEditContent = $this->permissionChecker->isGrantedAny(
            PermissionChecker::PERMISSION_CREATE_ARTICLES,
            PermissionChecker::PERMISSION_EDIT_SITE,
        );

        return match ($entityName) {
            'Article' => $canEditContent
                ? [self::ACTION_PUBLISH, self::ACTION_UNPUBLISH]
                : [],
            'BlogPost' => $canEditContent
                ? [self::ACTION_PUBLISH, self::ACTION_UNPUBLISH, self::ACTION_DELETE]
                : [],
            'Comment' => [
                ...$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_HIDE_COMMENTS)
                    ? [self::ACTION_HAM, self::ACTION_SPAM, self::ACTION_REJECT]
                    : [],
                ...$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_COMMENTS)
                    ? [self::ACTION_DELETE]
                    : [],
            ],
            default => [],
        };
    }

    public function csrfToken(string $entityName): string
    {
        $this->assertSupportedEntity($entityName);

        return (new FormParams(
            'BulkListAction',
            [],
            $this->settingStorage,
            'execute',
            ['entity' => $entityName],
        ))->getCsrfToken();
    }

    public function csrfTokenMatches(string $entityName, string $token): bool
    {
        return $token !== '' && hash_equals($this->csrfToken($entityName), $token);
    }

    public function isAllowed(string $entityName, string $action): bool
    {
        return \in_array($action, $this->actionsFor($entityName), true);
    }

    private function assertSupportedEntity(string $entityName): void
    {
        if (!\in_array($entityName, ['Article', 'BlogPost', 'Comment'], true)) {
            throw new \InvalidArgumentException('Bulk actions are not supported for this section.');
        }
    }
}
