<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var string $csrfToken */
/** @var int $importedCount */
/** @var string $basePath */

$html = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<section class="telegram-import-admin"
         data-telegram-import-admin
         data-action-endpoint="<?= $html($basePath . '/_admin/ajax.php?action=register_telegram_import') ?>"
         data-working-message="<?= $html($trans('Telegram import working')) ?>">
    <h1><?= $trans('Telegram import') ?></h1>
    <div class="info-box">
        <p><?= $trans('Telegram import intro') ?></p>
        <p><?= $trans('Telegram import exact links') ?></p>
        <p class="technical-data"><?= $trans('Telegram imported identities', ['{{ count }}' => $importedCount]) ?></p>
    </div>

    <form data-telegram-import-form enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $html($csrfToken) ?>">
        <p>
            <label>
                <strong><?= $trans('Telegram JSON file') ?></strong><br>
                <input type="file" name="telegram_json" accept=".json,application/json" required>
            </label>
        </p>
        <p><button type="submit" class="primary"><?= $trans('Telegram import submit') ?></button></p>
    </form>

    <p data-telegram-import-status role="status" aria-live="polite"></p>
    <section data-telegram-import-result hidden>
        <h2><?= $trans('Telegram import result') ?></h2>
        <pre></pre>
    </section>
</section>
<script src="<?= $html($basePath) ?>/_assets/register/telegram-import/admin.js"></script>
