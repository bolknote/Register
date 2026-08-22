/** Automatic alt generation and the inline image/alt editor. */

import {register_codemirror} from './codemirror.js';

export function initImageAlt(form, config) {
    if (!form || !config || !config.enabled || !register_codemirror.isReady()) {
        return;
    }

    const requestStates = new Map();
    let activeWidget = null;
    let activeSource = '';

    function clearWidget() {
        if (activeWidget) {
            activeWidget.clear();
        }
        activeWidget = null;
        activeSource = '';
    }

    function currentState(image) {
        const state = requestStates.get(image.src);
        if (state && state.status !== 'generating' && state.alt !== image.alt) {
            requestStates.delete(image.src);
        }

        return requestStates.get(image.src) || {
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
            const nextAlt = input.value.trim();
            if (save && register_codemirror.replaceImageAlt(image.src, image.alt, nextAlt)) {
                requestStates.set(image.src, {
                    status: 'ready',
                    alt: nextAlt,
                    expectedAlt: nextAlt
                });
            }
            queueMicrotask(syncWithCursor);
        }

        input.addEventListener('keydown', function (event) {
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

    function render(image, state) {
        clearWidget();

        const root = document.createElement('div');
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
            message.textContent = config.requestFailed;
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

        activeWidget = register_codemirror.addLineWidget(image.line, root);
        activeSource = image.src;
    }

    function syncWithCursor() {
        const image = register_codemirror.getCursorImage();
        if (!image) {
            const state = activeSource ? requestStates.get(activeSource) : null;
            if (!state || state.status !== 'generating') {
                clearWidget();
            }
            return;
        }

        render(image, currentState(image));
    }

    async function generate(image) {
        const previous = requestStates.get(image.src);
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
        requestStates.set(image.src, state);
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
            const response = await fetch(config.url, {
                method: 'POST',
                body: data,
                signal: controller.signal,
                registerHandleErrorsInline: true
            });
            let responseData = null;
            try {
                responseData = await response.json();
            } catch {
                throw new Error(config.requestFailed);
            }
            if (!response.ok || !responseData.success || typeof responseData.result !== 'string') {
                throw new Error(config.requestFailed);
            }

            if (!register_codemirror.replaceImageAlt(image.src, state.expectedAlt, responseData.result)) {
                requestStates.delete(image.src);
                syncWithCursor();
                return;
            }

            requestStates.set(image.src, {
                status: 'ready',
                alt: responseData.result,
                expectedAlt: responseData.result
            });
            const updated = register_codemirror.getImageBySrc(image.src, responseData.result);
            if (updated) {
                render(updated, requestStates.get(image.src));
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            requestStates.set(image.src, {
                status: 'error',
                alt: state.expectedAlt,
                expectedAlt: state.expectedAlt
            });
            const current = register_codemirror.getImageBySrc(image.src, state.expectedAlt);
            if (current) {
                render(current, requestStates.get(image.src));
            }
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
        queueMicrotask(syncWithCursor);
    });
}
