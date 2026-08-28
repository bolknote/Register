<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Auth\CommentNotificationRepository;
use Register\Content\ContentId;
use Register\Module\Reactions\ReactionAggregateSchema;
use Register\Core\Model\AuthProvider;
use Register\Model\Comment\CommentModerationContext;
use Register\Core\Model\Comment\CommentModerator;
use Register\Model\Comment\CommentThreadRenderer;
use Register\Core\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Request;

/** Queries and renders one comment thread for either posts or permanent pages. */
final readonly class ContentCommentRenderer
{
    /** @var list<CommentPresentationEnricherInterface> */
    private array $presentationEnrichers;

    private ?CommentNotificationRepository $notificationRepository;

    public function __construct(
        private DbLayer                        $dbLayer,
        private CommentThreadRenderer          $threadRenderer,
        private AuthProvider                   $authProvider,
        CommentNotificationRepository|CommentPresentationEnricherInterface ...$dependencies,
    ) {
        $notificationRepository = null;
        $presentationEnrichers = [];
        foreach ($dependencies as $dependency) {
            if ($dependency instanceof CommentNotificationRepository) {
                if ($notificationRepository instanceof CommentNotificationRepository) {
                    throw new \LogicException('Only one comment notification repository can be configured.');
                }

                $notificationRepository = $dependency;
            } else {
                $presentationEnrichers[] = $dependency;
            }
        }

        $this->notificationRepository = $notificationRepository;
        $this->presentationEnrichers = $presentationEnrichers;
    }

    public function render(ContentId $contentId, Request $request, string $returnPath): string
    {
        $moderatorLabel = $this->dbLayer
            ->select('sa.moderator_label')
            ->from('spam_assessments AS sa')
            ->where('sa.target_type = c.content_type')
            ->andWhere('sa.comment_id = c.id')
            ->orderBy('sa.id DESC')
            ->limit(1)
            ->getSql()
        ;
        $spamStatus = $this->dbLayer
            ->select('sa.status')
            ->from('spam_assessments AS sa')
            ->where('sa.target_type = c.content_type')
            ->andWhere('sa.comment_id = c.id')
            ->orderBy('sa.id DESC')
            ->limit(1)
            ->getSql()
        ;
        $commentRows = $this->dbLayer
            ->select(
                'c.id, c.parent_id, c.user_id, c.nick, c.email, c.time, c.modify_time, c.good, c.text, c.shown, c.deleted, p.storage_key AS userpic_storage_key',
                '(' . $moderatorLabel . ') AS moderator_label',
                '(' . $spamStatus . ') AS spam_status',
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

        $comments = $this->attachAuthorFlags($comments);
        $comments = $this->attachImportedReactionSummaries($comments);
        $comments = $this->attachPresentationEnrichments($comments);

        $moderator = $this->authProvider->getAuthenticatedCommentModerator($request);
        $authenticatedUser = $this->authProvider->getAuthenticatedPublicUser($request);
        if ($authenticatedUser instanceof \Register\Core\Model\AuthenticatedPublicUser
            && $this->notificationRepository instanceof CommentNotificationRepository
        ) {
            $this->notificationRepository->markContentRead($authenticatedUser, $contentId);
        }

        return $this->threadRenderer->render(
            $comments,
            $moderator instanceof CommentModerator
                ? new CommentModerationContext($moderator, $contentId->type, $returnPath)
                : null,
        );
    }

    /**
     * Resolve registered-author emails in bounded batches. The previous correlated subquery
     * scanned the users table once for every comment; this performs at most one scan per batch.
     *
     * @param list<array<string, mixed>> $comments
     * @return list<array<string, mixed>>
     */
    private function attachAuthorFlags(array $comments): array
    {
        $normalizedEmails = [];
        foreach ($comments as $comment) {
            $email = mb_strtolower((string)($comment['email'] ?? ''));
            if ($email !== '') {
                $normalizedEmails[$email] = $email;
            }
        }

        $authorEmails = [];
        foreach (array_chunk(array_values($normalizedEmails), 500) as $batchNumber => $emailBatch) {
            $parameters = [];
            $placeholders = [];
            foreach ($emailBatch as $position => $email) {
                $parameter = 'author_email_' . $batchNumber . '_' . $position;
                $parameters[$parameter] = $email;
                $placeholders[] = ':' . $parameter;
            }

            $rows = $this->dbLayer->select('email')
                ->from('users')
                ->where('LOWER(email) IN (' . implode(', ', $placeholders) . ')')
                ->execute($parameters)
                ->fetchAssocAll()
            ;
            foreach ($rows as $row) {
                $email = mb_strtolower((string)($row['email'] ?? ''));
                if ($email !== '') {
                    $authorEmails[$email] = true;
                }
            }
        }

        $result = [];
        foreach ($comments as $comment) {
            $email = mb_strtolower((string)($comment['email'] ?? ''));
            $comment['is_author'] = $email !== '' && isset($authorEmails[$email]);
            unset($comment['email']);
            $result[] = $comment;
        }

        return $result;
    }

    public function renderRegion(ContentId $contentId, Request $request, string $returnPath): string
    {
        $region = 'comments:' . (string)$contentId;

        return '<div class="live-comments-region" data-live-region="' . register_htmlencode($region) . '">'
            . $this->render($contentId, $request, $returnPath)
            . '</div>';
    }

    /**
     * @param list<array<string, mixed>> $comments
     * @return list<array<string, mixed>>
     */
    private function attachImportedReactionSummaries(array $comments): array
    {
        if ($comments === []) {
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
