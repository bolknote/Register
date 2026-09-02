<?php
/**
 * @var callable $trans
 * @var callable $friendlyFilesize
 * @var bool $enabled
 * @var string $filesystemDirectory
 * @var bool $sharedMemoryEnabled
 * @var array{
 *     total:int,
 *     available:int,
 *     entries:int,
 *     application:array{bytes:int, entries:int}|null
 * }|null $sharedMemoryInfo
 * @var array{total:int, available:int, bytes:int, entries:int}|null $tmpfsInfo
 * @var string $hotCacheDescription
 * @var bool $volatileEncryptionEnabled
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
            <dt><?= $trans('Page cache invalidation') ?></dt>
            <dd><?= $trans('Event-driven page cache invalidation') ?></dd>
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
                <?= $trans($hotCacheDescription) ?>
                <?php if ($volatileEncryptionEnabled): ?>
                    <small class="technical-data"><?= $trans('Volatile page cache encryption enabled') ?></small>
                <?php endif; ?>
                <?php if ($sharedMemoryInfo !== null): ?>
                    <small class="technical-data"><?= $trans('APCu memory usage', [
                        '{{ used }}'      => $friendlyFilesize(max(0, $sharedMemoryInfo['total'] - $sharedMemoryInfo['available'])),
                        '{{ total }}'     => $friendlyFilesize($sharedMemoryInfo['total']),
                        '{{ available }}' => $friendlyFilesize($sharedMemoryInfo['available']),
                        '{{ entries }}'   => $sharedMemoryInfo['entries'],
                    ]) ?></small>
                    <?php if ($sharedMemoryInfo['application'] !== null): ?>
                        <small class="technical-data"><?= $trans('APCu application usage', [
                            '{{ used }}'    => $friendlyFilesize($sharedMemoryInfo['application']['bytes']),
                            '{{ entries }}' => $sharedMemoryInfo['application']['entries'],
                        ]) ?></small>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($tmpfsInfo !== null): ?>
                    <small class="technical-data"><?= $trans('tmpfs memory usage', [
                        '{{ used }}'      => $friendlyFilesize($tmpfsInfo['bytes']),
                        '{{ total }}'     => $friendlyFilesize($tmpfsInfo['total']),
                        '{{ available }}' => $friendlyFilesize($tmpfsInfo['available']),
                        '{{ entries }}'   => $tmpfsInfo['entries'],
                    ]) ?></small>
                <?php endif; ?>
            </dd>
        </div>
    </dl>
    <p class="technical-data"><?= $trans('Page cache tier note') ?></p>
</div>
