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
        <dialog class="comment-confirm-dialog" aria-labelledby="comment-confirm-title" aria-describedby="comment-confirm-context comment-confirm-message">
            <form method="dialog">
                <h2 class="comment-action-question" id="comment-confirm-title"></h2>
                <p class="comment-confirm-context" id="comment-confirm-context"></p>
                <p class="comment-confirm-message" id="comment-confirm-message"></p>
                <div class="dialog-actions">
                    <button class="secondary comment-action-cancel" type="submit" value="cancel"><?php echo $trans('Cancel comment changes'); ?></button>
                    <button class="danger comment-action-confirm" type="submit" value="confirm"></button>
                </div>
            </form>
        </dialog>
    </template>
</section>
