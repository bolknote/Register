/** AI-assisted editorial actions. Results are reviewed before they replace source text. */

import {s2_codemirror} from './codemirror.js';

export function initAiTools(form, config) {
    if (!form || !config || !config.enabled) {
        return;
    }

    const container = document.getElementById('content-editor-ai-tools');
    const status = document.getElementById('ai-tools-status');
    const resultPanel = document.getElementById('ai-result-panel');
    const resultText = document.getElementById('ai-result-text');
    const applyButton = document.getElementById('ai-result-apply');
    const copyButton = document.getElementById('ai-result-copy');
    const closeButton = document.getElementById('ai-result-close');
    const actionButtons = Array.from(container ? container.querySelectorAll('[data-ai-action]') : []);
    if (!container || !status || !resultPanel || !resultText || !applyButton || !copyButton || !closeButton) {
        return;
    }

    let activeController = null;
    let pendingResult = null;

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

    function closeResult() {
        pendingResult = null;
        resultText.value = '';
        resultPanel.hidden = true;
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
        closeResult();
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
            } catch (error) {
                throw new Error(config.requestFailed);
            }
            if (!response.ok || !responseData.success || typeof responseData.result !== 'string') {
                throw new Error(responseData && responseData.message ? responseData.message : config.requestFailed);
            }

            pendingResult = {
                action: action,
                source: snapshot.text,
                start: snapshot.start,
                end: snapshot.end,
                result: responseData.result
            };
            resultText.value = responseData.result;
            resultPanel.hidden = false;
            setStatus('', false);
            resultText.focus();
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

    function applyResult() {
        if (!pendingResult) {
            return;
        }

        if (pendingResult.action === 'title') {
            const titleInput = form.elements.title;
            if (titleInput) {
                titleInput.value = pendingResult.result;
                titleInput.dispatchEvent(new Event('input', {bubbles: true}));
                titleInput.focus();
            }
        } else if (pendingResult.action === 'tags') {
            const tagsInput = form.elements.tags;
            if (tagsInput) {
                tagsInput.value = pendingResult.result;
                tagsInput.dispatchEvent(new Event('input', {bubbles: true}));
                const details = tagsInput.closest('details');
                if (details) {
                    details.open = true;
                }
                tagsInput.focus();
            }
        } else {
            const currentText = s2_codemirror.getValue().slice(pendingResult.start, pendingResult.end);
            if (currentText !== pendingResult.source) {
                setStatus(config.sourceChanged, true);
                return;
            }
            s2_codemirror.replaceRangeByIndex(pendingResult.result, pendingResult.start, pendingResult.end);
        }

        closeResult();
        setStatus('', false);
    }

    async function copyResult() {
        if (!pendingResult) {
            return;
        }
        try {
            await navigator.clipboard.writeText(pendingResult.result);
        } catch (error) {
            resultText.select();
            document.execCommand('copy');
        }
        setStatus(config.copied, false);
    }

    actionButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            runAction(button.dataset.aiAction);
        });
    });
    applyButton.addEventListener('click', applyResult);
    copyButton.addEventListener('click', copyResult);
    closeButton.addEventListener('click', closeResult);
}
