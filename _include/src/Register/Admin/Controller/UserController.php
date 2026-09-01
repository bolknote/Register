<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Admin\Controller;

use Register\AdminYard\Controller\EntityController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keeps the user directory focused on one human-readable account group. */
final class UserController extends EntityController
{
    public const string ACCOUNT_TYPE_TEAM = 'team';

    public const string ACCOUNT_TYPE_GUEST = 'guest';

    public const string ACCOUNT_TYPE_IMPORTED = 'imported';

    private const array ACCOUNT_TYPES = [
        self::ACCOUNT_TYPE_TEAM,
        self::ACCOUNT_TYPE_GUEST,
        self::ACCOUNT_TYPE_IMPORTED,
    ];

    #[\Override]
    public function listAction(Request $request): string|Response
    {
        $accountType = $request->query->get('account_type');
        if (!\is_string($accountType) || !\in_array($accountType, self::ACCOUNT_TYPES, true)) {
            $request->query->set('apply_filter', '1');
            $request->query->set('account_type', self::ACCOUNT_TYPE_TEAM);
        }

        return parent::listAction($request);
    }
}
