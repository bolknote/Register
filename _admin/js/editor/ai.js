/** AI-assisted editorial actions. Every result is applied immediately. */

import {register_codemirror} from './codemirror.js';
import {findCorrectionRanges} from './text/corrections.js';
import {editorDeps} from './deps.js';
import '../../../_assets/register/editor/request.js';

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
    let statusTimer;
    const requestFailed = config.requestFailed || 'Unable to generate text. Please try again.';

    function setBusy(busy) {
        container.setAttribute('aria-busy', busy ? 'true' : 'false');
        actionButtons.forEach(function (button) {
            button.disabled = busy;
        });
    }

    function setStatus(message, error, phase = null) {
        clearTimeout(statusTimer);
        status.replaceChildren();
        status.classList.toggle('is-error', !!error);
        if (!phase) {
            status.textContent = message || '';
            return;
        }
        const icon = document.createElement('span');
        icon.className = 'ai-tools-status-icon';
        icon.dataset.phase = phase;
        icon.title = message;
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = '<span class="ai-tools-spinner"></span><svg viewBox="0 0 24 24"><path d="m8 3 1.5 4.5L14 9l-4.5 1.5L8 15l-1.5-4.5L2 9l4.5-1.5Z"/><path class="ai-tools-check" pathLength="1" d="m11 17 3 3 8-9"/></svg>';
        const label = document.createElement('span');
        label.className = 'ai-tools-status-label';
        label.textContent = message;
        status.append(icon, label);
        if (phase !== 'working') statusTimer = setTimeout(() => setStatus('', false), 900);
    }

    document.addEventListener('save_article_start.register', function () {
        if (activeController) {
            activeController.abort();
            activeController = null;
            setBusy(false);
            setStatus('', false);
        }
    });

    async function runAction(action) {
        if (form.inert) return;
        const snapshot = register_codemirror.getSelectionSnapshot();
        if (snapshot.text.trim() === '') {
            setStatus(config.emptyText, true);
            return;
        }

        if (activeController) {
            activeController.abort();
        }
        const controller = new AbortController();
        const trackedRange = snapshot.hasSelection
            ? register_codemirror.trackRange(snapshot.start, snapshot.end) : null;
        controller.signal.addEventListener('abort', () => trackedRange?.clear(), {once: true});
        const targetInput = ['title', 'tags'].includes(action) ? form.elements[action] : null;
        const targetValue = targetInput?.value;
        activeController = controller;
        setBusy(true);
        setStatus(config.working, false, 'working');

        const data = new FormData();
        data.set('entity_name', config.entityName);
        data.set('content_id', String(config.contentId || 0));
        data.set('ai_action', action);
        data.set('title', form.elements.title ? form.elements.title.value : '');
        data.set('text', snapshot.text);
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
                timeoutMessage: config.timeout || requestFailed,
                networkMessage: config.networkError || 'Unable to get a response from the site. Your text is unchanged; retry the AI request.'
            });
            if (controller.signal.aborted || activeController !== controller) return;
            if (!response.ok || !responseData?.success || typeof responseData.result !== 'string') {
                throw new Error(responseData?.message || requestFailed);
            }

            const currentText = register_codemirror.getValue();
            const range = snapshot.hasSelection ? trackedRange?.find() : snapshot;
            const sourceChanged = snapshot.hasSelection
                ? !range || currentText.slice(range.start, range.end) !== snapshot.text
                : currentText !== snapshot.text;
            if (sourceChanged || (targetInput && targetInput.value !== targetValue)) {
                setStatus(config.sourceChanged, true);
                return;
            }

            if (action === 'title') {
                const titleInput = form.elements.title;
                if (titleInput) {
                    titleInput.value = responseData.result;
                    titleInput.dispatchEvent(new Event('input', {bubbles: true}));
                    titleInput.focus();
                }
                setStatus(config.applied || 'AI changes applied.', false, 'applied');
                return;
            }

            if (action === 'tags') {
                const tagsInput = form.elements.tags;
                if (tagsInput) {
                    tagsInput.value = responseData.result;
                    tagsInput.dispatchEvent(new Event('input', {bubbles: true}));
                    tagsInput.dispatchEvent(new Event('focus_tag_editor.register'));
                }
                setStatus(config.applied || 'AI changes applied.', false, 'applied');
                return;
            }

            {
                if (responseData.result === snapshot.text) {
                    setStatus(action === 'proofread' ? config.proofreadClean : config.unchanged, false, 'clean');
                    return;
                }

                register_codemirror.replaceRangeWithHighlights(
                    responseData.result,
                    range.start,
                    range.end,
                    findCorrectionRanges(snapshot.text, responseData.result)
                );
                setStatus(config.applied || 'AI changes applied.', false, 'applied');
            }
        } catch (error) {
            if (!controller.signal.aborted && activeController === controller && error.name !== 'AbortError') {
                setStatus(error.message || requestFailed, true);
            }
        } finally {
            trackedRange?.clear();
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
