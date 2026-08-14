<?php
/** @var callable $trans */
/** @var callable $friendlyFilesize */
/** @var int|string $rows */
/** @var int|string $bytes */
/** @var string $basePath */
/** @var string $csrfToken */
/** @var \Register\Module\Search\Admin\SearchIndexHealthStatus $health */
?>
<div class="stat-item">
    <input type="hidden" name="register_search_csrf_token" value="<?= s2_htmlencode($csrfToken) ?>">
    <h3><?= $trans('Search index') ?></h3>
    <?php if ($health->repairRequired): ?>
        <span class="icon icon-warning"></span> <?= $trans('Search repair required') ?><br>
    <?php elseif ($health->isUpdating()): ?>
        <?= $trans('Search updating', ['{{ pending }}' => $health->pendingUpdates, '%count%' => $health->pendingUpdates]) ?><br>
    <?php else: ?>
        <?= $trans('Search current') ?><br>
    <?php endif; ?>
    <?= $trans('Indexed documents', [
        '{{ indexed }}' => $health->indexedDocuments,
        '{{ expected }}' => $health->expectedDocuments,
    ]) ?><br>
    <?= $trans('Index storage', [
        '{{ rows }}' => $rows,
        '{{ size }}' => $friendlyFilesize($bytes),
    ]) ?><br>
    <a href="#" onclick="return registerSearch.reindex();" class="js" title="<?= $trans('Repair index title') ?>">
        <?= $trans('Repair index') ?></a><span id="register-search-progress"></span>
</div>
<script src="<?php echo $basePath; ?>/_admin/js/ajax.js"></script>
<script>
    window.registerSearchConfig = {
        url: <?= json_encode($basePath . '/_admin/ajax.php?action=register_search_reindex', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>,
        csrfToken: <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>
    };
</script>
<script src="<?= $basePath ?>/_assets/register/search/index-manager.js"></script>
