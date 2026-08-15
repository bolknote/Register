/**
 * Basic functions: ajax, md5, popup messages.
 *
 * @copyright 2007-2024 Roman Parpalak
 * @license MIT
 * @package S2
 */

function str_replace(from, to, str) {
    to = to.replace(/\$/g, '$$$$');
    while (str.indexOf(from) >= 0) {
        str = str.replace(from, to);
    }
    return str;
}

//
// Ajax wrappers
//

function checkAjaxStatus(XHR) {
    XHR.s2ErrorFlag = true;

    if (XHR.status === 401) {
        const data = JSON.parse(XHR.responseText);
        if (data && data.message) {
            PopupMessages.show(data.message, null, null, 'login');
        } else {
            DisplayError(XHR.responseText);
        }
        return false;
    }

    if (XHR.status === 403) {
        const data = JSON.parse(XHR.responseText);
        if (data && data.message) {
            PopupMessages.show(data.message);
        } else if (data.errors) {
            Array.from(data.errors).forEach(function (error) {
                // TODO array_merge
                PopupMessages.show(error);
            });
        } else {
            DisplayError(XHR.responseText);
        }
        return false;
    }

    if (XHR.status !== 200) {
        UnknownError(XHR.responseText, XHR.status);
        return false;
    }

    XHR.s2ErrorFlag = false;
    return true;
}

function UnknownError(sError, iStatus) {
    if (sError.indexOf('</body>') === -1 || sError.indexOf('</html>') === -1) {
        sError = s2_lang.unknown_error + ' ' + iStatus + '<br />' +
            s2_lang.server_response + '<br />' + sError;
    }

    DisplayError(sError);
}
