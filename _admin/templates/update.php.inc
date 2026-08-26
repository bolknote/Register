<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var string $basePath */
/** @var ?\Register\Update\ReleaseManifest $installed */
/** @var array<string, array{extension:string,available:bool,requirement:string}> $formats */
/** @var ?string $preferredFormat */
/** @var bool $available */
/** @var bool $singleRootLayout */
/** @var bool $writableLayout */
/** @var bool $storageWritable */
/** @var string $csrfToken */
/** @var int $chunkBytes */
/** @var int $maxArchiveBytes */
/** @var array<string, mixed>|null $recoverable */

?>
<div class="register-update"
     data-register-update
     data-endpoint="<?= register_htmlencode($basePath) ?>/_admin/ajax.php"
     data-csrf-token="<?= register_htmlencode($csrfToken) ?>"
     data-chunk-bytes="<?= $chunkBytes ?>"
     data-max-archive-bytes="<?= $maxArchiveBytes ?>"
     data-session-id="<?= register_htmlencode(\is_array($recoverable) && \is_string($recoverable['id'] ?? null) ? $recoverable['id'] : '') ?>"
     data-message-uploading="<?= register_htmlencode($trans('Uploading release')) ?>"
     data-message-checking="<?= register_htmlencode($trans('Checking release')) ?>"
     data-message-ready="<?= register_htmlencode($trans('Release ready')) ?>"
     data-message-applying="<?= register_htmlencode($trans('Applying release')) ?>"
     data-message-finalizing="<?= register_htmlencode($trans('Finalizing release')) ?>"
     data-message-complete="<?= register_htmlencode($trans('Update completed')) ?>"
     data-message-invalid-file="<?= register_htmlencode($trans('Select a supported release archive')) ?>"
     data-message-password="<?= register_htmlencode($trans('Enter your current password')) ?>"
     data-message-confirm="<?= register_htmlencode($trans('Update confirmation')) ?>"
     data-label-install="<?= register_htmlencode($trans('Back up and install')) ?>"
     data-label-finalize="<?= register_htmlencode($trans('Retry finalization')) ?>">
    <header class="register-update__header">
        <div>
            <p class="register-update__eyebrow">Register</p>
            <h1><?= $trans('Software update') ?></h1>
            <p class="register-update__lead"><?= $trans('Software update intro') ?></p>
        </div>
        <dl class="register-update__current">
            <div>
                <dt><?= $trans('Installed version') ?></dt>
                <dd><code><?= register_htmlencode($installed->version ?? \Register\Update\BuildInfo::DEVELOPMENT_VERSION) ?></code></dd>
            </div>
            <?php if ($installed instanceof \Register\Update\ReleaseManifest): ?>
                <div>
                    <dt><?= $trans('Release date') ?></dt>
                    <dd><time data-local-time datetime="<?= register_htmlencode($installed->builtAt) ?>"><?= register_htmlencode($installed->builtAt) ?></time></dd>
                </div>
            <?php endif; ?>
        </dl>
    </header>

    <section class="register-update__card" aria-labelledby="update-archive-title">
        <h2 id="update-archive-title"><?= $trans('Which release archive to use') ?></h2>
        <p><?= $trans('Release archive instructions') ?></p>
        <ul class="register-update__formats">
            <?php foreach ($formats as $format => $details): ?>
                <li class="<?= $details['available'] ? 'is-available' : 'is-unavailable' ?>">
                    <code><?= register_htmlencode($details['extension']) ?></code>
                    <span><?= $details['available'] ? $trans('Supported by this server') : $trans('Not supported by this server') ?></span>
                    <?php if ($format === $preferredFormat): ?>
                        <strong><?= $trans('Recommended') ?></strong>
                    <?php endif; ?>
                    <small><?= register_htmlencode($details['requirement']) ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="register-update__hint">
            <?= $trans('Release download hint') ?>
            <a href="https://github.com/bolknote/Register/releases" rel="noopener noreferrer"><?= $trans('Open releases') ?></a>.
        </p>
    </section>

    <?php if (!$available): ?>
        <section class="register-update__notice" role="status">
            <h2><?= $trans('Self-update is unavailable') ?></h2>
            <?php if (!$installed instanceof \Register\Update\ReleaseManifest): ?>
                <p><?= $trans('First self-update release instruction') ?></p>
            <?php elseif (!$singleRootLayout): ?>
                <p><?= $trans('Single-root layout required') ?></p>
            <?php elseif (!$writableLayout): ?>
                <p><?= $trans('Writable layout required') ?></p>
            <?php elseif (!$storageWritable): ?>
                <p><?= $trans('Writable update workspace required') ?></p>
            <?php else: ?>
                <p><?= $trans('No archive extractor available') ?></p>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="register-update__card" aria-labelledby="update-upload-title">
            <h2 id="update-upload-title"><?= $trans('Install a release') ?></h2>
            <ol class="register-update__steps">
                <li><?= $trans('Update step verify') ?></li>
                <li><?= $trans('Update step backup') ?></li>
                <li><?= $trans('Update step switch') ?></li>
                <li><?= $trans('Update step migrate') ?></li>
            </ol>

            <label class="register-update__drop" data-update-drop>
                <input type="file" accept=".zip,.tar.gz,.tar.bz2,application/zip,application/gzip,application/x-bzip2" data-update-file>
                <span class="register-update__drop-title"><?= $trans('Drop release archive here') ?></span>
                <span><?= $trans('or choose a file') ?></span>
            </label>

            <div class="register-update__progress" data-update-progress hidden>
                <progress max="100" value="0" data-update-progress-bar></progress>
                <span data-update-progress-label></span>
            </div>

            <div class="register-update__result" data-update-result hidden>
                <h3 data-update-version></h3>
                <dl class="register-update__plan">
                    <div><dt><?= $trans('Files to replace') ?></dt><dd data-update-writes>0</dd></div>
                    <div><dt><?= $trans('Files to remove') ?></dt><dd data-update-deletes>0</dd></div>
                    <div><dt><?= $trans('Files already current') ?></dt><dd data-update-unchanged>0</dd></div>
                </dl>
                <div class="register-update__conflicts" data-update-conflicts hidden>
                    <h3><?= $trans('File conflicts') ?></h3>
                    <p><?= $trans('File conflicts instruction') ?></p>
                    <ul data-update-conflict-list></ul>
                </div>
                <div class="register-update__approval" data-update-approval hidden>
                    <label>
                        <span><?= $trans('Current password') ?></span>
                        <input type="password" autocomplete="current-password" data-update-password>
                    </label>
                    <button type="button" class="primary" data-update-apply><?= $trans('Back up and install') ?></button>
                </div>
            </div>

            <p class="register-update__status" data-update-status role="status" aria-live="polite"></p>
        </section>
    <?php endif; ?>
</div>
