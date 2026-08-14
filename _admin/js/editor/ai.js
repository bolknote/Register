/** AI-assisted editorial actions. Every result is applied immediately. */

import {s2_codemirror} from './codemirror.js';
import {findCorrectionRanges} from './text/corrections.js';

export function initAiTools(form, config) {
    if (!form || !config || !config.enabled) {
        return;
    }

    const container = document.getElementById('content-editor-ai-tools');
    const status = document.getElementById('ai-tools-status');
    const actionButtons = Array.from(container ? container.querySelectorAll('[data-ai-action]') : []);
    if (!container || !status) {
        return;
    }

    let activeController = null;

    function setBusy(busy) {
        container.setAttribute('aria-busy', busy ? 'true' : 'false');
        actionButtons.forEach(function (button) {
            button.disabled = busy;
        });
    }

    function setStatus(message, error) {
        status.textContent = message || '';
        status.classList.toggle('is-error', !!error);
    }

    async function runAction(action) {
        const snapshot = s2_codemirror.getSelectionSnapshot();
        if (snapshot.text.trim() === '') {
            setStatus(config.emptyText, true);
            return;
        }

        if (activeController) {
            activeController.abort();
        }
        const controller = new AbortController();
        activeController = controller;
        setBusy(true);
        setStatus(config.working, false);

        const data = new FormData();
        data.set('entity_name', config.entityName);
        data.set('content_id', String(config.contentId || 0));
        data.set('ai_action', action);
        data.set('title', form.elements.title ? form.elements.title.value : '');
        data.set('text', snapshot.text);
        const csrfInput = form.elements['__csrf_token'];
        data.set('__csrf_token', csrfInput ? csrfInput.value : '');

        try {
            const response = await fetch(config.url, {
                method: 'POST',
                body: data,
                signal: controller.signal,
                s2HandleErrorsInline: true
            });
            let responseData = null;
            try {
                responseData = await response.json();
            } catch {
                throw new Error(config.requestFailed);
            }
            if (!response.ok || !responseData.success || typeof responseData.result !== 'string') {
                throw new Error(responseData && responseData.message ? responseData.message : config.requestFailed);
            }

            if (action === 'title') {
                const titleInput = form.elements.title;
                if (titleInput) {
                    titleInput.value = responseData.result;
                    titleInput.dispatchEvent(new Event('input', {bubbles: true}));
                    titleInput.focus();
                }
                setStatus('', false);
                return;
            }

            if (action === 'tags') {
                const tagsInput = form.elements.tags;
                if (tagsInput) {
                    tagsInput.value = responseData.result;
                    tagsInput.dispatchEvent(new Event('input', {bubbles: true}));
                    tagsInput.dispatchEvent(new Event('focus_tag_editor.s2'));
                }
                setStatus('', false);
                return;
            }

            {
                const currentText = s2_codemirror.getValue().slice(snapshot.start, snapshot.end);
                if (currentText !== snapshot.text) {
                    setStatus(config.sourceChanged, true);
                    return;
                }

                if (responseData.result === snapshot.text) {
                    setStatus(action === 'proofread' ? config.proofreadClean : config.unchanged, false);
                    return;
                }

                s2_codemirror.replaceRangeWithHighlights(
                    responseData.result,
                    snapshot.start,
                    snapshot.end,
                    findCorrectionRanges(snapshot.text, responseData.result)
                );
                setStatus('', false);
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                setStatus(error.message || config.requestFailed, true);
            }
        } finally {
            if (activeController === controller) {
                activeController = null;
                setBusy(false);
            }
        }
    }

    actionButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            runAction(button.dataset.aiAction);
        });
    });
}
