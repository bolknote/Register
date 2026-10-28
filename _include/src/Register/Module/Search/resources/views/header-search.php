<?php

declare(strict_types = 1);

/** @var string $search_label */
/** @var string $search_url */
/** @var string $quick_search_url */
/** @var bool $prefixed */
?>
<details class="register-header-search" data-header-search>
    <summary aria-label="<?php echo register_htmlencode($search_label); ?>" title="<?php echo register_htmlencode($search_label); ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="10.5" cy="10.5" r="6.5"></circle><path d="m15.5 15.5 5 5"></path></svg>
    </summary>
    <form class="register_search_form" role="search" aria-label="<?php echo register_htmlencode($search_label); ?>" method="get" action="<?php echo register_htmlencode($search_url); ?>">
<?php if ($prefixed): ?>
        <input type="hidden" name="search" value="1">
<?php endif; ?>
        <label class="visually-hidden" for="register_search_input"><?php echo register_htmlencode($search_label); ?></label>
        <span class="register-search-autocomplete"><input type="search" name="q" id="register_search_input" data-register-search-url="<?php echo register_htmlencode($quick_search_url); ?>" placeholder="<?php echo register_htmlencode($search_label); ?>" autocomplete="off" enterkeyhint="search"></span>
    </form>
</details>
