<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model\Comment;

/**
 * Turns a chronological comment list into a safe tree.
 *
 * A parent must occur before its child. This keeps imported malformed data (cycles, forward
 * references, or missing parents) readable by placing the affected comment at the root.
 */
final readonly class CommentThreadBuilder
{
    /**
     * @param list<array<string, mixed>> $comments
     * @return list<array<string, mixed>>
     */
    public function build(array $comments): array
    {
        $commentsById = [];
        $orderedIds   = [];

        foreach ($comments as $index => $comment) {
            $id = (int)($comment['id'] ?? 0);
            if ($id <= 0 || isset($commentsById[$id])) {
                continue;
            }

            $comment['id']        = $id;
            $comment['i']         = $index + 1;
            $comment['parent_id'] = isset($comment['parent_id']) && (int)$comment['parent_id'] > 0
                ? (int)$comment['parent_id']
                : null;
            $comment['is_author'] = isset($comment['is_author']) && (bool)$comment['is_author'];

            $commentsById[$id] = $comment;
            $orderedIds[]      = $id;
        }

        $childrenById = [];
        $rootIds      = [];
        $positions    = array_flip($orderedIds);

        foreach ($orderedIds as $id) {
            $parentId = $commentsById[$id]['parent_id'];
            if (
                $parentId === null
                || !isset($commentsById[$parentId])
                || $positions[$parentId] >= $positions[$id]
            ) {
                $commentsById[$id]['parent'] = null;
                $rootIds[]                   = $id;
                continue;
            }

            $commentsById[$id]['parent'] = [
                'id'   => $parentId,
                'i'    => $commentsById[$parentId]['i'],
                'nick' => (string)$commentsById[$parentId]['nick'],
            ];
            $childrenById[$parentId][] = $id;
        }

        return array_map(
            fn(int $id): array => $this->buildNode($id, $commentsById, $childrenById),
            $rootIds,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $commentsById
     * @param array<int, list<int>> $childrenById
     * @return array<string, mixed>
     */
    private function buildNode(int $id, array $commentsById, array $childrenById): array
    {
        $node             = $commentsById[$id];
        $node['children'] = array_map(
            fn(int $childId): array => $this->buildNode($childId, $commentsById, $childrenById),
            $childrenById[$id] ?? [],
        );

        return $node;
    }
}
