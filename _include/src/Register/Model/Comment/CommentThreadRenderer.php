<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Model\Comment;

use Register\Core\Model\Comment\CommentThreadBuilder;
use Register\Core\Model\UrlBuilder;
use Register\Core\Template\Viewer;

final readonly class CommentThreadRenderer
{
    public function __construct(
        private Viewer               $viewer,
        private CommentThreadBuilder $threadBuilder,
        private CommentModerationTokenManager $moderationTokenManager,
        private UrlBuilder           $urlBuilder,
        private string               $imagePath,
    ) {
    }

    /**
     * @param array<mixed> $comments
     */
    public function render(array $comments, ?CommentModerationContext $moderation = null): string
    {
        $normalizedComments = [];
        foreach ($comments as $comment) {
            if (\is_array($comment)) {
                $comment['moderation_state'] = $this->moderationState($comment);
                if ($comment['moderation_state'] === 'deleted') {
                    $comment['nick'] = '';
                }

                $normalizedComments[] = $comment;
            }
        }

        $audienceComments = $this->audienceComments(
            $normalizedComments,
            $moderation instanceof CommentModerationContext,
        );
        $tree = $this->threadBuilder->build($audienceComments);
        if ($tree === []) {
            return '';
        }

        return $this->viewer->render('comments', [
            'comments' => $this->renderNodes($tree, $moderation),
            'count'    => count($audienceComments),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function renderNodes(
        array                     $nodes,
        ?CommentModerationContext $moderation,
        int                       $depth = 0,
    ): string
    {
        $html = '';
        foreach ($nodes as $node) {
            /** @var list<array<string, mixed>> $children */
            $children = $node['children'];
            $html .= $this->viewer->render('comment', [
                ...$node,
                'children'       => $this->renderNodes($children, $moderation, $depth + 1),
                'depth'          => $depth,
                'visual_depth'   => min($depth, 3),
                'show_addressee' => $depth > 3,
                'userpic_url'    => $node['moderation_state'] === 'deleted'
                    ? null
                    : ($this->userpicUrl($node['userpic_storage_key'] ?? null)
                        ?? $this->presentationAvatarUrl($node['presentation_avatar_path'] ?? null)),
                'moderation'     => $this->moderationData($node, $moderation),
            ]);
        }

        return $html;
    }

    /**
     * Spam explicitly confirmed by a moderator never belongs in the public thread, including a
     * moderator's view. An automatic spam verdict only puts the comment into ordinary moderation:
     * it stays available to moderators until they decide whether it is spam. Any unavailable
     * comment with visible descendants is kept as an anonymous tombstone so the shape and meaning
     * of the discussion stay intact.
     *
     * @param list<array<string, mixed>> $comments
     * @return list<array<string, mixed>>
     */
    private function audienceComments(array $comments, bool $includeHidden): array
    {
        $commentsById = [];
        $positions    = [];
        foreach ($comments as $position => $comment) {
            $id = (int)($comment['id'] ?? 0);
            if ($id > 0 && !isset($commentsById[$id])) {
                $commentsById[$id] = $comment;
                $positions[$id]    = $position;
            }
        }

        $publicIds = [];
        foreach ($commentsById as $id => $comment) {
            if (in_array($comment['moderation_state'], ['visible', 'deleted'], true)
                || ($includeHidden && $comment['moderation_state'] === 'hidden')
            ) {
                $publicIds[$id] = true;
            }
        }

        $tombstoneIds = [];
        foreach (array_keys($publicIds) as $id) {
            $childId = $id;
            $visited = [];
            while (isset($commentsById[$childId])) {
                $parentId = isset($commentsById[$childId]['parent_id'])
                    ? (int)$commentsById[$childId]['parent_id']
                    : 0;
                if (
                    $parentId <= 0
                    || !isset($commentsById[$parentId])
                    || $positions[$parentId] >= $positions[$childId]
                ) {
                    break;
                }

                if (isset($visited[$parentId])) {
                    break;
                }

                $visited[$parentId] = true;

                if (!isset($publicIds[$parentId])) {
                    $publicIds[$parentId]    = true;
                    $tombstoneIds[$parentId] = true;
                }

                $childId = $parentId;
            }
        }

        $result = [];
        foreach ($comments as $comment) {
            $id = (int)($comment['id'] ?? 0);
            if (!isset($publicIds[$id])) {
                continue;
            }

            if (isset($tombstoneIds[$id])) {
                $comment['moderation_state']    = 'deleted';
                $comment['nick']                = '';
                $comment['text']                = '';
                $comment['is_author']           = false;
                $comment['userpic_storage_key'] = null;
            }

            $result[] = $comment;
        }

        return $result;
    }

    /** @param array<string, mixed> $comment */
    private function moderationState(array $comment): string
    {
        if ((bool)($comment['deleted'] ?? false)) {
            return 'deleted';
        }

        if (($comment['moderator_label'] ?? null) === 'spam') {
            return 'spam';
        }

        return (bool)($comment['shown'] ?? true) ? 'visible' : 'hidden';
    }

    /**
     * @param array<string, mixed> $comment
     * @return array<string, mixed>|null
     */
    private function moderationData(array $comment, ?CommentModerationContext $context): ?array
    {
        if (!$context instanceof CommentModerationContext || $comment['moderation_state'] === 'deleted') {
            return null;
        }

        $id = (int)$comment['id'];

        return [
            'action_url' => $this->urlBuilder->link('/comment-moderate'),
            'token'      => $this->moderationTokenManager->issue($context->moderator, $context->contentType, $id),
            'target'     => $context->contentType->value,
            'return_to'  => $context->returnPath,
            'can_edit'   => $context->moderator->canEdit,
            'can_hide'   => $context->moderator->canHide && $comment['moderation_state'] === 'visible',
            'can_show'   => $context->moderator->canHide && in_array($comment['moderation_state'], ['hidden', 'spam'], true),
            'can_delete' => $context->moderator->canHide,
            'can_spam'   => $context->moderator->canHide && $comment['moderation_state'] !== 'spam',
            'can_ham'    => false,
        ];
    }

    private function userpicUrl(mixed $storageKey): ?string
    {
        if (!\is_string($storageKey) || $storageKey === '' || str_starts_with($storageKey, '/')) {
            return null;
        }

        $segments = explode('/', $storageKey);
        foreach ($segments as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                return null;
            }
        }

        return rtrim($this->imagePath, '/') . '/' . implode('/', array_map(rawurlencode(...), $segments));
    }

    private function presentationAvatarUrl(mixed $path): ?string
    {
        if (!\is_string($path)
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $path) === 1
        ) {
            return null;
        }

        foreach (explode('/', ltrim($path, '/')) as $segment) {
            if ($segment === ''
                || preg_match('/%(?![0-9a-f]{2})/i', $segment) === 1
                || \in_array(strtolower(rawurldecode($segment)), ['.', '..'], true)
            ) {
                return null;
            }
        }

        return $this->urlBuilder->link($path);
    }
}
