<?php

declare(strict_types = 1);

/** @var callable $trans */

$html = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

?>
<dialog class="admin-confirm-dialog"
        aria-labelledby="admin-confirm-title"
        aria-describedby="admin-confirm-message"
        data-default-confirm-label="<?= $html($trans('Delete permanently')) ?>"
        data-admin-confirm-dialog>
    <form method="dialog">
        <h2 id="admin-confirm-title" data-admin-confirm-title><?= $html($trans('Please confirm')) ?></h2>
        <p id="admin-confirm-message" data-admin-confirm-message></p>
        <div class="dialog-actions">
            <button type="submit" class="secondary" value="cancel" data-admin-confirm-cancel><?= $html($trans('Cancel')) ?></button>
            <button type="submit" class="danger" value="confirm" data-admin-confirm-submit><?= $html($trans('Delete permanently')) ?></button>
        </div>
    </form>
</dialog>
