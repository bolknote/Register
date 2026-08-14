<?php

declare(strict_types = 1);

$htmlEncode = static fn(string $str): string => htmlspecialchars($str, ENT_QUOTES, 'UTF-8');

/** @var list<array{id: string, title: string, version: string, author: string, description: string}> $baseModules */
/** @var callable $trans */
?>
<section class="admin-extensions admin-system-modules">
    <h2><?= $trans('System modules') ?></h2>
    <div class="info-box">
        <p><?= $trans('Base modules description') ?></p>
    </div>
    <div class="extensions base-modules">
        <?php foreach ($baseModules as $module): ?>
            <div class="extension base-module enabled">
                <div class="info">
                    <h3 title="<?= $htmlEncode($module['id']) ?>"><?= $htmlEncode($module['title']) ?> <?= sprintf($trans('Version'), $htmlEncode($module['version'])) ?></h3>
                    <p><?= sprintf($trans('Module by'), $htmlEncode($module['author'])) ?></p>
                </div>
                <?php if ($module['description'] !== ''): ?>
                    <p class="description"><?= $htmlEncode($module['description']) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
