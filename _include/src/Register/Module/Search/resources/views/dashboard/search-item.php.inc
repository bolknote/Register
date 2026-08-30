<?php
/** @var callable $trans */
/** @var callable $friendlyFilesize */
/** @var int|string $rows */
/** @var int|string $bytes */
/** @var string $basePath */
/** @var string $csrfToken */
/** @var \Register\Module\Search\Admin\SearchIndexHealthStatus $health */
$currentDocuments = min($health->expectedDocuments, $health->currentDocuments);
$remainingDocuments = max(0, $health->expectedDocuments - $currentDocuments);
$progressPercent = $health->expectedDocuments > 0
    ? min(100, (int)round(100 * $currentDocuments / $health->expectedDocuments))
    : 100;
?>
<div class="stat-item search-stat-item"
     data-register-search
     data-reindex-url="<?= register_htmlencode($basePath . '/_admin/ajax.php?action=register_search_reindex') ?>"
     data-csrf-token="<?= register_htmlencode($csrfToken) ?>"
     data-scheduled-message="<?= register_htmlencode($trans('Repair scheduled')) ?>"
     data-failure-message="<?= register_htmlencode($trans('Repair scheduling failed')) ?>">
    <input type="hidden" name="register_search_csrf_token" value="<?= register_htmlencode($csrfToken) ?>">
    <h3><?= $trans('Search index') ?></h3>
    <p class="stat-status">
    <?php if ($health->repairPending): ?>
        <?= $trans('Search repairing', ['{{ percent }}' => $progressPercent]) ?>
    <?php elseif ($health->repairRequired): ?>
        <span class="icon icon-warning"></span> <?= $trans('Search repair required') ?>
    <?php elseif ($health->isUpdating()): ?>
        <?= $trans('Search updating', ['{{ pending }}' => $health->pendingUpdates, '%count%' => $health->pendingUpdates]) ?>
    <?php else: ?>
        <?= $trans('Search current') ?>
    <?php endif; ?>
    </p>
    <?php if ($health->repairPending): ?>
        <progress class="search-index-progress"
                  max="<?= max(1, $health->expectedDocuments) ?>"
                  value="<?= $health->expectedDocuments > 0 ? $currentDocuments : 1 ?>"
                  aria-label="<?= register_htmlencode($trans('Search repair progress', [
                      '{{ current }}' => $currentDocuments,
                      '{{ expected }}' => $health->expectedDocuments,
                      '{{ remaining }}' => $remainingDocuments,
                  ])) ?>"><?= $progressPercent ?>%</progress>
        <p class="technical-data search-index-progress-label"><?= $trans('Search repair progress', [
            '{{ current }}' => $currentDocuments,
            '{{ expected }}' => $health->expectedDocuments,
            '{{ remaining }}' => $remainingDocuments,
        ]) ?></p>
    <?php endif; ?>
    <ul class="stat-summary-list">
        <?php if ($health->currentDocuments !== $health->expectedDocuments): ?>
            <li><?= $trans('Current index documents', [
                '{{ current }}' => $currentDocuments,
                '{{ expected }}' => $health->expectedDocuments,
            ]) ?></li>
        <?php endif; ?>
        <li><?= $trans('Indexed documents', [
            '{{ indexed }}' => $health->indexedDocuments,
            '{{ expected }}' => $health->expectedDocuments,
        ]) ?></li>
        <li class="technical-data"><?= $trans('Index storage', [
            '{{ rows }}' => $rows,
            '{{ size }}' => $friendlyFilesize($bytes),
        ]) ?></li>
    </ul>
    <?php if ($health->repairRequired && !$health->repairPending): ?>
        <p class="stat-item-actions">
            <a href="#" data-register-search-reindex class="js" title="<?= $trans('Repair index title') ?>">
                <?= $trans('Repair index') ?></a><span id="register-search-progress"></span>
        </p>
    <?php endif; ?>
</div>
<script src="<?php echo $basePath; ?>/_admin/js/ajax.js"></script>
<script src="<?= $basePath ?>/_assets/register/search/index-manager.js"></script>
