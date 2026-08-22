<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Content\ContentId;
use Register\Module\Reactions\ReactionAggregateSchema;
use S2\Cms\Model\AuthProvider;
use S2\Cms\Model\Comment\CommentModerationContext;
use S2\Cms\Model\Comment\CommentModerator;
use S2\Cms\Model\Comment\CommentThreadRenderer;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Request;

/** Queries and renders one comment thread for either posts or permanent pages. */
final readonly class ContentCommentRenderer
{
    /** @var list<CommentPresentationEnricherInterface> */
    private array $presentationEnrichers;

    public function __construct(
        private DbLayer                        $dbLayer,
        private CommentThreadRenderer          $threadRenderer,
        private AuthProvider                   $authProvider,
        CommentPresentationEnricherInterface ...$presentationEnrichers,
    ) {
        $this->presentationEnrichers = array_values($presentationEnrichers);
    }

    public function render(ContentId $contentId, Request $request, string $returnPath): string
    {
        $authorComment = $this->dbLayer
            ->select('COUNT(*)')
            ->from('users AS u')
            ->where('LOWER(u.email) = LOWER(c.email)')
            ->andWhere("c.email <> ''")
            ->getSql()
        ;
        $moderatorLabel = $this->dbLayer
            ->select('sa.moderator_label')
            ->from('spam_assessments AS sa')
            ->where('sa.target_type = c.content_type')
            ->andWhere('sa.comment_id = c.id')
            ->orderBy('sa.id DESC')
            ->limit(1)
            ->getSql()
        ;
        $commentRows = $this->dbLayer
            ->select(
                'c.id, c.parent_id, c.nick, c.time, c.modify_time, c.email, c.show_email, c.good, c.text, c.shown, c.deleted, p.storage_key AS userpic_storage_key',
                '(' . $authorComment . ') AS is_author',
                '(' . $moderatorLabel . ') AS moderator_label',
            )
            ->from(CommentSchema::TABLE_NAME . ' AS c')
            ->leftJoin('userpics AS p', 'p.id = c.userpic_id')
            ->where('c.content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->andWhere('c.content_id = :content_id')->setParameter('content_id', $contentId->value)
            ->orderBy('time, c.id')
            ->execute()
            ->fetchAssocAll()
        ;
        $comments = [];
        foreach ($commentRows as $commentRow) {
            if (!is_array($commentRow)) {
                throw new \UnexpectedValueException('A comment query returned an invalid row.');
            }

            $comments[] = $commentRow;
        }

        $comments = $this->attachImportedReactionSummaries($comments);
        $comments = $this->attachPresentationEnrichments($comments);

        $moderator = $this->authProvider->getAuthenticatedCommentModerator($request);

        return $this->threadRenderer->render(
            $comments,
            $moderator instanceof CommentModerator
                ? new CommentModerationContext($moderator, $contentId->type, $returnPath)
                : null,
        );
    }

    public function renderRegion(ContentId $contentId, Request $request, string $returnPath): string
    {
        $region = 'comments:' . (string)$contentId;

        return '<div class="live-comments-region" data-live-region="' . s2_htmlencode($region) . '">'
            . $this->render($contentId, $request, $returnPath)
            . '</div>';
    }

    /**
     * @param list<array<string, mixed>> $comments
     * @return list<array<string, mixed>>
     */
    private function attachImportedReactionSummaries(array $comments): array
    {
        if ($comments === [] || !$this->dbLayer->tableExists(ReactionAggregateSchema::TABLE_NAME)) {
            return $comments;
        }

        $parameters = [];
        $placeholders = [];
        foreach ($comments as $comment) {
            $id = (int)($comment['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $parameter = 'comment_reaction_id_' . $id;
            $parameters[$parameter] = $id;
            $placeholders[] = ':' . $parameter;
        }

        if ($placeholders === []) {
            return $comments;
        }

        $rows = $this->dbLayer->select('target_id', 'emoji', 'SUM(reaction_count) AS reaction_count')
            ->from(ReactionAggregateSchema::TABLE_NAME)
            ->where("target_type = 'comment'")
            ->andWhere('target_id IN (' . implode(', ', $placeholders) . ')')
            ->groupBy('target_id', 'emoji')
            ->orderBy('target_id, reaction_count DESC, emoji')
            ->execute($parameters)
            ->fetchAssocAll()
        ;
        $summaries = [];
        foreach ($rows as $row) {
            $targetId = (int)$row['target_id'];
            $emoji = trim((string)$row['emoji']);
            $count = (int)$row['reaction_count'];
            if ($targetId > 0 && $emoji !== '' && $count > 0) {
                $summaries[$targetId][$emoji] = $count;
            }
        }

        $result = [];
        foreach ($comments as $comment) {
            $id = (int)($comment['id'] ?? 0);
            if ($id > 0) {
                $comment['reaction_summary'] = $summaries[$id] ?? [];
            }

            $result[] = $comment;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $comments
     * @return list<array<string, mixed>>
     */
    private function attachPresentationEnrichments(array $comments): array
    {
        if ($comments === [] || $this->presentationEnrichers === []) {
            return $comments;
        }

        $commentIds = [];
        foreach ($comments as $comment) {
            $id = (int)($comment['id'] ?? 0);
            if ($id > 0) {
                $commentIds[$id] = $id;
            }
        }

        if ($commentIds === []) {
            return $comments;
        }

        /** @var array<int, CommentPresentationEnrichment> $enrichments */
        $enrichments = [];
        $requestedIds = array_values($commentIds);
        foreach ($this->presentationEnrichers as $enricher) {
            foreach ($enricher->enrich($requestedIds) as $enrichment) {
                if (!isset($commentIds[$enrichment->commentId])) {
                    throw new \LogicException('A comment presentation enricher returned an unrequested comment.');
                }

                if (isset($enrichments[$enrichment->commentId])) {
                    throw new \LogicException('More than one comment presentation enricher claimed the same comment.');
                }

                $enrichments[$enrichment->commentId] = $enrichment;
            }
        }

        $result = [];
        foreach ($comments as $comment) {
            $enrichment = $enrichments[(int)($comment['id'] ?? 0)] ?? null;
            if ($enrichment instanceof CommentPresentationEnrichment) {
                $comment['presentation_avatar_path'] = $enrichment->localAvatarPath;
                $comment['presentation_author_url']  = $enrichment->authorUrl;
                $comment['presentation_source_url']  = $enrichment->sourceUrl;
                $comment['presentation_source_label'] = $enrichment->sourceLabel;
            }

            $result[] = $comment;
        }

        return $result;
    }
}
