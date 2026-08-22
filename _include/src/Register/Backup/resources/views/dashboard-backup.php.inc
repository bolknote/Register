<?php
/** @var callable $trans */
/** @var callable $friendlyFilesize */
/** @var string $basePath */
/** @var string $csrfToken */
/** @var bool $automatic */
/** @var int $retention */
/** @var \Register\Backup\BackupFile|null $latestBackup */
?>
<div class="stat-item backup-stat-item">
    <h3><?= $trans('Backups') ?></h3>
    <?php if ($latestBackup instanceof \Register\Backup\BackupFile): ?>
        <dl class="stat-details backup-details">
            <div>
                <dt><?= $trans('Latest backup') ?></dt>
                <dd>
                    <time datetime="<?= gmdate(\DateTimeInterface::ATOM, $latestBackup->createdAt) ?>" data-local-time>
                        <?= gmdate('Y-m-d H:i', $latestBackup->createdAt) ?> UTC
                    </time>
                    <small class="technical-data"><?= $friendlyFilesize($latestBackup->size) ?></small>
                </dd>
            </div>
        </dl>
    <?php else: ?>
        <p class="stat-status"><?= $trans('No backups yet') ?></p>
    <?php endif; ?>
    <p class="technical-data backup-schedule"><?= $automatic
        ? $trans('Automatic backup status', ['%count%' => $retention, '{{ count }}' => $retention])
        : $trans('Automatic backups disabled') ?></p>
    <p class="technical-data backup-encryption"><?= $trans('Backup encryption status') ?></p>
    <form class="backup-actions" method="post" action="<?= $basePath ?>/_admin/ajax.php?action=register_backup_create">
        <input type="hidden" name="csrf_token" value="<?= register_htmlencode($csrfToken) ?>">
        <label>
            <span><?= $trans('Current password') ?></span>
            <input type="password" name="password" autocomplete="current-password" maxlength="255" required>
        </label>
        <?php if ($latestBackup instanceof \Register\Backup\BackupFile): ?>
            <button type="submit" formaction="<?= $basePath ?>/_admin/ajax.php?action=register_backup_download"><?= $trans('Download backup') ?></button>
        <?php endif; ?>
        <button type="submit"><?= $trans('Create backup') ?></button>
    </form>
</div>
