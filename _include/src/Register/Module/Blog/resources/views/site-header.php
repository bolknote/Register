<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var string $site_name */
/** @var string $tagline */
/** @var string $home_url */
/** @var bool $is_home */
/** @var string $site_tools_html */
/** @var string|null $create_post_html */

$canCreatePost = \is_string($create_post_html ?? null) && $create_post_html !== '';
$titleTag      = $is_home ? 'h1' : 'div';
?>
<div class="site-header-shell">
<?php echo $site_tools_html; ?>

    <div class="site-header-copy">
        <<?php echo $titleTag; ?> class="site-title">
<?php if ($is_home): ?>
            <span><?php echo register_htmlencode($site_name); ?></span>
<?php else: ?>
            <a href="<?php echo register_htmlencode($home_url); ?>"><span><?php echo register_htmlencode($site_name); ?></span></a>
<?php endif; ?>
        </<?php echo $titleTag; ?>>
        <div class="site-tagline"><?php echo nl2br(register_htmlencode($tagline)); ?></div>
    </div>

<?php if ($canCreatePost): ?>
    <div class="post-create-slot" data-post-create-slot hidden>
        <template class="post-create-template"><?php echo $create_post_html; ?></template>
    </div>
<?php endif; ?>
</div>
