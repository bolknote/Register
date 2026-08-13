<?php
/** @var callable $trans */
/** @var callable $friendlyFilesize */
/** @var int|string $rows */
/** @var int|string $bytes */
/** @var string $basePath */
/** @var string $csrfToken */
?>
<div class="stat-item">
    <input type="hidden" name="register_search_csrf_token" value="<?= s2_htmlencode($csrfToken) ?>">
    <h3><?= $trans('Search index') ?></h3>
    <?php if ($rows === 0): ?>
        <span class="icon icon-warning"></span> <?= $trans('Indexing required') ?>
    <?php else: ?>
        <?php echo $trans('Size', ['{{ value }}' => $friendlyFilesize($bytes)]); ?><br>
        <?php echo $trans('Rows', ['{{ rows }}' => $rows, '%count%' => $rows]); ?><br>
    <?php endif; ?>
    <a href="#" onclick="return registerSearch.reindex();" class="js" title="<?= $trans('Reindex title') ?>">
        <?= $trans('Reindex') ?></a><span id="register-search-progress"></span>
</div>
<script src="<?php echo $basePath; ?>/_admin/js/ajax.js"></script>
<script>
    window.registerSearchConfig = {
        url: <?= json_encode($basePath . '/_admin/ajax.php?action=register_search_reindex', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>,
        csrfToken: <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>
    };
</script>
<script src="<?= $basePath ?>/_assets/register/search/index-manager.js"></script>
