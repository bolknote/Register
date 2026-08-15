<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var int $pages_num */
/** @var int $page_comments_num */
/** @var int $posts_num */
/** @var int $post_comments_num */
/** @var int $drafts_num */
/** @var int $scheduled_num */
/** @var int $overdue_num */
/** @var ?int $next_scheduled_at */
/** @var int $pending_comments_num */

$attentionRequired = $drafts_num + $scheduled_num + $overdue_num + $pending_comments_num > 0;

?>
<div class="stat-item publication-stat-item editorial-stat-item <?= $attentionRequired ? 'requires-attention' : 'is-clear' ?>">
    <h3><?= $trans('Editorial queue') ?></h3>
    <?php if (!$attentionRequired): ?>
        <p class="stat-status stat-status-success">✓ <?= $trans('Nothing requires attention') ?></p>
    <?php else: ?>
        <ul class="editorial-attention-list stat-summary-list">
            <?php if ($drafts_num > 0): ?>
                <li>
                    <a href="?entity=BlogPost&amp;action=list&amp;is_active=0&amp;apply_filter=1">
                        <?= $trans('Drafts num', ['{{ drafts }}' => $drafts_num, '%count%' => $drafts_num]) ?>
                    </a>
                </li>
            <?php endif; ?>
            <?php if ($scheduled_num > 0): ?>
                <li>
                    <a href="?entity=BlogPost&amp;action=list&amp;is_active=0&amp;apply_filter=1">
                        <?= $trans('Scheduled posts num', ['{{ posts }}' => $scheduled_num, '%count%' => $scheduled_num]) ?>
                    </a>
                    <?php if ($next_scheduled_at !== null): ?>
                        <span class="technical-data">
                            <?= $trans('Next publication') ?>:
                            <time data-local-time datetime="<?= gmdate('Y-m-d\TH:i:s\Z', $next_scheduled_at) ?>">
                                <?= gmdate('Y-m-d H:i \U\T\C', $next_scheduled_at) ?>
                            </time>
                        </span>
                    <?php endif; ?>
                </li>
            <?php endif; ?>
            <?php if ($overdue_num > 0): ?>
                <li class="editorial-warning">
                    <a href="?entity=BlogPost&amp;action=list&amp;is_active=0&amp;apply_filter=1">
                        <span aria-hidden="true">!</span>
                        <?= $trans('Overdue posts num', ['{{ posts }}' => $overdue_num, '%count%' => $overdue_num]) ?>
                    </a>
                </li>
            <?php endif; ?>
            <?php if ($pending_comments_num > 0): ?>
                <li>
                    <a href="?entity=Comment&amp;action=list&amp;status=0&amp;apply_filter=0">
                        <?= $trans('Pending comments num', [
                            '{{ comments }}' => $pending_comments_num,
                            '%count%'        => $pending_comments_num,
                        ]) ?>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    <?php endif; ?>
    <details class="published-summary">
        <summary><?= $trans('Published content') ?></summary>
        <ul class="publication-statistics stat-summary-list">
            <li>
                <strong><?= $trans('Pages num', ['{{ pages }}' => $pages_num, '%count%' => $pages_num]) ?></strong>
                <span class="publication-comments technical-data"><?= $trans('Comments num', [
                    '{{ comments }}' => $page_comments_num,
                    '%count%'        => $page_comments_num,
                ]) ?></span>
            </li>
            <li>
                <strong><?= $trans('Posts num', ['{{ posts }}' => $posts_num, '%count%' => $posts_num]) ?></strong>
                <span class="publication-comments technical-data"><?= $trans('Comments num', [
                    '{{ comments }}' => $post_comments_num,
                    '%count%'        => $post_comments_num,
                ]) ?></span>
            </li>
        </ul>
    </details>
</div>
