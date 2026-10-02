/** Automatic alt generation and the inline image/alt editor. */

import {register_codemirror} from './codemirror.js';
import {editorDeps} from './deps.js';
import '../../../_assets/register/editor/request.js';

export function initImageAlt(form, config) {
    if (!form || !config || !config.enabled || !register_codemirror.isReady()) {
        return;
    }

    const requestStates = new Map();
    let activeRoot = null;
    let activeImage = null;
    let activeEdit = null;
    let syncTimer = null;
    const requestFailed = config.requestFailed || 'Unable to generate an image description. Please try again.';

    function scheduleSyncWithCursor() {
        if (syncTimer !== null) return;
        // CodeMirror's contenteditable input reconciles DOM mutations after
        // the current event. Replacing a line widget before that finishes can
        // make its saved sibling reference stale. Run one coalesced sync in
        // the next task, after the observer has settled.
        syncTimer = setTimeout(function () {
            syncTimer = null;
            syncWithCursor();
        }, 0);
    }

    function clearWidget() {
        activeRoot?.remove();
        activeRoot = null;
        activeImage = null;
    }

    function currentState(image) {
        const state = requestStates.get(image.target);
        if (state && state.status !== 'generating' && state.alt !== image.alt) {
            requestStates.delete(image.target);
        }

        return requestStates.get(image.target) || {
            status: 'ready',
            alt: image.alt,
            expectedAlt: image.alt
        };
    }

    function button(label, className, text) {
        const control = document.createElement('button');
        control.type = 'button';
        control.className = className;
        control.title = label;
        control.setAttribute('aria-label', label);
        control.textContent = text;
        return control;
    }

    function beginEdit(image, state, overlay) {
        overlay.replaceChildren();
        overlay.classList.add('is-editing');

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'ai-image-alt-input';
        input.value = state.alt ?? image.alt;
        input.maxLength = 500;
        input.setAttribute('aria-label', config.edit);
        overlay.append(input);

        let finished = false;
        function finish(save) {
            if (finished) {
                return;
            }
            finished = true;
            activeEdit = null;
            const nextAlt = input.value.trim();
            if (save && register_codemirror.replaceImageAlt(image, image.alt, nextAlt)) {
                requestStates.set(image.target, {
                    status: 'ready',
                    alt: nextAlt,
                    expectedAlt: nextAlt
                });
            }
            scheduleSyncWithCursor();
        }
        activeEdit = {image, finish};

        input.addEventListener('keydown', function (event) {
            if (event.isComposing || event.keyCode === 229) return;
            if (event.key === 'Enter') {
                event.preventDefault();
                finish(true);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                finish(false);
            }
        });
        input.addEventListener('blur', function () {
            finish(true);
        });
        input.focus();
        input.select();
    }

    function keepActiveEdit() {
        if (!activeEdit) return false;
        if (register_codemirror.getTrackedImage(activeEdit.image, activeEdit.image.alt)) return true;
        activeEdit.finish(false);
        return false;
    }

    function render(image, state) {
        // Removing a focused input does not reliably fire blur (notably in
        // Firefox). Let the author finish it before replacing the widget.
        if (keepActiveEdit()) return;
        const root = activeRoot || document.createElement('div');
        root.replaceChildren();
        root.className = 'ai-image-alt-preview';
        root.dataset.state = state.status;
        root.setAttribute('role', 'group');
        root.setAttribute('aria-label', config.preview);

        const preview = document.createElement('img');
        preview.src = image.src;
        preview.alt = '';
        preview.loading = 'lazy';
        preview.setAttribute('aria-hidden', 'true');
        root.append(preview);

        const overlay = document.createElement('div');
        overlay.className = 'ai-image-alt-overlay';
        root.append(overlay);

        if (state.status === 'generating') {
            const spinner = document.createElement('span');
            spinner.className = 'ai-image-alt-spinner';
            spinner.setAttribute('aria-hidden', 'true');
            const message = document.createElement('span');
            message.textContent = config.generating;
            overlay.append(spinner, message);
        } else if (state.status === 'error') {
            const message = document.createElement('span');
            message.textContent = requestFailed;
            const retry = button(config.retry, 'ai-image-alt-retry', config.retry);
            retry.addEventListener('click', function () {
                generate(image);
            });
            overlay.append(message, retry);
        } else {
            const alt = state.alt ?? image.alt;
            const altText = button(config.edit, 'ai-image-alt-text', alt || config.empty);
            altText.addEventListener('click', function () {
                beginEdit(image, {...state, alt: alt}, overlay);
            });
            const regenerate = button(config.regenerate, 'ai-image-alt-regenerate', '↻');
            regenerate.addEventListener('click', function () {
                generate({...image, alt: alt});
            });
            overlay.append(altText, regenerate);
        }

        if (!activeRoot) {
            // A CodeMirror line widget is physically inside its contenteditable
            // DOM. Focusing and then replacing an input there while CodeMirror
            // reconciles a save can corrupt its internal DOM snapshot. Keep the
            // interactive preview next to the editor, outside that boundary.
            const editor = form.querySelector('.CodeMirror');
            if (!editor) return;
            editor.insertAdjacentElement('afterend', root);
            activeRoot = root;
        }
        activeImage = image;
    }

    function syncWithCursor() {
        const image = register_codemirror.getCursorImage();
        requestStates.forEach((state, target) => {
            if (!target.marker.find()) {
                state.controller?.abort();
                requestStates.delete(target);
            }
        });
        if (keepActiveEdit()) return;
        if (!image) {
            const state = activeImage ? requestStates.get(activeImage.target) : null;
            if (!state || state.status !== 'generating') {
                clearWidget();
            }
            return;
        }

        render(image, currentState(image));
    }

    document.addEventListener('save_article_start.register', function () {
        requestStates.forEach(function (state, target) {
            if (state.controller) {
                state.controller.abort();
                requestStates.delete(target);
            }
        });
        activeEdit?.finish(true);
        scheduleSyncWithCursor();
    });

    // Exit checks must see the pending description even if removing the page
    // never blurs its input. Capture also runs before the form's pagehide copy.
    document.addEventListener('changes_present.register', () => activeEdit?.finish(true));
    window.addEventListener('pagehide', () => activeEdit?.finish(true), {capture: true});

    async function generate(image) {
        if (form.inert) return;
        const current = register_codemirror.getTrackedImage(image);
        if (!current) return;
        image = current;
        const previous = requestStates.get(image.target);
        if (previous?.controller) {
            previous.controller.abort();
        }

        const controller = new AbortController();
        const state = {
            status: 'generating',
            alt: image.alt,
            expectedAlt: image.alt,
            controller: controller
        };
        requestStates.set(image.target, state);
        render(image, state);

        const data = new FormData();
        data.set('entity_name', config.entityName);
        data.set('content_id', String(config.contentId || 0));
        data.set('image_src', image.src);
        data.set('title', form.elements.title ? form.elements.title.value : '');
        data.set('text', register_codemirror.getValue());
        const csrfInput = form.elements['__csrf_token'];
        data.set('__csrf_token', csrfInput ? csrfInput.value : '');

        try {
            const {response, data: responseData} = await window.RegisterEditorRequest.requestJson(config.url, {
                method: 'POST',
                body: data,
                signal: controller.signal,
                registerHandleErrorsInline: true
            }, {
                timeoutMs: editorDeps.saveTimeoutMs,
                timeoutMessage: config.timeout || requestFailed
            });
            if (controller.signal.aborted || requestStates.get(image.target) !== state) return;
            if (!response.ok || !responseData?.success || typeof responseData.result !== 'string') {
                throw new Error(requestFailed);
            }

            if (!register_codemirror.replaceImageAlt(image, state.expectedAlt, responseData.result)) {
                requestStates.delete(image.target);
                syncWithCursor();
                return;
            }

            requestStates.set(image.target, {
                status: 'ready',
                alt: responseData.result,
                expectedAlt: responseData.result
            });
            scheduleSyncWithCursor();
        } catch (error) {
            if (controller.signal.aborted || requestStates.get(image.target) !== state || error.name === 'AbortError') {
                return;
            }
            requestStates.set(image.target, {
                status: 'error',
                alt: state.expectedAlt,
                expectedAlt: state.expectedAlt
            });
            scheduleSyncWithCursor();
        }
    }

    document.addEventListener('image_inserted.register', function (event) {
        const image = register_codemirror.getImageBySrc(String(event.detail?.src || ''), '');
        if (image) {
            generate(image);
        }
    });

    register_codemirror.onCursorActivity(syncWithCursor);
    register_codemirror.onChange(function () {
        scheduleSyncWithCursor();
    });
}
