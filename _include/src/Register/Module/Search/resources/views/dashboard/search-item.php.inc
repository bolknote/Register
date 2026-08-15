<?php
/** @var callable $trans */
/** @var callable $friendlyFilesize */
/** @var int|string $rows */
/** @var int|string $bytes */
/** @var string $basePath */
/** @var string $csrfToken */
/** @var \Register\Module\Search\Admin\SearchIndexHealthStatus $health */
?>
<div class="stat-item search-stat-item"
     data-register-search
     data-reindex-url="<?= s2_htmlencode($basePath . '/_admin/ajax.php?action=register_search_reindex') ?>"
     data-csrf-token="<?= s2_htmlencode($csrfToken) ?>"
     data-scheduled-message="<?= s2_htmlencode($trans('Repair scheduled')) ?>"
     data-failure-message="<?= s2_htmlencode($trans('Repair scheduling failed')) ?>">
    <input type="hidden" name="register_search_csrf_token" value="<?= s2_htmlencode($csrfToken) ?>">
    <h3><?= $trans('Search index') ?></h3>
    <p class="stat-status">
    <?php if ($health->repairPending): ?>
        <?= $trans('Search repairing') ?>
    <?php elseif ($health->repairRequired): ?>
        <span class="icon icon-warning"></span> <?= $trans('Search repair required') ?>
    <?php elseif ($health->isUpdating()): ?>
        <?= $trans('Search updating', ['{{ pending }}' => $health->pendingUpdates, '%count%' => $health->pendingUpdates]) ?>
    <?php else: ?>
        <?= $trans('Search current') ?>
    <?php endif; ?>
    </p>
    <ul class="stat-summary-list">
        <li><?= $trans('Indexed documents', [
            '{{ indexed }}' => $health->indexedDocuments,
            '{{ expected }}' => $health->expectedDocuments,
        ]) ?></li>
        <li class="technical-data"><?= $trans('Index storage', [
            '{{ rows }}' => $rows,
            '{{ size }}' => $friendlyFilesize($bytes),
        ]) ?></li>
    </ul>
    <p class="stat-item-actions">
        <a href="#" data-register-search-reindex class="js" title="<?= $trans('Repair index title') ?>">
            <?= $trans('Repair index') ?></a><span id="register-search-progress"></span>
    </p>
</div>
<script src="<?php echo $basePath; ?>/_admin/js/ajax.js"></script>
<script src="<?= $basePath ?>/_assets/register/search/index-manager.js"></script>
