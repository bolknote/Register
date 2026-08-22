<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\Domain\ActorType;
use Register\Extension\activitypub\Domain\CanonicalBasePath;
use Register\Extension\activitypub\Domain\CanonicalOrigin;
use Register\Extension\activitypub\Domain\ContentDeliveryMode;
use Register\Extension\activitypub\Domain\FederationState;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\FederationPolicy;
use Register\Extension\activitypub\Domain\PostObjectType;

final readonly class FederationStateRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function lifecycleState(): FederationLifecycleState
    {
        return $this->state()->lifecycle;
    }

    public function state(): FederationState
    {
        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::STATE_TABLE)
            ->where('id = :id')->setParameter('id', 'installation')
            ->execute()
            ->fetchAssoc()
        ;
        if (!\is_array($row)) {
            throw new \RuntimeException('The ActivityPub installation state is missing.');
        }

        try {
            return new FederationState(
                (int)$row['profile_version'],
                FederationLifecycleState::from((string)$row['lifecycle_state']),
                $row['canonical_origin'] === null ? null : new CanonicalOrigin((string)$row['canonical_origin']),
                new CanonicalBasePath((string)$row['base_path']),
                ActorType::from((string)$row['site_actor_type']),
                PostObjectType::from((string)$row['post_object_type']),
                ContentDeliveryMode::from((string)$row['content_mode']),
                (bool)$row['posts_enabled'],
                (bool)$row['pages_enabled'],
                (string)$row['default_visibility'],
                (bool)$row['auto_accept_follows'],
                (int)$row['created_at'],
                $row['activated_at'] === null ? null : (int)$row['activated_at'],
                $row['paused_at'] === null ? null : (int)$row['paused_at'],
                $row['decommissioned_at'] === null ? null : (int)$row['decommissioned_at'],
                (int)$row['updated_at'],
            );
        } catch (\ValueError | \InvalidArgumentException $exception) {
            throw new \RuntimeException('The ActivityPub installation state is invalid.', 0, $exception);
        }
    }

    public function decommissionedAt(): ?int
    {
        return $this->state()->decommissionedAt;
    }

    public function updatePolicy(FederationPolicy $policy, int $now): void
    {
        if ($now < 1) {
            throw new \InvalidArgumentException('An ActivityPub policy timestamp must be positive.');
        }

        $state = $this->state();
        if (!\in_array($state->lifecycle, [
            FederationLifecycleState::INSTALLED,
            FederationLifecycleState::ACTIVE,
            FederationLifecycleState::PAUSED,
        ], true)) {
            throw new \DomainException('ActivityPub policy cannot be changed during or after decommissioning.');
        }

        if (FederationPolicy::fromState($state)->equals($policy)) {
            return;
        }

        $updatedAt = max($now, $state->updatedAt + 1);

        $affected = $this->dbLayer->update(ActivityPubSchema::STATE_TABLE)
            ->set('posts_enabled', ':posts_enabled')->setParameter('posts_enabled', $policy->postsEnabled ? 1 : 0)
            ->set('pages_enabled', ':pages_enabled')->setParameter('pages_enabled', $policy->pagesEnabled ? 1 : 0)
            ->set('post_object_type', ':post_object_type')->setParameter('post_object_type', $policy->postObjectType->value)
            ->set('content_mode', ':content_mode')->setParameter('content_mode', $policy->contentMode->value)
            ->set('default_visibility', ':default_visibility')->setParameter('default_visibility', $policy->defaultVisibility)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $updatedAt)
            ->where('id = :id')->setParameter('id', 'installation')
            ->andWhere('lifecycle_state = :lifecycle')->setParameter('lifecycle', $state->lifecycle->value)
            ->andWhere('updated_at = :previous_updated_at')->setParameter('previous_updated_at', $state->updatedAt)
            ->andWhere('posts_enabled = :previous_posts_enabled')
            ->setParameter('previous_posts_enabled', $state->postsEnabled ? 1 : 0)
            ->andWhere('pages_enabled = :previous_pages_enabled')
            ->setParameter('previous_pages_enabled', $state->pagesEnabled ? 1 : 0)
            ->andWhere('post_object_type = :previous_post_object_type')
            ->setParameter('previous_post_object_type', $state->postObjectType->value)
            ->andWhere('content_mode = :previous_content_mode')
            ->setParameter('previous_content_mode', $state->contentMode->value)
            ->andWhere('default_visibility = :previous_default_visibility')
            ->setParameter('previous_default_visibility', $state->defaultVisibility)
            ->execute()
            ->affectedRows()
        ;
        if ($affected !== 1) {
            throw new \RuntimeException('ActivityPub policy changed concurrently.');
        }

        if (!FederationPolicy::fromState($this->state())->equals($policy)) {
            throw new \RuntimeException('The stored ActivityPub policy could not be verified.');
        }
    }

    public function pause(int $now): bool
    {
        return $this->transition(
            FederationLifecycleState::ACTIVE,
            FederationLifecycleState::PAUSED,
            $now,
            'paused_at',
        );
    }

    public function resume(int $now): bool
    {
        return $this->transition(
            FederationLifecycleState::PAUSED,
            FederationLifecycleState::ACTIVE,
            $now,
            null,
        );
    }

    public function beginDecommission(int $now): bool
    {
        $updated = $this->dbLayer->update(ActivityPubSchema::STATE_TABLE)
            ->set('lifecycle_state', ':next')->setParameter('next', FederationLifecycleState::DECOMMISSIONING->value)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', 'installation')
            ->andWhere('lifecycle_state IN (:active, :paused)')
            ->setParameter('active', FederationLifecycleState::ACTIVE->value)
            ->setParameter('paused', FederationLifecycleState::PAUSED->value)
            ->execute()
            ->affectedRows()
        ;

        return $updated === 1;
    }

    public function finishDecommission(int $now): bool
    {
        return $this->transition(
            FederationLifecycleState::DECOMMISSIONING,
            FederationLifecycleState::DECOMMISSIONED,
            $now,
            'decommissioned_at',
        );
    }

    private function transition(
        FederationLifecycleState $expected,
        FederationLifecycleState $next,
        int                      $now,
        ?string                  $timestampColumn,
    ): bool {
        if ($now < 1) {
            throw new \InvalidArgumentException('An ActivityPub lifecycle timestamp must be positive.');
        }

        $update = $this->dbLayer->update(ActivityPubSchema::STATE_TABLE)
            ->set('lifecycle_state', ':next')->setParameter('next', $next->value)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
        ;
        if ($timestampColumn !== null) {
            $update->set($timestampColumn, ':transition_at')->setParameter('transition_at', $now);
        }

        return $update
            ->where('id = :id')->setParameter('id', 'installation')
            ->andWhere('lifecycle_state = :expected')->setParameter('expected', $expected->value)
            ->execute()
            ->affectedRows() === 1
        ;
    }
}
