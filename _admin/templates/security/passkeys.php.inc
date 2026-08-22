<?php
/**
 * @var callable $trans
 * @var callable $adminAssetVersion
 * @var string $basePath
 * @var bool $passkeysAvailable
 * @var list<\Register\Core\Security\WebAuthn\WebAuthnCredential> $credentials
 * @var array{available:int,created_at:?int} $recoveryStatus
 * @var string $registerCsrfToken
 * @var string $deleteCsrfToken
 * @var string $recoveryCsrfToken
 */

declare(strict_types = 1);

$html = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

?>
<h1><?= $html($trans('Security')) ?></h1>
<section class="security-settings" data-webauthn-settings data-webauthn-messages
         data-webauthn-operation-failed="<?= $html($trans('The security operation failed.')) ?>"
         data-webauthn-verification-failed="<?= $html($trans('The passkey could not be verified.')) ?>"
         data-webauthn-no-passkey="<?= $html($trans('No passkey was selected.')) ?>"
         data-webauthn-creation-failed="<?= $html($trans('The passkey was not created.')) ?>">
    <div class="admin-section-heading">
        <div>
            <h2><?= $html($trans('Passkeys')) ?></h2>
            <p><?= $html($trans('Use a device passkey or security key to sign in without entering a password.')) ?></p>
        </div>
    </div>

    <?php if (!$passkeysAvailable): ?>
        <p class="error-message-box"><?= $html($trans('Passkeys require a correctly configured HTTPS site URL.')) ?></p>
    <?php endif; ?>

    <?php if ($credentials === []): ?>
        <p data-passkey-empty><?= $html($trans('No passkeys are registered yet.')) ?></p>
    <?php else: ?>
        <ul class="security-credential-list">
            <?php foreach ($credentials as $credential): ?>
                <li>
                    <div>
                        <strong><?= $html($credential->name) ?></strong>
                        <small>
                            <?= $html($trans('Added')) ?>
                            <time data-local-time datetime="<?= gmdate('c', $credential->createdAt) ?>"><?= gmdate('Y-m-d H:i', $credential->createdAt) ?> UTC</time>
                            <?php if ($credential->lastUsedAt !== null): ?>
                                · <?= $html($trans('Last used')) ?>
                                <time data-local-time datetime="<?= gmdate('c', $credential->lastUsedAt) ?>"><?= gmdate('Y-m-d H:i', $credential->lastUsedAt) ?> UTC</time>
                            <?php endif; ?>
                        </small>
                    </div>
                    <form method="post" action="?action=webauthn_delete" data-webauthn-delete-form>
                        <input type="hidden" name="csrf_token" value="<?= $html($deleteCsrfToken) ?>">
                        <input type="hidden" name="credential_hash" value="<?= $html($credential->hash) ?>">
                        <label>
                            <span class="visually-hidden"><?= $html($trans('Current password')) ?></span>
                            <input type="password" name="password" autocomplete="current-password"
                                   placeholder="<?= $html($trans('Current password')) ?>" required>
                        </label>
                        <button type="submit" class="danger"><?= $html($trans('Revoke')) ?></button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="?action=webauthn_register_options" data-webauthn-register-form>
        <input type="hidden" name="csrf_token" value="<?= $html($registerCsrfToken) ?>">
        <label>
            <span><?= $html($trans('Passkey name')) ?></span>
            <input type="text" name="name" maxlength="100" autocomplete="off"
                   placeholder="<?= $html($trans('For example: MacBook or YubiKey')) ?>" required>
        </label>
        <label>
            <span><?= $html($trans('Current password')) ?></span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit" <?= $passkeysAvailable ? '' : 'disabled' ?>><?= $html($trans('Add passkey')) ?></button>
    </form>
    <p class="security-settings-message" data-webauthn-settings-message role="alert" aria-live="polite" hidden></p>

    <div class="admin-section-heading security-recovery-heading">
        <div>
            <h2><?= $html($trans('Recovery codes')) ?></h2>
            <p><?= $html($trans('Each recovery code can be used once if passkeys are unavailable.')) ?></p>
        </div>
    </div>
    <p>
        <?= $html($trans('Unused codes')) ?>: <strong><?= $recoveryStatus['available'] ?></strong>
    </p>
    <form method="post" action="?action=webauthn_recovery_regenerate" data-webauthn-recovery-form>
        <input type="hidden" name="csrf_token" value="<?= $html($recoveryCsrfToken) ?>">
        <label>
            <span><?= $html($trans('Current password')) ?></span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit"><?= $html($trans('Generate new recovery codes')) ?></button>
    </form>
    <div class="security-recovery-codes" data-webauthn-recovery-codes hidden>
        <p><strong><?= $html($trans('Save these codes now. They will not be shown again.')) ?></strong></p>
        <ol></ol>
    </div>
</section>
<script src="<?= $html($basePath) ?>/_admin/js/webauthn.js?v=<?= $html($adminAssetVersion('_admin/js/webauthn.js')) ?>" defer></script>
