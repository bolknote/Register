/**
 * Editor toolbar and keyboard shortcuts for Register.
 *
 * @copyright 2007-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

import {GetImage} from './dialogs.js';
import {register_codemirror} from './codemirror.js';

let editorDocumentEventsBound = false;

function bindEditorDocumentEvents() {
    if (editorDocumentEventsBound) {
        return;
    }
    editorDocumentEventsBound = true;

    document.addEventListener('insert_paragraph.register', function (event) {
        const sType = event.detail.sType;
        if (sType === 'h2' || sType === 'h3' || sType === 'h4' || sType === 'blockquote' || sType === 'pre') {
            register_codemirror.paragraph('<' + sType + '>', '</' + sType + '>');
        } else {
            register_codemirror.paragraph('<p' + (sType ? ' align="' + sType + '"' : '') + '>', '</p>');
        }
    });

    document.addEventListener('insert_tag.register', function (event) {
        const inserted = register_codemirror.addTag(event.detail.sStart, event.detail.sEnd);
        if (inserted && event.detail.imageSrc) {
            document.dispatchEvent(new CustomEvent('image_inserted.register', {
                detail: {src: event.detail.imageSrc}
            }));
        }
    });
}

export function initHtmlTextarea(eTextarea) {
    if (!eTextarea) {
        return;
    }
    bindEditorDocumentEvents();
    register_codemirror.get_instance(eTextarea);

    // Use parentNode to catch events from CodeMirror.
    const textareaWrapper = eTextarea.parentNode;
    if (!textareaWrapper || textareaWrapper.dataset.editorShortcutsBound === 'true') {
        return;
    }
    textareaWrapper.dataset.editorShortcutsBound = 'true';
    textareaWrapper.addEventListener('keydown', function (e) {
        function insertParagraph(sType) {
            document.dispatchEvent(new CustomEvent('insert_paragraph.register', {detail: {sType: sType}}));
        }

        function tagSelection(sTag) {
            return insertTag('<' + sTag + '>', '</' + sTag + '>');
        }

        function insertTag(sStart, sEnd) {
            document.dispatchEvent(new CustomEvent('insert_tag.register', {detail: {sStart: sStart, sEnd: sEnd}}));
        }

        const ch = String.fromCharCode(e.which).toLowerCase();

        if (e.ctrlKey && !e.shiftKey) {
            if (ch === 'i')
                tagSelection('em');
            else if (ch === 'b')
                tagSelection('strong');
            else if (ch === 'q')
                insertParagraph('blockquote');
            else if (ch === 'l')
                insertParagraph('');
            else if (ch === 'e')
                insertParagraph('center');
            else if (ch === 'r')
                insertParagraph('right');
            else if (ch === 'j')
                insertParagraph('justify');
            else if (ch === 'k')
                insertTag('<a href="">', '</a>');
            else if (ch === 'o')
                tagSelection('nobr');
            else if (ch === 'p')
                GetImage();
            else
                return;
            e.preventDefault();
        }
    });
}

export function initHtmlToolbar(eToolbar) {
    if (!eToolbar || eToolbar.dataset.editorToolbarBound === 'true') {
        return;
    }
    eToolbar.dataset.editorToolbarBound = 'true';

    const isMac = /Mac|iPhone|iPad|iPod/i.test(navigator.platform);
    const undoButton = eToolbar.querySelector('[data-editor-action="undo"]');
    const redoButton = eToolbar.querySelector('[data-editor-action="redo"]');
    if (undoButton) {
        undoButton.title += ' (' + (isMac ? '⌘Z' : 'Ctrl+Z') + ')';
    }
    if (redoButton) {
        redoButton.title += ' (' + (isMac ? '⌘⇧Z' : 'Ctrl+Y') + ')';
    }

    function insertParagraph(sType) {
        document.dispatchEvent(new CustomEvent('insert_paragraph.register', {detail: {sType: sType}}));
    }

    function insertTag(sStart, sEnd) {
        document.dispatchEvent(new CustomEvent('insert_tag.register', {detail: {sStart: sStart, sEnd: sEnd}}));
    }

    function tagSelection(sTag) {
        return insertTag('<' + sTag + '>', '</' + sTag + '>');
    }

    eToolbar.addEventListener('click', function (e) {
        if (e.target.tagName === 'BUTTON') {
            const actions = {
                'undo': () => register_codemirror.undo(),
                'redo': () => register_codemirror.redo(),
                'b': () => tagSelection('strong'),
                'i': () => tagSelection('em'),
                'strike': () => tagSelection('s'),
                'big': () => tagSelection('big'),
                'small': () => tagSelection('small'),
                'sup': () => tagSelection('sup'),
                'sub': () => tagSelection('sub'),
                'nobr': () => tagSelection('nobr'),
                'a': () => insertTag('<a href="">', '</a>'),
                'img': () => GetImage(),
                'h2': () => insertParagraph('h2'),
                'h3': () => insertParagraph('h3'),
                'h4': () => insertParagraph('h4'),
                'left': () => insertParagraph(''),
                'center': () => insertParagraph('center'),
                'right': () => insertParagraph('right'),
                'justify': () => insertParagraph('justify'),
                'quote': () => insertParagraph('blockquote'),
                'ul': () => tagSelection('ul'),
                'ol': () => tagSelection('ol'),
                'li': () => tagSelection('li'),
                'pre': () => insertParagraph('pre'),
                'code': () => tagSelection('code'),
                'parag': () => register_codemirror.smart(),
                'fullscreen': function () {
                    const editorRoot = eToolbar.closest('[data-html-editor-root]')
                        || document.getElementById('id-article-editor-block');
                    if (!editorRoot) {
                        return;
                    }
                    if (!document.fullscreenElement) {
                        editorRoot.requestFullscreen().catch((err) => {
                            console.warn(
                                `Error attempting to enable fullscreen mode: ${err.message} (${err.name})`
                            );
                        });
                    } else {
                        document.exitFullscreen();
                    }
                }
            };
            const action = e.target.dataset.editorAction || e.target.className;
            if (actions[action]) {
                actions[action]();
            }
        }
    });
}

document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.code === 'KeyS') {
        document.dispatchEvent(new Event('save_form.register'));
        e.preventDefault();
    }
});
