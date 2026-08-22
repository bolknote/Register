<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

enum ActorType: string
{
    case SERVICE = 'Service';
    case ORGANIZATION = 'Organization';
    case PERSON = 'Person';

    public function isAllowedFor(ActorKind $kind): bool
    {
        return match ($kind) {
            ActorKind::SITE   => $this === self::SERVICE || $this === self::ORGANIZATION,
            ActorKind::AUTHOR => $this === self::PERSON,
        };
    }
}
