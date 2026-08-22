<?php

declare(strict_types = 1);

/**
 * @var string $title
 * @var list<array{title: string, link: string}> $posts
 */

?>
<section class="blog-all-posts" aria-labelledby="blog-all-posts-title">
    <h1 class="blog-all-posts-title" id="blog-all-posts-title"><?php echo register_htmlencode($title); ?></h1>
    <div class="blog-all-posts-list article-text">
        <?php foreach ($posts as $post): ?>
            <p><a href="<?php echo register_htmlencode($post['link']); ?>"><?php echo register_htmlencode($post['title']); ?></a></p>
        <?php endforeach; ?>
    </div>
</section>
