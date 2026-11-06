<?php

declare(strict_types = 1);

/** @var string $id */
/** @var string $formUrl */
/** @var callable $trans */
?>
<section class="comment-form-block" id="add-comment" data-comment-form-deferred="<?php echo register_htmlencode($id); ?>">
    <a href="<?php echo register_htmlencode($formUrl); ?>" data-comment-form-load data-register-native-navigation><?php echo $trans('Post a comment'); ?></a>
</section>
