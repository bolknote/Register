<?php

declare(strict_types = 1);

/**
 * Content of <!-- register_comments --> placeholder
 *
 * @var callable $trans
 * @var string $comments
 * @var int $count
 */

?>
<section class="comments-section" aria-labelledby="comments-title">
    <h2 class="comment" id="comments-title"><?php echo $trans('Comments'); ?> <span class="comment-count"><?php echo $count; ?></span></h2>
    <div class="comment-thread" role="list">
        <?php echo $comments; ?>
    </div>
    <template id="comment-action-confirmation-template">
        <div class="comment-action-confirmation" role="alertdialog" aria-live="assertive">
            <span class="comment-action-question"></span>
            <span class="comment-action-buttons">
                <button class="comment-action-cancel" type="button"><?php echo $trans('Cancel comment changes'); ?></button>
                <button class="comment-action-confirm" type="button"></button>
            </span>
        </div>
    </template>
</section>
