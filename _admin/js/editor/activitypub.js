/** Side-effect-free ActivityPub preview for the current unsaved editor form. */

import {register_codemirror} from './codemirror.js';
import {formErrorMessages} from './utils/form-errors.js';
import {editorDeps} from './deps.js';
import '../../../_assets/register/editor/request.js';

export function initActivityPubPreview(form, config) {
    if (!form || !config || !config.enabled) {
        return;
    }

    const panel = form.querySelector('[data-activitypub-editor-panel]');
    const button = panel?.querySelector('[data-activitypub-preview-button]');
    const status = panel?.querySelector('[data-activitypub-preview-status]');
    const result = panel?.querySelector('[data-activitypub-preview-result]');
    const frame = panel?.querySelector('[data-activitypub-preview-frame]');
    const json = panel?.querySelector('[data-activitypub-preview-json]');
    const metadata = panel?.querySelector('[data-activitypub-preview-metadata]');
    const provisional = panel?.querySelector('[data-activitypub-preview-provisional]');
    if (!panel || !button || !status || !result || !frame || !json || !metadata || !provisional) {
        return;
    }

    // Keep the completed request as well: its displayed result or error belongs
    // to the same form snapshot and must be invalidated by subsequent edits.
    let previewController = null;
    let previewSnapshot = '';
    const requestFailed = config.failed || 'Unable to build the preview. Please try again.';

    function setBusy(busy) {
        button.disabled = busy;
        panel.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    function setStatus(message, isError) {
        status.textContent = message || '';
        status.classList.toggle('is-error', Boolean(isError));
    }

    function invalidatePreview() {
        if (!previewController) return;
        previewController.abort();
        previewController = null;
        previewSnapshot = '';
        setBusy(false);
        result.hidden = true;
        setStatus(config.changed, false);
    }

    function invalidateChangedPreview() {
        if (!previewController) return;
        register_codemirror.flip();
        // Committing an already-synced tag can emit input/change without
        // changing what was sent to the server.
        if (JSON.stringify(Array.from(new FormData(form))) !== previewSnapshot) {
            invalidatePreview();
        }
    }

    function previewDocument(content) {
        const policy = "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'";
        return '<!doctype html><html><head><meta charset="utf-8">'
            + '<meta http-equiv="Content-Security-Policy" content="' + policy + '">'
            + '<style>html{color-scheme:light dark}body{font:16px/1.55 system-ui,sans-serif;margin:1rem;overflow-wrap:anywhere}'
            + 'a{color:inherit}pre{white-space:pre-wrap}blockquote{border-inline-start:3px solid #999;margin-inline:0;padding-inline-start:1rem}</style>'
            + '</head><body>' + content + '</body></html>';
    }

    async function runPreview() {
        if (form.inert) return;
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        previewController?.abort();
        const controller = new AbortController();
        previewController = controller;
        setBusy(true);
        setStatus(config.working, false);
        result.hidden = true;

        register_codemirror.flip();
        const data = new FormData(form);
        previewSnapshot = JSON.stringify(Array.from(data));
        data.set('entity_name', config.entityName);
        data.set('content_id', String(config.contentId || 0));

        try {
            const {response, data: payload} = await window.RegisterEditorRequest.requestJson(config.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: data,
                signal: controller.signal,
                registerHandleErrorsInline: true
            }, {
                timeoutMs: editorDeps.saveTimeoutMs,
                timeoutMessage: config.timeout || requestFailed
            });
            if (controller.signal.aborted || previewController !== controller) return;
            if (!response.ok || !payload || payload.success !== true) {
                throw new Error(formErrorMessages(form, payload, requestFailed).join('\n'));
            }

            setStatus(payload.message || '', false);
            metadata.textContent = [payload.owner_handle, payload.canonical_url].filter(Boolean).join(' · ');
            provisional.textContent = payload.provisional_message || '';
            provisional.hidden = provisional.textContent === '';
            json.textContent = payload.pretty_json || '';
            frame.srcdoc = previewDocument(payload.content_html || '<p>' + config.noObject + '</p>');
            result.hidden = false;
        } catch (error) {
            if (!controller.signal.aborted && previewController === controller && error.name !== 'AbortError') {
                setStatus(error.message || requestFailed, true);
            }
        } finally {
            if (previewController === controller) {
                setBusy(false);
            }
        }
    }

    form.addEventListener('input', invalidateChangedPreview);
    form.addEventListener('change', invalidateChangedPreview);
    form.addEventListener('reset', invalidatePreview);
    register_codemirror.onChange(invalidateChangedPreview);
    document.addEventListener('save_article_start.register', invalidatePreview);
    document.addEventListener('save_article_end.register', invalidatePreview);
    button.addEventListener('click', runPreview);
}
