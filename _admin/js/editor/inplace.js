/**
 * Lazy bootstrap for the shared HTML editor on public in-place post forms.
 *
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

import {initHtmlTextarea, initHtmlToolbar} from './shortcuts.js';
import {s2_codemirror} from './codemirror.js';
import {setEditorDeps} from './deps.js';
import {Preview} from './preview.js';
import {initImagePipeline} from './images/pipeline.js';
import {ClosePictureDialog, ReturnAudio, ReturnImage} from './dialogs.js';
import {escapeHtml, sanitizeUrlForAttribute} from './utils/escape.js';

const adminRoot = new URL('../../', import.meta.url);
const stylePaths = [
    'lib/codemirror.css',
    'lib/codemirror/foldgutter.css',
    'lib/codemirror/dialog.css',
    'lib/codemirror/matchesonscrollbar.css',
    'css/inplace-editor.css',
];
const scriptPaths = [
    'lib/codemirror/codemirror.min.js',
    'lib/codemirror/selection-pointer.min.js',
    'lib/codemirror/xml.min.js',
    'lib/codemirror/javascript.min.js',
    'lib/codemirror/css.min.js',
    'lib/codemirror/htmlmixed.min.js',
    'lib/codemirror/clike.min.js',
    'lib/codemirror/php.min.js',
    'lib/codemirror/foldcode.js',
    'lib/codemirror/foldgutter.js',
    'lib/codemirror/xml-fold.js',
    'lib/codemirror/annotatescrollbar.js',
    'lib/codemirror/dialog.js',
    'lib/codemirror/jump-to-line.js',
    'lib/codemirror/matchesonscrollbar.js',
    'lib/codemirror/search.js',
    'lib/codemirror/searchcursor.js',
    'lib/morphdom-umd.min.js',
    'lib/image-q.min.js',
];

let editorAssetsPromise = null;
let mediaEventsBound = false;
let activeEditor = null;
let openGeneration = 0;

function loadStyle(path) {
    const url = new URL(path, adminRoot).toString();
    const existing = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
        .find((link) => link.href === url);
    if (existing) {
        return Promise.resolve();
    }

    return new Promise((resolve, reject) => {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        link.addEventListener('load', resolve, {once: true});
        link.addEventListener('error', () => reject(new Error('Unable to load editor stylesheet: ' + url)), {once: true});
        document.head.append(link);
    });
}

function loadScript(path) {
    const url = new URL(path, adminRoot).toString();
    const existing = Array.from(document.scripts).find((script) => script.src === url);
    if (existing?.dataset.editorAssetLoaded === 'true') {
        return Promise.resolve();
    }

    return new Promise((resolve, reject) => {
        const script = existing || document.createElement('script');
        const handleLoad = () => {
            script.dataset.editorAssetLoaded = 'true';
            resolve();
        };
        script.addEventListener('load', handleLoad, {once: true});
        script.addEventListener('error', () => reject(new Error('Unable to load editor script: ' + url)), {once: true});
        if (!existing) {
            script.src = url;
            script.async = false;
            document.head.append(script);
        }
    });
}

async function ensureEditorAssets() {
    if (editorAssetsPromise) {
        return editorAssetsPromise;
    }

    editorAssetsPromise = (async () => {
        await Promise.all(stylePaths.map(loadStyle));
        for (const scriptPath of scriptPaths) {
            await loadScript(scriptPath);
        }
        if (!window.CodeMirror) {
            throw new Error('CodeMirror is unavailable after loading editor assets.');
        }
    })();

    try {
        await editorAssetsPromise;
    } catch (error) {
        editorAssetsPromise = null;
        throw error;
    }
}

function ensurePictureDialog() {
    let dialog = document.getElementById('picture_dialog');
    if (dialog) {
        return dialog;
    }

    dialog = document.createElement('dialog');
    dialog.id = 'picture_dialog';
    dialog.className = 'post-inplace-picture-dialog';

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'picture-dialog-close';
    closeButton.setAttribute('aria-label', 'Close');
    closeButton.textContent = '×';
    closeButton.addEventListener('click', ClosePictureDialog);

    const frame = document.createElement('iframe');
    frame.id = 'picture_frame';
    frame.name = 'picture_frame';
    frame.src = '';
    frame.setAttribute('title', 'Media');

    dialog.append(closeButton, frame);
    document.body.append(dialog);

    return dialog;
}

function bindMediaEvents() {
    if (mediaEventsBound) {
        return;
    }
    mediaEventsBound = true;

    window.ReturnImage = ReturnImage;
    window.ReturnAudio = ReturnAudio;
    window.ClosePictureDialog = ClosePictureDialog;

    document.addEventListener('return_image.s2', function (event) {
        if (!activeEditor) {
            return;
        }
        const src = sanitizeUrlForAttribute(event.detail.file_path);
        const width = event.detail.width || 'auto';
        const height = event.detail.height || 'auto';
        document.dispatchEvent(new CustomEvent('insert_tag.s2', {
            detail: {
                sStart: '<img src="' + src + '" width="' + width + '" height="' + height + '" loading="lazy" alt="',
                sEnd: '" />',
            },
        }));
        ClosePictureDialog();
    });

    document.addEventListener('return_audio.s2', function (event) {
        if (!activeEditor) {
            return;
        }
        const src = sanitizeUrlForAttribute(event.detail.file_path);
        const title = escapeHtml(event.detail.title || '');
        document.dispatchEvent(new CustomEvent('insert_tag.s2', {
            detail: {
                sStart: '<audio controls preload="metadata" src="' + src + '" data-title="' + title + '"></audio>',
                sEnd: '',
            },
        }));
        ClosePictureDialog();
    });

    document.addEventListener('save_form.s2', function () {
        if (activeEditor && !activeEditor.form.matches('[aria-busy="true"]')) {
            activeEditor.form.requestSubmit();
        }
    });
}

function editorElements(form) {
    const textarea = form.elements.namedItem('body');
    const title = form.elements.namedItem('title');
    const toolbar = form.querySelector('.html-toolbar');
    const previewFrame = textarea instanceof HTMLTextAreaElement && textarea.id !== ''
        ? document.getElementById(textarea.id + '-preview-frame')
        : null;

    if (
        !(textarea instanceof HTMLTextAreaElement)
        || !(title instanceof HTMLInputElement)
        || !(toolbar instanceof HTMLElement)
        || !(previewFrame instanceof HTMLIFrameElement)
    ) {
        throw new Error('The in-place editor markup is incomplete.');
    }

    return {textarea, title, toolbar, previewFrame};
}

function schedulePreview(state, immediate = false) {
    window.clearTimeout(state.previewTimer);
    const render = () => {
        if (activeEditor !== state) {
            return;
        }
        s2_codemirror.flip();
        Preview(
            state.title.value,
            state.textarea.value,
            state.postId,
            'blog.php',
            'post',
            state.previewFrame
        );
    };

    if (immediate) {
        render();
    } else {
        state.previewTimer = window.setTimeout(render, 300);
    }
}

export async function openInplaceEditor(form) {
    if (!(form instanceof HTMLFormElement)) {
        throw new TypeError('An in-place post form is required.');
    }

    const generation = ++openGeneration;
    await ensureEditorAssets();
    if (generation !== openGeneration || !form.isConnected || form.hidden) {
        return false;
    }

    closeInplaceEditor();
    const {textarea, title, toolbar, previewFrame} = editorElements(form);
    const postId = Number.parseInt(form.closest('[data-post-id]')?.dataset.postId || '', 10);

    setEditorDeps({
        CodeMirror: window.CodeMirror,
        loadingIndicator: (loading) => form.classList.toggle('is-media-loading', loading),
        s2_lang: {unknown_error: form.dataset.editorPreviewError || 'Unable to render preview.'},
        sUrl: new URL('ajax.php?', adminRoot).toString(),
        pictureManagerUrl: new URL('pictman.php', adminRoot).toString(),
        previewErrorStylesheet: new URL('css/editor-preview-error.css', adminRoot).toString(),
        imageOverlayStylesheet: new URL('css/editor-image-overlay.css', adminRoot).toString(),
        morphdom: window.morphdom || null,
    });

    ensurePictureDialog();
    bindMediaEvents();
    form.classList.remove('is-editor-loading');
    form.classList.add('is-editor-ready');
    try {
        initHtmlTextarea(textarea);
        initHtmlToolbar(toolbar);
        initImagePipeline();
    } catch (error) {
        s2_codemirror.close();
        form.classList.remove('is-editor-ready');
        throw error;
    }

    const state = {
        form,
        textarea,
        title,
        previewFrame,
        postId: Number.isSafeInteger(postId) && postId > 0 ? postId : 0,
        previewTimer: 0,
        titleListener: null,
    };
    state.titleListener = () => schedulePreview(state);
    title.addEventListener('input', state.titleListener);
    s2_codemirror.onChange(() => schedulePreview(state));
    activeEditor = state;

    schedulePreview(state, true);

    return true;
}

export function syncInplaceEditor(form) {
    if (activeEditor?.form === form) {
        s2_codemirror.flip();
    }
}

export function closeInplaceEditor(form = null) {
    ++openGeneration;
    if (!activeEditor || (form instanceof HTMLFormElement && activeEditor.form !== form)) {
        return;
    }

    const state = activeEditor;
    activeEditor = null;
    window.clearTimeout(state.previewTimer);
    state.title.removeEventListener('input', state.titleListener);
    s2_codemirror.flip();
    s2_codemirror.close();
    state.form.classList.remove('is-editor-ready', 'is-editor-loading', 'is-media-loading');

    if (document.fullscreenElement && state.form.contains(document.fullscreenElement)) {
        document.exitFullscreen().catch(() => {});
    }
    if (document.getElementById('picture_dialog')?.open) {
        ClosePictureDialog();
    }
}
