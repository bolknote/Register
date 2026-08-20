<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Content\ContentId;
use S2\Cms\Model\AuthProvider;
use S2\Cms\Model\Comment\CommentModerationContext;
use S2\Cms\Model\Comment\CommentModerator;
use S2\Cms\Model\Comment\CommentThreadRenderer;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Request;

/** Queries and renders one comment thread for either posts or permanent pages. */
final readonly class ContentCommentRenderer
{
    public function __construct(
        private DbLayer               $dbLayer,
        private CommentThreadRenderer $threadRenderer,
        private AuthProvider          $authProvider,
    ) {
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
        $comments = $this->dbLayer
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
}
