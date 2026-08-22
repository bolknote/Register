/**
 * Extensions management in the admin panel.
 *
 * @copyright 2009-2024 Roman Parpalak
 * @license MIT
 * @package Register
 */

var extensionRoot = document.querySelector('.admin-extensions');
var sUrl = extensionRoot ? extensionRoot.dataset.ajaxUrl : '';

async function changeExtension(sAction, sId, sCsrfToken, sMessage) {
    if (sAction === 'install_extension') {
        const installMessage = (sMessage !== '' ? register_lang.install_message.replaceAll('%s', sMessage) : '')
            + register_lang.install_extension.replaceAll('%s', sId);
        if (!await window.AdminConfirm.ask({
            title: register_lang.install_title,
            message: installMessage,
            confirmLabel: register_lang.install_confirm,
            dangerous: false
        })) {
            return false;
        }
    } else if (sAction === 'uninstall_extension') {
        const uninstallMessage = register_lang.delete_extension.replaceAll('%s', sId)
            + (sMessage !== '' ? '\n\n' + sMessage : '')
            + '\n\n' + register_lang.cannot_undo;
        if (!await window.AdminConfirm.ask({
            title: register_lang.uninstall_title,
            message: uninstallMessage,
            confirmLabel: register_lang.uninstall_confirm,
            dangerous: true
        })) {
            return false;
        }
    }

    try {
        const response = await fetch(sUrl + 'action=' + sAction + '&id=' + sId, {
            method: 'POST',
            body: new URLSearchParams('csrf_token=' + sCsrfToken)
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || register_lang.extension_action_failed);
        }
        loadingIndicator(true);
        window.location.reload();
    } catch (error) {
        PopupMessages.show(error.message || register_lang.extension_action_failed, [], 0, 'extensions.' + sId + '.' + sAction);
    }

    return false;
}

if (extensionRoot) {
    extensionRoot.addEventListener('click', function (event) {
        const button = event.target.closest('button[data-extension-action]');
        if (!button) {
            return;
        }

        button.disabled = true;
        changeExtension(
            button.dataset.extensionAction || '',
            button.dataset.extensionId || '',
            button.dataset.csrfToken || '',
            button.dataset.message || ''
        ).finally(function () {
            button.disabled = false;
        });
    });
}
