<?php
/**
 * @var callable $trans
 * @var callable $friendlyFilesize
 * @var bool $enabled
 * @var string $filesystemDirectory
 * @var bool $sharedMemoryEnabled
 * @var array{total:int, available:int, entries:int}|null $sharedMemoryInfo
 */
?>
<div class="stat-item page-cache-stat-item">
    <h3><?= $trans('Page cache') ?></h3>
    <dl class="stat-details page-cache-details">
        <div>
            <dt><?= $trans('Page cache state') ?></dt>
            <dd><?= $trans($enabled ? 'Page cache enabled' : 'Page cache disabled') ?></dd>
        </div>
        <div>
            <dt><?= $trans('Persistent page cache') ?></dt>
            <dd>
                <?= $trans('Filesystem page cache') ?>
                <small class="technical-data"><code><?= htmlspecialchars($filesystemDirectory, ENT_QUOTES, 'UTF-8') ?></code></small>
            </dd>
        </div>
        <div>
            <dt><?= $trans('Hot page cache') ?></dt>
            <dd>
                <?= $trans($sharedMemoryEnabled ? 'APCu page cache enabled' : 'APCu page cache unavailable') ?>
                <?php if ($sharedMemoryInfo !== null): ?>
                    <small class="technical-data"><?= $trans('APCu memory usage', [
                        '{{ used }}'      => $friendlyFilesize(max(0, $sharedMemoryInfo['total'] - $sharedMemoryInfo['available'])),
                        '{{ total }}'     => $friendlyFilesize($sharedMemoryInfo['total']),
                        '{{ available }}' => $friendlyFilesize($sharedMemoryInfo['available']),
                        '{{ entries }}'   => $sharedMemoryInfo['entries'],
                    ]) ?></small>
                <?php endif; ?>
            </dd>
        </div>
    </dl>
    <p class="technical-data"><?= $trans('Page cache tier note') ?></p>
</div>
