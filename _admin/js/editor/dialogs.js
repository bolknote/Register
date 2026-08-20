/**
 * Dialog helpers for editor actions in S2.
 *
 * @copyright 2007-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

import {editorDeps} from './deps.js';

const loadPictureManager = (function () {
    let wnd = null;
    return function () {
        if (!wnd) {
            wnd = window.open(editorDeps.pictureManagerUrl || 'pictman.php', 'picture_frame', '');
        }
        wnd.focus();
        wnd.document.body.focus();
    };
}());

function GetImage() {
    const dialog = document.getElementById('picture_dialog');
    if (!dialog) {
        return;
    }
    dialog.showModal();
    loadPictureManager();
}

function ReturnImage(s, w, h) {
    document.dispatchEvent(new CustomEvent('return_image.s2', {detail: {file_path: s, width: w, height: h}}));
}

function ReturnAudio(filePath, title) {
    document.dispatchEvent(new CustomEvent('return_audio.s2', {detail: {file_path: filePath, title: title}}));
}

function ClosePictureDialog() {
    const dialog = document.getElementById('picture_dialog');
    if (dialog) {
        dialog.close();
    }
}

function showErrorDialog(sError) {
    if (typeof editorDeps.DisplayError === 'function') {
        editorDeps.DisplayError(sError);
    }
}

export {
    GetImage,
    ReturnImage,
    ReturnAudio,
    ClosePictureDialog,
    showErrorDialog
};
