<?php
/**
 * @copyright 2026 Roman Parpalak
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
        $counts = [];
        foreach (ReactionType::cases() as $reaction) {
            $counts[$reaction->value] = 0;
        }

        $rows = $this->dbLayer->select('reaction', 'COUNT(*) AS reaction_count')
            ->from(Manifest::TABLE_NAME)
            ->where('content_id = :content_id')->setParameter('content_id', $contentId)
            ->groupBy('reaction')
            ->execute()
            ->fetchAssocAll()
        ;
        foreach ($rows as $row) {
            $reaction = ReactionType::tryFrom((string)$row['reaction']);
            if ($reaction instanceof ReactionType) {
                $counts[$reaction->value] = (int)$row['reaction_count'];
            }
        }

        $selected = null;
        if ($visitorId !== null) {
            $value = $this->dbLayer->select('reaction')
                ->from(Manifest::TABLE_NAME)
                ->where('content_id = :content_id')->setParameter('content_id', $contentId)
                ->andWhere('visitor_id = :visitor_id')->setParameter('visitor_id', $visitorId)
                ->execute()
                ->result()
            ;
            $selected = \is_string($value) ? ReactionType::tryFrom($value) : null;
        }

        return new ReactionState($counts, $selected);
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
}
