/**
 * Basic functions: ajax, md5, popup messages.
 *
 * @copyright 2007-2024 Roman Parpalak
 * @license MIT
 * @package Register
 */

function str_replace(from, to, str) {
    // Process only the original template: names may contain the placeholder.
    return from === '' ? str : str.split(from).join(to);
}

//
// Ajax wrappers
//

function checkAjaxStatus(XHR) {
    XHR.registerErrorFlag = true;

    if (XHR.status === 401 || XHR.status === 403) {
        let data = null;
        try {
            data = JSON.parse(XHR.responseText);
        } catch {
            // Proxies and login pages can return HTML instead of JSON.
        }
        const errors = Array.isArray(data?.errors)
            ? data.errors.filter(error => typeof error === 'string' && error.trim() !== '') : [];
        if (typeof data?.message === 'string' && data.message.trim() !== '') {
            PopupMessages.show(data.message, null, null, XHR.status === 401 ? 'login' : null);
        } else if (errors.length) {
            errors.forEach(error => PopupMessages.show(error));
        } else {
            DisplayError(String(XHR.responseText ?? '') || register_lang.unknown_error);
        }
        return false;
    }

    if (XHR.status !== 200) {
        UnknownError(XHR.responseText, XHR.status);
        return false;
    }

    XHR.registerErrorFlag = false;
    return true;
}

function UnknownError(sError, iStatus) {
    sError = String(sError ?? '');
    if (sError.indexOf('</body>') === -1 || sError.indexOf('</html>') === -1) {
        sError = register_lang.unknown_error + ' ' + iStatus + '<br />' +
            register_lang.server_response + '<br />' + sError;
    }

    DisplayError(sError);
}
