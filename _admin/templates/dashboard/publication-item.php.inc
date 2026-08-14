<?php
/** @var callable $trans */
/** @var int|string $pages_num */
/** @var int|string $page_comments_num */
/** @var int|string $posts_num */
/** @var int|string $post_comments_num */
?>
<div class="stat-item publication-stat-item">
    <h3><?= $trans('Published content') ?></h3>
    <ul class="publication-statistics">
        <li>
            <span><?= $trans('Pages num', ['{{ pages }}' => $pages_num, '%count%' => $pages_num]) ?></span>
            <span class="publication-comments"><?= $trans('Comments num', [
                '{{ comments }}' => $page_comments_num,
                '%count%'        => $page_comments_num,
            ]) ?></span>
        </li>
        <li>
            <span><?= $trans('Posts num', ['{{ posts }}' => $posts_num, '%count%' => $posts_num]) ?></span>
            <span class="publication-comments"><?= $trans('Comments num', [
                '{{ comments }}' => $post_comments_num,
                '%count%'        => $post_comments_num,
            ]) ?></span>
        </li>
    </ul>
</div>
