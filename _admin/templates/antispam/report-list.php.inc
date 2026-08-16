<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var array<string, int> $metrics */
/** @var array{model_version: string, configured_signals: int, enabled_signals: int, active_rules: int, reputation_records: int, text_model_examples: int, text_model_features: int, text_model_holdout_recall_tenths: int, text_model_holdout_false_positive_tenths: int} $modelStatus */

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
            <h3><?= htmlspecialchars($trans('Antispam model'), ENT_QUOTES, 'UTF-8') ?></h3>
            <strong><?= htmlspecialchars($trans('Filter is configured'), ENT_QUOTES, 'UTF-8') ?></strong>
            <p class="technical-data">
                <?= htmlspecialchars(sprintf(
                    $trans('Antispam model status'),
                    $modelStatus['model_version'],
                    $modelStatus['enabled_signals'],
                    $modelStatus['configured_signals'],
                    $modelStatus['active_rules'],
                    $modelStatus['reputation_records'],
                ), ENT_QUOTES, 'UTF-8') ?>
                <br>
                <?php if ($modelStatus['text_model_examples'] > 0): ?>
                    <?= htmlspecialchars(sprintf(
                        $trans('Antispam trained text model status'),
                        $modelStatus['text_model_examples'],
                        $modelStatus['text_model_features'],
                        $modelStatus['text_model_holdout_recall_tenths'] / 10,
                        $modelStatus['text_model_holdout_false_positive_tenths'] / 10,
                    ), ENT_QUOTES, 'UTF-8') ?>
                <?php else: ?>
                    <?= htmlspecialchars($trans('Antispam text model is not trained yet.'), ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </p>
        </div>
        <?php if ($metrics['total'] === 0): ?>
            <div class="stat-item">
                <h3><?= htmlspecialchars($trans('New antispam checks, 30 days'), ENT_QUOTES, 'UTF-8') ?></h3>
                <strong><?= htmlspecialchars($trans('No new checks yet'), ENT_QUOTES, 'UTF-8') ?></strong>
                <p class="technical-data"><?= htmlspecialchars($trans('Imported comments are not added to the live antispam log.'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        <?php else: ?>
            <div class="stat-item">
                <h3><?= htmlspecialchars($trans('Antispam decisions, 30 days'), ENT_QUOTES, 'UTF-8') ?></h3>
                <strong><?= (int)$metrics['total'] ?></strong>
                <p class="technical-data">
                    <?= htmlspecialchars($trans('Local spam decisions'), ENT_QUOTES, 'UTF-8') ?>: <?= (int)$metrics['local_positive'] ?><br>
                    <?= htmlspecialchars($trans('Filter failures'), ENT_QUOTES, 'UTF-8') ?>: <?= (int)$metrics['failed'] ?>
                </p>
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
        <?php endif; ?>
    </div>
</section>
<?php
$listIntroHtml = (string)ob_get_clean();
require __DIR__ . '/../admin-yard/list.php.inc';
