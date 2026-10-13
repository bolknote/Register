<?php

declare(strict_types = 1);

use Register\Content\ContentId;
use Register\Module\Blog\Model\DeferredViewCount;

/** @var callable $trans */
/** @var int $id */
/** @var array $tags */
/** @var string $link */
/** @var bool $commented */
/** @var int $comment_num */
/** @var bool $showComments */
/** @var bool $enabledComments */
/** @var int|null $view_count */

$postId = (int)$id;
$inplaceData = isset($inplace) && \is_array($inplace) ? $inplace : null;
$isPrivatePreview = !empty($scheduled_preview) || !empty($draft_preview);
$tagNames = array_values(array_map(
    static fn(array $tag): string => (string)($tag['title'] ?? ''),
    \is_array($tags ?? null) ? $tags : [],
));
?>
<div class="post foot">
<?php if (!$isPrivatePreview): ?>
<!-- register_reactions:post:<?php echo $postId; ?> -->
<?php endif; ?>
<?php
    $footer = [];

    // Full pages defer counters until after the page cache. Mutation responses
    // provide an exact count, so no unresolved placeholder enters JSON.
    if (!$isPrivatePreview && $postId > 0 && !isset($view_count)) {
        $footer['views'] = DeferredViewCount::placeholder(ContentId::post($postId));
    } elseif (!$isPrivatePreview) {
        $viewCount = (int)($view_count ?? 0);
        $viewLabel = $trans('N Views', ['%count%' => $viewCount, '{{ count }}' => $viewCount]);
        $encodedViewLabel = register_htmlencode($viewLabel);
        $footer['views'] = '<span class="post-foot-views" aria-label="' . $encodedViewLabel
            . '" title="' . $encodedViewLabel . '"><span class="post-foot-views-count" aria-hidden="true">'
            . $viewCount . '</span></span>';
    }

    if (!$isPrivatePreview && $commented && $showComments) {
        if ($comment_num) {
            $commentLabel = $trans('N Comments', ['%count%' => $comment_num, '{{ count }}' => $comment_num]);
            $footer['comments'] = '<span class="post-foot-comments"><a href="' . register_htmlencode($link) . '#comments-title" data-comment-count="' . $comment_num . '" aria-label="' . register_htmlencode($commentLabel) . '">' . $commentLabel . '</a></span>';
        } else {
            $commentLabel = $enabledComments ? $trans('Post comment') : '';
            $footer['comments'] = '<span class="post-foot-comments"><a href="' . register_htmlencode($link) . '#add-comment" data-comment-count="0"' . ($commentLabel !== '' ? ' aria-label="' . register_htmlencode($commentLabel) . '"' : '') . '>' . $commentLabel . '</a></span>';
        }
    }

    if ($tagNames !== [] || $inplaceData !== null) {
        $tagLinks = [];
        foreach (\is_array($tags ?? null) ? $tags : [] as $tag) {
            $tagLinks[] = '<a class="post-tag-link" href="' . register_htmlencode((string)($tag['link'] ?? '')) . '">' . register_htmlencode((string)($tag['title'] ?? '')) . '</a>';
        }

        $emptyClass = $tagNames === [] ? ' is-empty' : '';
        $editAttributes = $inplaceData === null
            ? ''
            : ' data-post-inplace-tags-values';
        $tagLabel = register_htmlencode($trans('Tags'));
        $visibleEditorLabel = $inplaceData === null
            ? ''
            : '<span class="post-foot-tags-label">' . $tagLabel . ':</span> ';
        $footer['tags'] = '<span class="post-foot-tags post-tag-list' . $emptyClass . '" aria-label="' . $tagLabel . '">'
            . $visibleEditorLabel . '<span class="post-tag-values"' . $editAttributes . '>'
            . implode('', $tagLinks) . '</span></span>';
    }

    echo $footer['comments'] ?? '';
    echo '<div class="post-foot-meta">'
        . ($footer['tags'] ?? '')
        . ($footer['views'] ?? '')
        . '</div>';
?>
</div>
