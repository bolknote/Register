<?php
/**
 * @var callable $trans
 * @var string $basePath
 * @var \Register\Core\Mail\MailSettings $settings
 * @var list<string> $errors
 * @var array{
 *     hour:array{accepted:int,failed:int,p50_ms:float,p95_ms:float},
 *     day:array{accepted:int,failed:int,p50_ms:float,p95_ms:float},
 *     last:array{at:string,type:string,status:string,transport:string,duration_ms:float,error_code:int|null,error:string|null}|null
 * } $delivery
 * @var array{available:bool,domain:string,spf:bool|null,dmarc:bool|null,dkim:bool|null,dkim_name:string|null} $dns
 * @var array{total:int,ready:int,delayed:int,failed:int,oldest_ready_age:int|null} $queue
 * @var string $csrfToken
 * @var string $testRecipient
 * @var string $testResult
 */
$state = static function (?bool $value) use ($trans): string {
    return match ($value) {
        true => $trans('DNS record found'),
        false => $trans('DNS record missing'),
        null => $trans('DNS record unknown'),
    };
};
?>
<div class="stat-item mail-stat-item<?= $errors !== [] ? ' requires-attention' : '' ?>" id="mail-status">
    <h3><?= $trans('Outgoing mail') ?></h3>

    <?php if ($errors === []): ?>
        <p class="stat-status stat-status-success"><?= $trans('Mail configuration ready') ?></p>
    <?php else: ?>
        <p class="stat-status"><?= $trans('Mail configuration incomplete') ?></p>
        <ul class="technical-data">
            <?php foreach ($errors as $error): ?>
                <li><code><?= register_htmlencode($error) ?></code> — <?= $trans('Mail configuration error ' . $error) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <dl class="stat-details">
        <div><dt><?= $trans('Mail transport') ?></dt><dd><code><?= register_htmlencode($settings->resolvedTransport()) ?></code></dd></div>
        <div><dt>From</dt><dd><code><?= register_htmlencode($settings->fromEmail()) ?></code></dd></div>
        <div><dt>Envelope</dt><dd><code><?= register_htmlencode($settings->envelopeEmail()) ?></code></dd></div>
        <div><dt><?= $trans('Mail queue') ?></dt><dd><?= $trans('Mail queue summary', [
            '{{ ready }}' => $queue['ready'],
            '{{ delayed }}' => $queue['delayed'],
            '{{ failed }}' => $queue['failed'],
        ]) ?></dd></div>
        <div><dt><?= $trans('Mail last hour') ?></dt><dd><?= $trans('Mail delivery summary', [
            '{{ accepted }}' => $delivery['hour']['accepted'],
            '{{ failed }}' => $delivery['hour']['failed'],
            '{{ p50 }}' => number_format($delivery['hour']['p50_ms'], 1, '.', ''),
            '{{ p95 }}' => number_format($delivery['hour']['p95_ms'], 1, '.', ''),
        ]) ?></dd></div>
        <div><dt><?= $trans('Mail last day') ?></dt><dd><?= $trans('Mail delivery summary', [
            '{{ accepted }}' => $delivery['day']['accepted'],
            '{{ failed }}' => $delivery['day']['failed'],
            '{{ p50 }}' => number_format($delivery['day']['p50_ms'], 1, '.', ''),
            '{{ p95 }}' => number_format($delivery['day']['p95_ms'], 1, '.', ''),
        ]) ?></dd></div>
    </dl>

    <?php if ($dns['domain'] !== ''): ?>
        <p class="technical-data">
            <code><?= register_htmlencode($dns['domain']) ?></code>:
            SPF — <?= $state($dns['spf']) ?>;
            DMARC — <?= $state($dns['dmarc']) ?>;
            DKIM — <?= $state($dns['dkim']) ?>.
        </p>
    <?php endif; ?>

    <?php if ($delivery['last'] !== null): ?>
        <p class="technical-data">
            <?= $trans('Last mail event') ?>:
            <time datetime="<?= register_htmlencode($delivery['last']['at']) ?>" data-local-time><?= register_htmlencode($delivery['last']['at']) ?></time>,
            <code><?= register_htmlencode($delivery['last']['type']) ?></code>,
            <?= register_htmlencode($delivery['last']['status']) ?>,
            <?= number_format($delivery['last']['duration_ms'], 1, '.', '') ?> ms.
            <?php if ($delivery['last']['error'] !== null): ?>
                <br><?= register_htmlencode($delivery['last']['error']) ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if ($testResult !== ''): ?>
        <p class="stat-status<?= $testResult === 'accepted' ? ' stat-status-success' : '' ?>">
            <?= $trans($testResult === 'accepted' ? 'Mail test accepted' : 'Mail test failed') ?>
        </p>
    <?php endif; ?>

    <form class="mail-test-actions" method="post" action="<?= $basePath ?>/_admin/ajax.php?action=register_mail_test">
        <input type="hidden" name="csrf_token" value="<?= register_htmlencode($csrfToken) ?>">
        <label>
            <span><?= $trans('Mail test recipient') ?></span>
            <input type="email" name="recipient" maxlength="254" required value="<?= register_htmlencode($testRecipient) ?>">
        </label>
        <button type="submit"<?= $errors !== [] ? ' disabled' : '' ?>><?= $trans('Send mail test') ?></button>
    </form>
    <p class="technical-data"><?= $trans('Mail acceptance note') ?></p>
</div>
