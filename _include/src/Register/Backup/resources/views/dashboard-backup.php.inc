<?php
/** @var callable $trans */
/** @var callable $friendlyFilesize */
/** @var string $basePath */
/** @var string $csrfToken */
/** @var bool $automatic */
/** @var int $retention */
/** @var \Register\Backup\BackupFile|null $latestBackup */
?>
<div class="stat-item">
    <h3><?= $trans('Backups') ?></h3>
    <?php if ($latestBackup instanceof \Register\Backup\BackupFile): ?>
        <?= $trans('Latest backup', [
            '{{ date }}' => date('Y-m-d H:i', $latestBackup->createdAt),
            '{{ size }}' => $friendlyFilesize($latestBackup->size),
        ]) ?><br>
        <a href="<?= $basePath ?>/_admin/ajax.php?action=register_backup_download"><?= $trans('Download backup') ?></a><br>
    <?php else: ?>
        <?= $trans('No backups yet') ?><br>
    <?php endif; ?>
    <?= $automatic
        ? $trans('Automatic backup status', ['{{ count }}' => $retention])
        : $trans('Automatic backups disabled') ?><br>
    <form method="post" action="<?= $basePath ?>/_admin/ajax.php?action=register_backup_create">
        <input type="hidden" name="csrf_token" value="<?= s2_htmlencode($csrfToken) ?>">
        <button type="submit"><?= $trans('Create backup') ?></button>
    </form>
</div>
