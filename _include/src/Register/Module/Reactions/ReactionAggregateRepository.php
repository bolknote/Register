<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Reactions;

use Register\Content\ContentId;
use Register\Core\Pdo\DbLayer;
use Register\Module\Blog\Model\BlogPageCache;

/** Public write boundary for imported reactions; local visitor identities are never synthesized. */
final readonly class ReactionAggregateRepository
{
    public function __construct(
        private DbLayer        $dbLayer,
        private ?BlogPageCache $pageCache = null,
    )
    {
    }

    public function store(
        ReactionAggregate $aggregate,
        ?ContentId         $contentId = null,
        bool               $deferUntilCommit = false,
    ): void
    {
        $this->dbLayer->upsert(ReactionAggregateSchema::TABLE_NAME)
            ->setKey('target_type', ':target_type')->setParameter('target_type', $aggregate->targetType->value)
            ->setKey('target_id', ':target_id')->setParameter('target_id', $aggregate->targetId)
            ->setKey('source', ':source')->setParameter('source', $aggregate->source)
            ->setKey('source_key', ':source_key')->setParameter('source_key', $aggregate->sourceKey)
            ->setValue('reaction', ':reaction')->setParameter('reaction', $aggregate->reaction)
            ->setValue('emoji', ':emoji')->setParameter('emoji', $aggregate->emoji)
            ->setValue('reaction_count', ':reaction_count')->setParameter('reaction_count', $aggregate->count)
            ->setValue('created_at', ':created_at')->setParameter('created_at', $aggregate->createdAt)
            ->setValue('source_data', ':source_data')->setParameter(
                'source_data',
                json_encode($aggregate->sourceData, JSON_THROW_ON_ERROR),
            )
            ->execute()
        ;

        $this->invalidateCache(
            $aggregate->targetType,
            $aggregate->targetId,
            $contentId,
            $deferUntilCommit,
        );
    }

    public function remove(
        ReactionAggregateTargetType $targetType,
        int                         $targetId,
        string                      $source,
        string                      $sourceKey,
        ?ContentId                  $contentId = null,
        bool                        $deferUntilCommit = false,
    ): bool {
        $this->validateIdentity($targetId, $source, $sourceKey);

        $removed = $this->dbLayer->delete(ReactionAggregateSchema::TABLE_NAME)
            ->where('target_type = :target_type')->setParameter('target_type', $targetType->value)
            ->andWhere('target_id = :target_id')->setParameter('target_id', $targetId)
            ->andWhere('source = :source')->setParameter('source', $source)
            ->andWhere('source_key = :source_key')->setParameter('source_key', $sourceKey)
            ->execute()
            ->affectedRows() > 0
        ;

        if ($removed) {
            $this->invalidateCache($targetType, $targetId, $contentId, $deferUntilCommit);
        }

        return $removed;
    }

    private function validateIdentity(int $targetId, string $source, string $sourceKey): void
    {
        if ($targetId <= 0
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $source) !== 1
            || $sourceKey === ''
            || \strlen($sourceKey) > 128
        ) {
            throw new \InvalidArgumentException('An imported reaction identity is invalid.');
        }
    }

    private function invalidateCache(
        ReactionAggregateTargetType $targetType,
        int                         $targetId,
        ?ContentId                  $contentId,
        bool                        $deferUntilCommit,
    ): void
    {
        if (!$this->pageCache instanceof BlogPageCache) {
            return;
        }

        if ($targetType === ReactionAggregateTargetType::POST) {
            $this->pageCache->invalidateFirstPage($deferUntilCommit);
            $this->pageCache->invalidateContent(ContentId::post($targetId), $deferUntilCommit);
        } elseif ($targetType === ReactionAggregateTargetType::PAGE) {
            $this->pageCache->invalidateContent(ContentId::page($targetId), $deferUntilCommit);
        } elseif ($targetType === ReactionAggregateTargetType::COMMENT) {
            if ($contentId instanceof ContentId) {
                $this->pageCache->invalidateContent($contentId, $deferUntilCommit);
            } else {
                // Callers that cannot identify the owning content retain the safe
                // fallback. Bulk import supplies it and therefore stays targeted.
                $this->pageCache->invalidateContentResponses($deferUntilCommit);
            }
        }
    }
}
