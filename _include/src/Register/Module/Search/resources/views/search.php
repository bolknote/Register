<?php

declare(strict_types = 1);

/**
 * @var $trans callable
 * @var $action string
 * @var $quickSearchUrl string
 * @var $query string
 * @var $num ?int
 * @var $num_info string
 * @var $output string
 * @var $paging string
 * @var $tags ?string
 */

?>
<form class="search-form" role="search" aria-label="<?php echo s2_htmlencode($trans('Search')); ?>" method="get" action="<?php echo s2_htmlencode($action); ?>">
    <label class="visually-hidden" for="s2_search_input_ext"><?php echo s2_htmlencode($trans('Search')); ?></label>
    <span class="s2-search-autocomplete">
        <input class="search-input" id="s2_search_input_ext" type="search" name="q"
               value="<?php echo s2_htmlencode($query); ?>"
               data-s2-search-url="<?php echo s2_htmlencode($quickSearchUrl ?? ''); ?>"
               autocomplete="off" enterkeyhint="search" />
    </span>
    <input class="search-button" type="submit" name="search" value="<?php echo $trans('Search button'); ?>" />
</form>
<?php

echo $tags ?? '';

if (isset($num)) {
    if ($num > 0) {
        if (!empty($num_info)) {
            echo '<p class="s2_search_found_num">' . $num_info . '</p>';
        }

        echo '<div class="search-results">', $output, '</div>', $paging;
    }
    else {
        echo '<p class="s2_search_not_found">' . $trans('No results found') . '</p>';
    }
}
