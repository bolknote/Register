<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Presentation;

use Register\Comment\CommentPresentationEnricherInterface;
use Register\Comment\CommentPresentationEnrichment;
use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\Infrastructure\ActivityPubSchema;

/** Adds verified provenance and only locally cached avatars to imported public comments. */
final readonly class ActivityPubCommentPresentationEnricher implements CommentPresentationEnricherInterface
{
    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param null|\Closure(): int $clock */
    public function __construct(private DbLayer $dbLayer, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    /**
     * @param non-empty-list<int> $commentIds
     * @return list<CommentPresentationEnrichment>
     */
    #[\Override]
    public function enrich(array $commentIds): array
    {
        $commentIds = array_values(array_unique(array_filter(
            $commentIds,
            static fn(int $commentId): bool => $commentId > 0,
        )));
        if ($commentIds === []) {
            return [];
        }

        if (\count($commentIds) > 1_000) {
            throw new \InvalidArgumentException('A comment presentation enrichment batch is too large.');
        }

        [$condition, $parameters] = $this->integerInCondition('interaction.local_comment_id', $commentIds);
        $query = $this->dbLayer->select(
            'interaction.id AS interaction_id',
            'interaction.local_comment_id',
            'interaction.remote_object_url',
            'actor.actor_url',
            'actor.state AS actor_state',
            'media.public_id AS avatar_public_id',
            'media.source_url_hash AS avatar_source_hash',
            'media.published_source_hash AS avatar_published_hash',
            'media.storage_key AS avatar_storage_key',
            'media.serve_until AS avatar_serve_until',
        )
            ->from(ActivityPubSchema::INTERACTION_TABLE . ' AS interaction')
            ->innerJoin(
                ActivityPubSchema::REMOTE_ACTOR_TABLE . ' AS actor',
                'actor.id = interaction.remote_actor_id',
            )
            ->leftJoin(
                ActivityPubSchema::REMOTE_MEDIA_TABLE . ' AS media',
                'media.remote_actor_id = actor.id',
            )
            ->where('interaction.interaction_type = :type')->setParameter('type', 'reply')
            ->andWhere('interaction.state = :state')->setParameter('state', 'active')
            ->andWhere($condition)
            ->orderBy('interaction.id DESC')
        ;
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }

        $now = ($this->clock)();
        $result = [];
        $seen = [];
        foreach ($query->execute()->fetchAssocAll() as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $commentId = (int)($row['local_comment_id'] ?? 0);
            if ($commentId < 1 || isset($seen[$commentId])) {
                continue;
            }

            $actorUrl  = $this->httpsUrl($row['actor_url'] ?? null);
            $sourceUrl = $this->httpsUrl($row['remote_object_url'] ?? null);
            if ($actorUrl === null || $sourceUrl === null) {
                continue;
            }

            $avatarPath = null;
            $avatarPublicId = $row['avatar_public_id'] ?? null;
            if (\in_array($row['actor_state'] ?? null, ['active', 'moved'], true)
                && \is_string($avatarPublicId)
                && preg_match('/^[A-Za-z0-9_-]{22}$/D', $avatarPublicId) === 1
                && \is_string($row['avatar_storage_key'] ?? null)
                && $row['avatar_storage_key'] !== ''
                && \is_string($row['avatar_source_hash'] ?? null)
                && \is_string($row['avatar_published_hash'] ?? null)
                && hash_equals($row['avatar_source_hash'], $row['avatar_published_hash'])
                && (int)($row['avatar_serve_until'] ?? 0) > $now
            ) {
                $avatarPath = '/activitypub/media/' . $avatarPublicId;
            }

            $seen[$commentId] = true;
            $result[] = new CommentPresentationEnrichment(
                $commentId,
                $avatarPath,
                $actorUrl,
                $sourceUrl,
                'ActivityPub',
            );
        }

        return $result;
    }

    private function httpsUrl(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $parts = parse_url($value);
        if (\strlen($value) > 2_048
            || !\is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || str_contains($value, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1
        ) {
            return null;
        }

        return $value;
    }

    /**
     * @param non-empty-list<int> $ids
     * @return array{string, array<string, int>}
     */
    private function integerInCondition(string $column, array $ids): array
    {
        $parameters = [];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $name = 'activitypub_comment_id_' . $index;
            $parameters[$name] = $id;
            $placeholders[] = ':' . $name;
        }

        return [$column . ' IN (' . implode(', ', $placeholders) . ')', $parameters];
    }
}
