/** Side-effect-free ActivityPub preview for the current unsaved editor form. */

import {s2_codemirror} from './codemirror.js';

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

    let activeController = null;

    function setBusy(busy) {
        button.disabled = busy;
        panel.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    function setStatus(message, isError) {
        status.textContent = message || '';
        status.classList.toggle('is-error', Boolean(isError));
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
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        if (activeController) {
            activeController.abort();
        }
        const controller = new AbortController();
        activeController = controller;
        setBusy(true);
        setStatus(config.working, false);
        result.hidden = true;

        s2_codemirror.flip();
        const data = new FormData(form);
        data.set('entity_name', config.entityName);
        data.set('content_id', String(config.contentId || 0));

        try {
            const response = await fetch(config.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: data,
                signal: controller.signal,
                s2HandleErrorsInline: true
            });
            let payload = null;
            try {
                payload = await response.json();
            } catch {
                throw new Error(config.failed);
            }
            if (!response.ok || !payload || payload.success !== true) {
                throw new Error(payload && payload.message ? payload.message : config.failed);
            }

            setStatus(payload.message || '', false);
            metadata.textContent = [payload.owner_handle, payload.canonical_url].filter(Boolean).join(' · ');
            provisional.textContent = payload.provisional_message || '';
            provisional.hidden = provisional.textContent === '';
            json.textContent = payload.pretty_json || '';
            frame.srcdoc = previewDocument(payload.content_html || '<p>' + config.noObject + '</p>');
            result.hidden = false;
        } catch (error) {
            if (error.name !== 'AbortError') {
                setStatus(error.message || config.failed, true);
            }
        } finally {
            if (activeController === controller) {
                activeController = null;
                setBusy(false);
            }
        }
    }

    button.addEventListener('click', runPreview);
}
