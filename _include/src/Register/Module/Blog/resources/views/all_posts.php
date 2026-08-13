<?php

declare(strict_types = 1);

/**
 * @var string $title
 * @var list<array{title: string, link: string}> $posts
 */

?>
<section class="blog-all-posts" aria-labelledby="blog-all-posts-title">
    <h1 class="blog-all-posts-title" id="blog-all-posts-title"><?php echo s2_htmlencode($title); ?></h1>
    <div class="blog-all-posts-list e2-text">
        <?php foreach ($posts as $post): ?>
            <p><a href="<?php echo s2_htmlencode($post['link']); ?>"><?php echo s2_htmlencode($post['title']); ?></a></p>
        <?php endforeach; ?>
    </div>
</section>
