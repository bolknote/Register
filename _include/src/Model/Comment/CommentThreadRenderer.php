<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model\Comment;

use S2\Cms\Template\Viewer;

final readonly class CommentThreadRenderer
{
    public function __construct(
        private Viewer               $viewer,
        private CommentThreadBuilder $threadBuilder,
    ) {
    }

    /**
     * @param array<mixed> $comments
     */
    public function render(array $comments): string
    {
        $normalizedComments = [];
        foreach ($comments as $comment) {
            if (\is_array($comment)) {
                $normalizedComments[] = $comment;
            }
        }

        $tree = $this->threadBuilder->build($normalizedComments);
        if ($tree === []) {
            return '';
        }

        return $this->viewer->render('comments', [
            'comments' => $this->renderNodes($tree),
            'count'    => count($normalizedComments),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function renderNodes(array $nodes, int $depth = 0): string
    {
        $html = '';
        foreach ($nodes as $node) {
            /** @var list<array<string, mixed>> $children */
            $children = $node['children'];
            $html .= $this->viewer->render('comment', [
                ...$node,
                'children'       => $this->renderNodes($children, $depth + 1),
                'depth'          => $depth,
                'visual_depth'   => min($depth, 3),
                'show_addressee' => $depth > 3,
            ]);
        }

        return $html;
    }
}
