<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Auth\PublicAuthSchema;
use Register\Core\Model\SessionAudience;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\SchemaBuilderInterface;

/** Separates public-site sessions from sessions accepted by administrative entry points. */
final readonly class SessionAudienceSchemaMigration implements SchemaMigrationInterface
{
    #[\Override]
    public function fromGeneration(): int
    {
        return 25;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 26;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        $dbLayer->addField(
            'users_online',
            'audience',
            SchemaBuilderInterface::TYPE_STRING,
            12,
            false,
            SessionAudience::ADMIN->value,
            'login',
        );

        // Older releases issued an admin-path cookie for all public authentication methods.
        // Existing sessions belonging to an external identity must not inherit the default
        // administrative audience during the upgrade.
        $externalLogins = $dbLayer
            ->select('DISTINCT u.login')
            ->from('users AS u')
            ->innerJoin(PublicAuthSchema::IDENTITIES_TABLE . ' AS identity', 'identity.user_id = u.id')
            ->execute()
            ->fetchColumn()
        ;
        foreach ($externalLogins as $login) {
            if (!\is_string($login) || $login === '') {
                continue;
            }

            $dbLayer
                ->update('users_online')
                ->set('audience', ':audience')->setParameter('audience', SessionAudience::PUBLIC->value)
                ->where('login = :login')->setParameter('login', $login)
                ->execute()
            ;
        }
    }
}
