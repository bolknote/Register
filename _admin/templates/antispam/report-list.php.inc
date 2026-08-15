<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var array<string, int> $metrics */

$formatRate = static function (int $count, int $total): string {
    if ($total === 0) {
        return '—';
    }

    return \sprintf('%d / %d (%.1f%%)', $count, $total, 100 * $count / $total);
};

ob_start();
?>
<section class="admin-stat antispam-metrics">
    <div class="stat-items">
        <div class="stat-item">
            <h3><?= htmlspecialchars($trans('Antispam decisions, 30 days'), ENT_QUOTES, 'UTF-8') ?></h3>
            <?= (int)$metrics['total'] ?>
            · <?= htmlspecialchars($trans('Local spam decisions'), ENT_QUOTES, 'UTF-8') ?>: <?= (int)$metrics['local_positive'] ?>
            · <?= htmlspecialchars($trans('Filter failures'), ENT_QUOTES, 'UTF-8') ?>: <?= (int)$metrics['failed'] ?>
        </div>
        <div class="stat-item">
            <h3><?= htmlspecialchars($trans('Local filter quality'), ENT_QUOTES, 'UTF-8') ?></h3>
            <?= htmlspecialchars($trans('False positives'), ENT_QUOTES, 'UTF-8') ?>:
            <?= $formatRate($metrics['local_false_positive'], $metrics['labelled_ham']) ?><br>
            <?= htmlspecialchars($trans('False negatives'), ENT_QUOTES, 'UTF-8') ?>:
            <?= $formatRate($metrics['local_false_negative'], $metrics['labelled_spam']) ?>
        </div>
        <div class="stat-item">
            <h3><?= htmlspecialchars($trans('Shadow comparison'), ENT_QUOTES, 'UTF-8') ?></h3>
            <?= htmlspecialchars($trans('Agreement'), ENT_QUOTES, 'UTF-8') ?>:
            <?= $formatRate($metrics['shadow_agreement'], $metrics['shadow_total']) ?><br>
            <?= htmlspecialchars($trans('Compared decisions'), ENT_QUOTES, 'UTF-8') ?>: <?= (int)$metrics['shadow_total'] ?>
        </div>
        <div class="stat-item">
            <h3>Akismet</h3>
            <?= htmlspecialchars($trans('False positives'), ENT_QUOTES, 'UTF-8') ?>:
            <?= $formatRate($metrics['shadow_false_positive'], $metrics['shadow_labelled_ham']) ?><br>
            <?= htmlspecialchars($trans('False negatives'), ENT_QUOTES, 'UTF-8') ?>:
            <?= $formatRate($metrics['shadow_false_negative'], $metrics['shadow_labelled_spam']) ?>
        </div>
    </div>
</section>
<?php
$listIntroHtml = (string)ob_get_clean();
require __DIR__ . '/../admin-yard/list.php.inc';
