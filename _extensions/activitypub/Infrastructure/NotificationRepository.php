<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

use S2\Cms\Pdo\DbLayer;
use s2_extensions\activitypub\Presentation\CanonicalJson;

final readonly class NotificationRepository
{
    public function __construct(private DbLayer $dbLayer, private CanonicalJson $canonicalJson)
    {
    }

    /** @param array<string, mixed> $payload */
    public function create(
        ?int   $localActorId,
        string $type,
        string $subjectType,
        int    $subjectId,
        array  $payload,
        int    $now,
    ): int {
        if (($localActorId !== null && $localActorId < 1)
            || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $type) !== 1
            || preg_match('/^[a-z][a-z0-9_]{0,15}$/D', $subjectType) !== 1
            || $subjectId < 1
            || $now < 1
        ) {
            throw new \InvalidArgumentException('An ActivityPub notification is invalid.');
        }

        $this->dbLayer->insert(ActivityPubSchema::NOTIFICATION_TABLE)
            ->values([
                'local_actor_id'    => ':local_actor_id',
                'notification_type' => ':notification_type',
                'subject_type'      => ':subject_type',
                'subject_id'        => ':subject_id',
                'state'             => ':state',
                'payload_json'      => ':payload_json',
                'created_at'        => ':created_at',
            ])
            ->execute([
                'local_actor_id'    => $localActorId,
                'notification_type' => $type,
                'subject_type'      => $subjectType,
                'subject_id'        => $subjectId,
                'state'             => 'unread',
                'payload_json'      => $this->canonicalJson->encode($payload),
                'created_at'        => $now,
            ])
        ;
        $id = (int)$this->dbLayer->insertId();
        if ($id < 1) {
            throw new \RuntimeException('Unable to obtain the ActivityPub notification identifier.');
        }

        return $id;
    }
}
