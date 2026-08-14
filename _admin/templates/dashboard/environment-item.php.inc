<?php
/**
 * @var callable $trans
 * @var callable $friendlyFilesize
 * @var array{size: int|null, records: int|null, type: string, version: string} $databaseInfo
 * @var string $operatingSystem
 * @var string $phpVersion
 * @var string $serverLoad
 */
?>
<div class="stat-item environment-stat-item">
    <h3><?= $trans('Environment') ?></h3>
    <dl class="stat-details environment-details">
        <div>
            <dt><?= $trans('OS') ?></dt>
            <dd><?= htmlspecialchars($operatingSystem, ENT_QUOTES, 'UTF-8') ?></dd>
        </div>
        <div>
            <dt>PHP</dt>
            <dd>
                <a href="ajax.php?action=phpinfo" title="<?= $trans('PHP info') ?>" target="_blank" rel="noreferrer">
                    <?= htmlspecialchars($phpVersion, ENT_QUOTES, 'UTF-8') ?> &uarr;
                </a>
            </dd>
        </div>
        <div>
            <dt><?= $trans('Database') ?></dt>
            <dd>
                <?= htmlspecialchars($databaseInfo['type'], ENT_QUOTES, 'UTF-8') ?>
                <?= htmlspecialchars($databaseInfo['version'], ENT_QUOTES, 'UTF-8') ?>
                <?php if ($databaseInfo['size'] !== null || $databaseInfo['records'] !== null): ?>
                    <small class="technical-data">
                        <?php if ($databaseInfo['size'] !== null): ?>
                            <?= $trans('Size', ['{{ value }}' => $friendlyFilesize($databaseInfo['size'])]) ?>
                        <?php endif; ?>
                        <?php if ($databaseInfo['size'] !== null && $databaseInfo['records'] !== null): ?> · <?php endif; ?>
                        <?php if ($databaseInfo['records'] !== null): ?>
                            <?= $trans('Rows', [
                                '{{ rows }}' => $databaseInfo['records'],
                                '%count%'    => $databaseInfo['records'],
                            ]) ?>
                        <?php endif; ?>
                    </small>
                <?php endif; ?>
            </dd>
        </div>
        <div>
            <dt><?= $trans('Server load') ?></dt>
            <dd class="technical-data"><?= htmlspecialchars($serverLoad, ENT_QUOTES, 'UTF-8') ?></dd>
        </div>
    </dl>
</div>
