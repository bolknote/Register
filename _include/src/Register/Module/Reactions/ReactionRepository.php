<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Reactions;

use S2\Cms\Pdo\DbLayer;

final readonly class ReactionRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function state(int $contentId, ?string $visitorId = null): ReactionState
    {
        return $this->states([$contentId], $visitorId)[$contentId];
    }

    /**
     * @param list<int> $contentIds
     * @return array<int, ReactionState>
     */
    public function states(array $contentIds, ?string $visitorId = null): array
    {
        $normalizedIds = [];
        foreach ($contentIds as $contentId) {
            if ($contentId <= 0) {
                throw new \InvalidArgumentException('A content identifier must be a positive integer.');
            }

            $normalizedIds[$contentId] = $contentId;
        }

        if ($normalizedIds === []) {
            return [];
        }

        $parameters   = [];
        $placeholders = [];
        $counts       = [];
        $extraCounts  = [];
        foreach ($normalizedIds as $normalizedId) {
            $parameter               = 'content_id_' . $normalizedId;
            $parameters[$parameter]  = $normalizedId;
            $placeholders[]          = ':' . $parameter;
            $counts[$normalizedId]   = $this->emptyCounts();
            $extraCounts[$normalizedId] = [];
        }

        $rows = $this->dbLayer->select('content_id', 'reaction', 'COUNT(*) AS reaction_count')
            ->from(Manifest::TABLE_NAME)
            ->where('content_id IN (' . implode(', ', $placeholders) . ')')
            ->groupBy('content_id', 'reaction')
            ->execute($parameters)
            ->fetchAssocAll()
        ;
        foreach ($rows as $row) {
            $rowContentId = (int)$row['content_id'];
            $reaction     = ReactionType::tryFrom((string)$row['reaction']);
            if ($reaction instanceof ReactionType && isset($counts[$rowContentId])) {
                $counts[$rowContentId][$reaction->value] = (int)$row['reaction_count'];
            }
        }

        if ($this->dbLayer->tableExists(ReactionAggregateSchema::TABLE_NAME)) {
            $rows = $this->dbLayer->select(
                'target_id',
                'reaction',
                'emoji',
                'SUM(reaction_count) AS reaction_count',
            )
                ->from(ReactionAggregateSchema::TABLE_NAME)
                ->where("target_type = 'post'")
                ->andWhere('target_id IN (' . implode(', ', $placeholders) . ')')
                ->groupBy('target_id', 'reaction', 'emoji')
                ->execute($parameters)
                ->fetchAssocAll()
            ;
            foreach ($rows as $row) {
                $rowContentId = (int)$row['target_id'];
                if (!isset($counts[$rowContentId])) {
                    continue;
                }

                $count = (int)$row['reaction_count'];
                $reaction = ReactionType::tryFrom((string)$row['reaction']);
                if ($reaction instanceof ReactionType) {
                    $counts[$rowContentId][$reaction->value] += $count;
                    continue;
                }

                $emoji = trim((string)$row['emoji']);
                if ($emoji !== '' && $count > 0) {
                    $extraCounts[$rowContentId][$emoji] = ($extraCounts[$rowContentId][$emoji] ?? 0) + $count;
                }
            }

            foreach ($extraCounts as &$extras) {
                arsort($extras, SORT_NUMERIC);
            }

            unset($extras);
        }

        $selected = array_fill_keys(array_keys($normalizedIds), null);
        if ($visitorId !== null) {
            $rows = $this->dbLayer->select('content_id', 'reaction')
                ->from(Manifest::TABLE_NAME)
                ->where('content_id IN (' . implode(', ', $placeholders) . ')')
                ->andWhere('visitor_id = :visitor_id')->setParameter('visitor_id', $visitorId)
                ->execute($parameters)
                ->fetchAssocAll()
            ;
            foreach ($rows as $row) {
                $rowContentId = (int)$row['content_id'];
                if (array_key_exists($rowContentId, $selected)) {
                    $selected[$rowContentId] = ReactionType::tryFrom((string)$row['reaction']);
                }
            }
        }

        $states = [];
        foreach ($normalizedIds as $normalizedId) {
            $states[$normalizedId] = new ReactionState(
                $counts[$normalizedId],
                $selected[$normalizedId],
                $extraCounts[$normalizedId],
            );
        }

        return $states;
    }

    public function toggle(int $contentId, string $visitorId, ReactionType $reaction): ReactionState
    {
        $current = $this->dbLayer->select('reaction')
            ->from(Manifest::TABLE_NAME)
            ->where('content_id = :content_id')->setParameter('content_id', $contentId)
            ->andWhere('visitor_id = :visitor_id')->setParameter('visitor_id', $visitorId)
            ->execute()
            ->result()
        ;

        if ($current === $reaction->value) {
            $this->dbLayer->delete(Manifest::TABLE_NAME)
                ->where('content_id = :content_id')->setParameter('content_id', $contentId)
                ->andWhere('visitor_id = :visitor_id')->setParameter('visitor_id', $visitorId)
                ->execute()
            ;
        } else {
            $now = time();
            $this->dbLayer->upsert(Manifest::TABLE_NAME)
                ->setKey('content_id', ':content_id')->setParameter('content_id', $contentId)
                ->setKey('visitor_id', ':visitor_id')->setParameter('visitor_id', $visitorId)
                ->setValue('reaction', ':reaction')->setParameter('reaction', $reaction->value)
                ->setValue('created_at', ':created_at')->setParameter('created_at', $now)
                ->setValue('updated_at', ':updated_at')->setParameter('updated_at', $now)
                ->execute()
            ;
        }

        return $this->state($contentId, $visitorId);
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        $counts = [];
        foreach (ReactionType::cases() as $reaction) {
            $counts[$reaction->value] = 0;
        }

        return $counts;
    }
}
