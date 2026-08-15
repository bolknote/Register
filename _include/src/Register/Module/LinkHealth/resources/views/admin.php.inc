<?php

declare(strict_types = 1);

use Register\Module\LinkHealth\ArchiveStatus;
use Register\Module\LinkHealth\LinkHealthStatus;

/** @var callable $trans */
/** @var array{total: int, usages: int, statuses: array<string, int>, inventory_ready: bool} $summary */
/** @var list<array<string, int|string|null>> $targets */
/** @var ?LinkHealthStatus $status */
/** @var int $page */
/** @var int $pageCount */
/** @var string $csrfToken */
/** @var bool $autoRepair */
/** @var bool $canManage */
/** @var string $basePath */

$html = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$time = static fn(int|string|null $value): string => $value === null
    ? $trans('Link never')
    : date('Y-m-d H:i', (int)$value);
$statusCount = static fn(LinkHealthStatus $value): int => $summary['statuses'][$value->value] ?? 0;
$filterUrl = static fn(?LinkHealthStatus $value, int $targetPage = 1): string => '?entity=LinkHealth'
    . ($value instanceof LinkHealthStatus ? '&status=' . rawurlencode($value->value) : '')
    . ($targetPage > 1 ? '&page=' . $targetPage : '');
?>
<section class="link-health-admin"
         data-link-health-admin
         data-action-endpoint="<?= $html($basePath . '/_admin/ajax.php?action=register_link_health_action') ?>"
         data-csrf-token="<?= $html($csrfToken) ?>"
         data-working-message="<?= $html($trans('Link action working')) ?>"
         data-failure-message="<?= $html($trans('Link action failed')) ?>">
    <input type="hidden" name="link_health_csrf_token" value="<?= $html($csrfToken) ?>">
    <header class="link-health-header">
        <div>
            <h1><?= $trans('Link health') ?></h1>
            <p class="technical-data"><?= $summary['inventory_ready']
                    ? $trans('Link inventory current')
                    : $trans('Link inventory building') ?></p>
        </div>
        <p class="link-health-auto-repair <?= $autoRepair ? 'is-enabled' : 'is-disabled' ?>">
            <?= $autoRepair ? $trans('Link automatic repair enabled') : $trans('Link automatic repair disabled') ?>
        </p>
    </header>

    <div class="link-health-summary" aria-label="<?= $trans('Link health') ?>">
        <div><strong><?= $summary['total'] ?></strong><span><?= $trans('Unique external links') ?></span></div>
        <div><strong><?= $summary['usages'] ?></strong><span><?= $trans('Link occurrences') ?></span></div>
        <div class="is-broken"><strong><?= $statusCount(LinkHealthStatus::BROKEN) ?></strong><span><?= $trans('Broken links') ?></span></div>
        <div class="is-suspect"><strong><?= $statusCount(LinkHealthStatus::SUSPECT) ?></strong><span><?= $trans('Suspect links') ?></span></div>
    </div>

    <nav class="link-health-filters" aria-label="<?= $trans('Link state') ?>">
        <a href="<?= $filterUrl(null) ?>"<?= $status === null ? ' aria-current="page"' : '' ?>><?= $trans('All links') ?> <span><?= $summary['total'] ?></span></a>
        <?php foreach ([
            LinkHealthStatus::BROKEN,
            LinkHealthStatus::SUSPECT,
            LinkHealthStatus::UNKNOWN,
            LinkHealthStatus::RESTRICTED,
            LinkHealthStatus::HEALTHY,
            LinkHealthStatus::IGNORED,
        ] as $filterStatus): ?>
            <a href="<?= $filterUrl($filterStatus) ?>"<?= $status === $filterStatus ? ' aria-current="page"' : '' ?>>
                <?= $trans('Link status ' . $filterStatus->value) ?> <span><?= $statusCount($filterStatus) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <p class="link-health-action-status" role="status" aria-live="polite"></p>

    <?php if ($targets === []): ?>
        <div class="info-box"><p><?= $trans('No external links') ?></p></div>
    <?php else: ?>
        <div class="link-health-table-wrap">
            <table class="link-health-table">
                <thead><tr>
                    <th><?= $trans('Link target') ?></th>
                    <th><?= $trans('Link state') ?></th>
                    <th><?= $trans('Link use') ?></th>
                    <th><?= $trans('Link observations') ?></th>
                    <th><?= $trans('Link archive') ?></th>
                    <th><?= $trans('Link actions') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($targets as $target):
                    $targetStatus = LinkHealthStatus::from((string)$target['health_status']);
                    $archiveStatus = ArchiveStatus::from((string)$target['archive_status']);
                    ?>
                    <tr>
                        <td class="link-health-target">
                            <a href="<?= $html((string)$target['normalized_url']) ?>" target="_blank" rel="noreferrer noopener">
                                <?= $html((string)$target['normalized_url']) ?>
                            </a>
                            <small><?= $html((string)$target['host']) ?></small>
                            <?php if ($target['effective_url'] !== null && $target['effective_url'] !== $target['normalized_url']): ?>
                                <small>→ <?= $html((string)$target['effective_url']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="link-health-status status-<?= $html($targetStatus->value) ?>"><?= $trans('Link status ' . $targetStatus->value) ?></span>
                            <?php if ($target['http_status'] !== null): ?><small><?= $trans('Link HTTP status', ['{{ status }}' => $target['http_status']]) ?></small><?php endif; ?>
                            <?php if ((int)$target['failure_count'] > 0): ?><small><?= $trans('Link failures', ['{{ count }}' => $target['failure_count']]) ?></small><?php endif; ?>
                            <?php if ($target['last_error'] !== null): ?><small class="link-health-error"><?= $html((string)$target['last_error']) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?= $trans('Link occurrence count', [
                                '%count%' => $target['occurrence_count'],
                                '{{ count }}' => $target['occurrence_count'],
                            ]) ?>
                            <?= $trans('Link material count', [
                                '%count%' => $target['content_count'],
                                '{{ count }}' => $target['content_count'],
                            ]) ?>
                            <small><?= $trans('Link first seen', ['{{ time }}' => $time($target['first_seen_at'])]) ?></small>
                            <small><?= $trans('Link last seen', ['{{ time }}' => $time($target['last_seen_at'])]) ?></small>
                        </td>
                        <td>
                            <small><?= $trans('Link last checked', ['{{ time }}' => $time($target['last_checked_at'])]) ?></small>
                            <small><?= $trans('Link last successful', ['{{ time }}' => $time($target['last_success_at'])]) ?></small>
                            <?php if ($target['next_check_at'] !== null): ?><small><?= $trans('Link next check', ['{{ time }}' => $time($target['next_check_at'])]) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($archiveStatus === ArchiveStatus::AVAILABLE && $target['archive_url'] !== null): ?>
                                <a href="<?= $html((string)$target['archive_url']) ?>" target="_blank" rel="noreferrer noopener">
                                    <?= $trans('Link archive available', ['{{ timestamp }}' => $html((string)$target['archive_timestamp'])]) ?>
                                </a>
                            <?php elseif ($archiveStatus === ArchiveStatus::MISSING): ?>
                                <?= $trans('Link archive missing') ?>
                            <?php elseif ($archiveStatus === ArchiveStatus::ERROR): ?>
                                <?= $trans('Link archive error') ?>
                            <?php else: ?>
                                <?= $trans('Link archive unchecked') ?>
                            <?php endif; ?>
                        </td>
                        <td class="link-health-actions">
                            <?php if ($canManage): ?>
                                <button type="button" data-operation="recheck" data-target-id="<?= $target['id'] ?>"><?= $trans('Link recheck') ?></button>
                                <?php if ($targetStatus === LinkHealthStatus::IGNORED): ?>
                                    <button type="button" data-operation="unignore" data-target-id="<?= $target['id'] ?>"><?= $trans('Link unignore') ?></button>
                                <?php else: ?>
                                    <button type="button" data-operation="ignore" data-target-id="<?= $target['id'] ?>"><?= $trans('Link ignore') ?></button>
                                <?php endif; ?>
                                <?php if ($targetStatus === LinkHealthStatus::BROKEN && $archiveStatus === ArchiveStatus::AVAILABLE): ?>
                                    <button type="button" class="primary" data-operation="repair" data-target-id="<?= $target['id'] ?>"><?= $trans('Link repair') ?></button>
                                <?php endif; ?>
                            <?php else: ?>
                                <span aria-hidden="true">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($pageCount > 1): ?>
        <nav class="link-health-pagination" aria-label="Pagination">
            <?php for ($pageNumber = 1; $pageNumber <= $pageCount; ++$pageNumber): ?>
                <a href="<?= $filterUrl($status, $pageNumber) ?>"<?= $pageNumber === $page ? ' aria-current="page"' : '' ?>><?= $pageNumber ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</section>
<script src="<?= $basePath ?>/_assets/register/link-health/admin.js"></script>
