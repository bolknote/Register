<?php
/** @var callable $trans */
/** @var \Register\Core\Security\Monitoring\SecurityAlertSummary $securitySummary */
?>
<div class="stat-item security-stat-item">
    <h3><?= $trans('Security monitoring') ?></h3>
    <p class="stat-status">
        <?php if ($securitySummary->hasAlerts()): ?>
            <span class="icon icon-warning"></span> <?= $trans('Security activity warning') ?>
        <?php else: ?>
            <?= $trans('Security activity normal') ?>
        <?php endif; ?>
    </p>
    <ul class="stat-summary-list technical-data">
        <li><?= $trans('Security HTTP summary', [
            '{{ unauthorized }}' => $securitySummary->unauthorizedResponses,
            '{{ forbidden }}'    => $securitySummary->forbiddenResponses,
            '{{ rate_limited }}' => $securitySummary->rateLimitedResponses,
        ]) ?></li>
        <li><?= $trans('Security application summary', [
            '{{ csp }}'     => $securitySummary->cspViolations,
            '{{ uploads }}' => $securitySummary->uploadFailures,
        ]) ?></li>
    </ul>
    <?php if ($securitySummary->telemetryNearCapacity): ?>
        <p class="technical-data"><span class="icon icon-warning"></span> <?= $trans('Security telemetry near capacity') ?></p>
    <?php endif; ?>
    <p class="technical-data"><?= $trans('Security monitoring window', [
        '{{ minutes }}' => $securitySummary->windowMinutes,
    ]) ?></p>
</div>
