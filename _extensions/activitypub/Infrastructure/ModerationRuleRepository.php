<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

use S2\Cms\Pdo\DbLayer;
use s2_extensions\activitypub\Domain\ModerationAction;
use s2_extensions\activitypub\Domain\RemoteActor;

/** Deterministic database-backed actor/origin/domain moderation policy. */
final readonly class ModerationRuleRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function decision(RemoteActor $actor): ModerationAction
    {
        $parts = parse_url($actor->actorUrl);
        $host  = \is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        $port  = \is_array($parts) && isset($parts['port']) ? $parts['port'] : 443;
        $origin = $host === '' ? '' : 'https://' . $host . ($port === 443 ? '' : ':' . $port);
        $candidates = [
            'actor'  => $actor->actorUrl,
            'origin' => $origin,
            'domain' => $host,
        ];
        $rows = $this->dbLayer->select('scope', 'match_hash', 'match_value', 'action')
            ->from(ActivityPubSchema::MODERATION_RULE_TABLE)
            ->where('enabled = 1')
            ->orderBy('priority DESC, id DESC')
            ->execute()
            ->fetchAssocAll()
        ;
        foreach ($rows as $row) {
            $scope = (string)$row['scope'];
            $expected = $candidates[$scope] ?? null;
            if ($expected === null
                || !hash_equals(hash('sha256', $expected), (string)$row['match_hash'])
                || !hash_equals($expected, (string)$row['match_value'])
            ) {
                continue;
            }

            try {
                return ModerationAction::from((string)$row['action']);
            } catch (\ValueError $exception) {
                throw new \RuntimeException('A stored ActivityPub moderation action is invalid.', 0, $exception);
            }
        }

        return ModerationAction::MODERATE;
    }

    /** @param array<string, mixed> $data */
    public function store(
        string           $scope,
        string           $value,
        ModerationAction $action,
        int              $priority,
        array            $data,
        int              $now,
    ): void {
        if (!\in_array($scope, ['actor', 'origin', 'domain'], true)
            || $value === ''
            || \strlen($value) > 2_048
            || $priority < -1_000_000
            || $priority > 1_000_000
            || $now < 1
        ) {
            throw new \InvalidArgumentException('An ActivityPub moderation rule is invalid.');
        }

        $hash = hash('sha256', $value);
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->dbLayer->upsert(ActivityPubSchema::MODERATION_RULE_TABLE)
            ->setKey('scope', ':scope')->setParameter('scope', $scope)
            ->setKey('match_hash', ':match_hash')->setParameter('match_hash', $hash)
            ->setValue('match_value', ':match_value')->setParameter('match_value', $value)
            ->setValue('action', ':action')->setParameter('action', $action->value)
            ->setValue('priority', ':priority')->setParameter('priority', $priority)
            ->setValue('enabled', '1')
            ->setValue('rule_data', ':rule_data')->setParameter('rule_data', $json)
            ->setValue('created_at', ':created_at')->setParameter('created_at', $now)
            ->setValue('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->execute()
        ;
    }
}
