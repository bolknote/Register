/**
 * Editor dependency registry for Register.
 *
 * @copyright 2025-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

const editorDeps = {
    PopupMessages: null,
    autoComplete: null,
    register_lang: null,
    CodeMirror: null,
    loadingIndicator: null,
    sUrl: null,
    pictureManagerUrl: null,
    previewErrorStylesheet: null,
    morphdom: null,
    DisplayError: null
};

function setEditorDeps(nextDeps) {
    if (!nextDeps) {
        return;
    }
    Object.keys(nextDeps).forEach(function (key) {
        if (Object.prototype.hasOwnProperty.call(editorDeps, key)) {
            editorDeps[key] = nextDeps[key];
        }
    });
}

function getEditorDeps() {
    return editorDeps;
}

function assertDeps(requiredKeys, context) {
    if (!Array.isArray(requiredKeys)) {
        return true;
    }
    for (let i = 0; i < requiredKeys.length; i++) {
        const key = requiredKeys[i];
        if (!editorDeps[key]) {
            console.warn('Missing editor dependency: ' + key + (context ? ' (' + context + ')' : ''));
            return false;
        }
    }
    return true;
}

export {editorDeps, setEditorDeps, getEditorDeps, assertDeps};
