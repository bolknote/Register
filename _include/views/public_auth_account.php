<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var \Register\Core\Model\AuthenticatedPublicUser|null $user */
/** @var int $unread */
/** @var string|null $live_region */
/** @var string $login_url */
/** @var string $unread_url */
/** @var string $logout_url */
/** @var string $logout_token */
/** @var string $admin_url */
/** @var string $return_path */

$displayName = $user?->displayName() ?? '';
$initials = $user instanceof \Register\Core\Model\AuthenticatedPublicUser
    ? \Register\Core\Helper\StringHelper::nameInitials($displayName)
    : '';
?>
<div class="public-auth-account"<?php if ($live_region !== null): ?> data-live-region="<?php echo register_htmlencode($live_region); ?>"<?php endif; ?>>
<?php if (!$user instanceof \Register\Core\Model\AuthenticatedPublicUser): ?>
    <a class="public-auth-open public-auth-login-button" href="<?php echo register_htmlencode($login_url); ?>" data-public-auth-open data-register-native-navigation>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="3.3"></circle><path d="M5.5 20a6.5 6.5 0 0 1 13 0M19 4v5M16.5 6.5h5"></path></svg>
        <span><?php echo $trans('Sign in'); ?></span>
    </a>
<?php else: ?>
    <?php if ($unread > 0): ?>
    <a class="public-auth-unread" href="<?php echo register_htmlencode($unread_url); ?>" title="<?php echo register_htmlencode($trans('N unread comments', ['%count%' => $unread])); ?>" aria-label="<?php echo register_htmlencode($trans('N unread comments', ['%count%' => $unread])); ?>" data-register-native-navigation>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20.5 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h9.5a4 4 0 0 1 4 4Z"></path></svg>
        <span><?php echo $unread > 99 ? '99+' : $unread; ?></span>
    </a>
    <?php endif; ?>
    <details class="public-auth-user-menu">
        <summary aria-label="<?php echo register_htmlencode($trans('Account menu')); ?>">
            <span class="public-auth-avatar" aria-hidden="true"><?php echo register_htmlencode($initials); ?></span>
            <span class="public-auth-user-name"><?php echo register_htmlencode($displayName); ?></span>
            <svg class="public-auth-chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"></path></svg>
        </summary>
        <div class="public-auth-menu-popover">
            <div class="public-auth-menu-identity">
                <strong><?php echo register_htmlencode($displayName); ?></strong>
                <?php if ($user->email !== ''): ?><span><?php echo register_htmlencode($user->email); ?></span><?php endif; ?>
            </div>
            <?php if ($user->isAdministrator): ?>
            <a class="public-auth-menu-item" href="<?php echo register_htmlencode($admin_url); ?>" data-register-native-navigation>
                <span class="public-auth-menu-r" aria-hidden="true">ℜ</span><?php echo $trans('Administration'); ?>
            </a>
            <?php endif; ?>
            <form class="public-auth-logout-form" method="post" action="<?php echo register_htmlencode($logout_url); ?>" data-public-auth-form="logout" data-busy-label="<?php echo register_htmlencode($trans('Signing out')); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo register_htmlencode($logout_token); ?>">
                <input type="hidden" name="return_path" value="<?php echo register_htmlencode($return_path); ?>">
                <button class="public-auth-menu-item" type="submit">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4H5v16h5M14 8l4 4-4 4M8 12h10"></path></svg><?php echo $trans('Sign out'); ?>
                </button>
            </form>
        </div>
    </details>
<?php endif; ?>
</div>
