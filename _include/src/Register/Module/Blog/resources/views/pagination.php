<?php

declare(strict_types = 1);

/**
 * @var callable $trans
 * @var list<array{number: int|null, url: string|null, current: bool}> $pages
 * @var string|null $previous_url
 * @var string|null $next_url
 */

?>
<nav class="blog-pagination" aria-label="<?php echo register_htmlencode($trans('Pagination')); ?>">
    <?php if ($previous_url !== null): ?>
        <a class="blog-pagination-arrow previous" href="<?php echo register_htmlencode($previous_url); ?>"
           aria-label="<?php echo register_htmlencode($trans('Previous page')); ?>">←</a>
    <?php endif; ?>
    <ol>
        <?php foreach ($pages as $page): ?>
            <li>
                <?php if ($page['number'] === null): ?>
                    <span class="blog-pagination-ellipsis" aria-hidden="true">…</span>
                <?php elseif ($page['current']): ?>
                    <strong class="blog-pagination-current" aria-current="page"><?php echo $page['number']; ?></strong>
                <?php else: ?>
                    <a href="<?php echo register_htmlencode((string)$page['url']); ?>"><?php echo $page['number']; ?></a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php if ($next_url !== null): ?>
        <a class="blog-pagination-arrow next" href="<?php echo register_htmlencode($next_url); ?>"
           aria-label="<?php echo register_htmlencode($trans('Next page')); ?>">→</a>
    <?php endif; ?>
</nav>
