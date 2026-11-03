(() => {
    'use strict';
    const drafts = new Map();
    const prefix = 'register_comment_draft:';
    const intentKey = 'register_comment_send';
    const path = form => { const url = new URL(form.action, location.href); return url.pathname + url.search; };
    const source = form => form.querySelector('.comment-editor-source');
    const value = (form, selector) => form.querySelector(selector)?.value || '';
    const authenticated = form => !!form.querySelector('.comment-authenticated-identity');
    const nonce = () => crypto.randomUUID?.()
        || Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');

    function read(key) {
        for (const kind of ['sessionStorage', 'localStorage']) {
            try { const data = JSON.parse(window[kind].getItem(prefix + key) || 'null'); if (data) return data; } catch (_) { /* Storage may be unavailable. */ }
        }
        return drafts.get(key) || null;
    }

    function save(form) {
        const text = window.RegisterCommentEditor?.html(form) ?? source(form)?.value ?? '';
        const data = {text, id: value(form, '.comment-form-id'), name: value(form, '#comment-name'),
            email: value(form, '#comment-email'), parent: value(form, '.comment-parent-id'),
            number: value(form, '.comment-reply-number'), replyName: value(form, '.comment-reply-name'),
            subscribed: !!form.querySelector('#subscribed')?.checked};
        drafts.set(path(form), data);
        let stored = false;
        for (const kind of ['localStorage', 'sessionStorage']) {
            try { window[kind].setItem(prefix + path(form), JSON.stringify(data)); stored = true; } catch (_) { /* Keep the live draft. */ }
        }
        return stored;
    }

    function clearSent(id) {
        for (const [key, data] of drafts) if (data.id === id) drafts.delete(key);
        for (const kind of ['localStorage', 'sessionStorage']) {
            try {
                const storage = window[kind];
                for (const key of Object.keys(storage)) {
                    if (key.startsWith(prefix) && JSON.parse(storage.getItem(key) || 'null')?.id === id) storage.removeItem(key);
                }
            } catch (_) { /* Storage may be unavailable. */ }
        }
    }

    function clearPath(key) {
        const data = read(key);
        if (data?.id) {
            clearSent(data.id);
            try { localStorage.removeItem('comment_text_' + data.id); } catch (_) { /* Storage may be unavailable. */ }
        }
    }

    function restore(form, data) {
        if (!data || data.id !== value(form, '.comment-form-id') || typeof data.text !== 'string') return;
        if (!source(form).value) {
            source(form).value = data.text;
            source(form).dispatchEvent(new Event('input', {bubbles: true}));
        }
        const restoreReply = !value(form, '.comment-parent-id') && !!data.parent;
        for (const [selector, key] of [['#comment-name', 'name'], ['#comment-email', 'email'],
            ['.comment-parent-id', 'parent'], ['.comment-reply-number', 'number'], ['.comment-reply-name', 'replyName']]) {
            const field = form.querySelector(selector);
            if (field && (!field.value || (restoreReply && ['parent', 'number', 'replyName'].includes(key))) && typeof data[key] === 'string') field.value = data[key];
        }
        if (restoreReply) {
            const context = form.closest('.comment-form-block')?.querySelector('.comment-reply-context');
            const target = context?.querySelector('.comment-reply-target');
            if (context && target) {
                context.hidden = false;
                target.textContent = data.replyName || `№ ${data.number}`;
                target.href = `#${encodeURIComponent(data.number)}`;
            }
        }
        const subscribed = form.querySelector('#subscribed');
        if (subscribed && typeof data.subscribed === 'boolean') subscribed.checked = data.subscribed;
    }

    function enhance() {
        const form = document.querySelector('form[data-comment-submit-flow]');
        if (!form || form.dataset.commentSubmitReady) return;
        form.dataset.commentSubmitReady = '1';
        const sent = document.cookie.split('; ').find(cookie => cookie.startsWith('comment_form_sent='))?.slice('comment_form_sent='.length);
        const sentPath = document.cookie.split('; ').find(cookie => cookie.startsWith('comment_path_sent='))?.slice('comment_path_sent='.length);
        if (sentPath) {
            try { clearPath(decodeURIComponent(sentPath)); } catch (_) { /* Ignore an invalid receipt. */ }
        } else if (sent) clearSent(sent);
        restore(form, read(path(form)));
        if (!authenticated(form) && window.RegisterPublicAuth) {
            const identity = form.querySelector('.comment-identity');
            if (identity) {
                identity.hidden = true;
                identity.querySelectorAll('input').forEach(input => { input.disabled = true; });
            }
        }
        const url = new URL(location.href);
        const marker = url.searchParams.get('comment_send');
        if (!marker) return;
        let intent;
        try { intent = JSON.parse(sessionStorage.getItem(intentKey) || 'null'); sessionStorage.removeItem(intentKey); } catch (_) { /* Do not send without a matching intent. */ }
        url.searchParams.delete('comment_send');
        history.replaceState(history.state, '', url.pathname + url.search + url.hash);
        if (intent?.nonce === marker && intent.path === path(form) && authenticated(form) && source(form).value) {
            requestAnimationFrame(() => { if (form.isConnected) form.requestSubmit(); });
        }
    }

    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const body = form.closest('.public-auth-body');
        if (body?.dataset.commentFlow === '1' && form.matches('.public-auth-provider-form, .public-auth-password-form')) {
            const comment = document.querySelector('form[data-comment-submit-flow]');
            if (!comment) return;
            const target = new URL(form.querySelector('[name="return_path"]')?.value || new URL(form.action).searchParams.get('return'), location.href);
            const nonce = target.searchParams.get('comment_send');
            try {
                if (!save(comment) || !nonce) throw new Error('No recoverable draft');
                sessionStorage.setItem(intentKey, JSON.stringify({nonce, path: path(comment)}));
            } catch (_) {
                event.preventDefault(); event.stopImmediatePropagation();
                window.RegisterPublicAuth?.error(comment.dataset.commentStorageError);
            }
            return;
        }
        if (!form.matches('[data-comment-submit-flow]')) return;
        const text = window.RegisterCommentEditor?.html(form) ?? source(form)?.value ?? '';
        if (authenticated(form) || form.dataset.commentEmailReady === '1') { save(form); return; }
        if (!window.RegisterPublicAuth) return;
        event.preventDefault();
        if (!text.trim()) {
            let error = form.querySelector('[data-comment-submit-error]');
            if (!error) { error = document.createElement('p'); error.dataset.commentSubmitError = ''; error.setAttribute('role', 'alert'); form.prepend(error); }
            error.textContent = form.dataset.commentEmpty;
            window.RegisterCommentEditor?.focus(form) || source(form)?.focus();
            return;
        }
        form.querySelector('[data-comment-submit-error]')?.remove();
        save(form);
        const target = new URL(form.action, location.href);
        target.searchParams.set('comment_send', nonce());
        target.hash = 'add-comment';
        window.RegisterPublicAuth.openComment(form, target.pathname + target.search + target.hash);
    }, true);

    document.addEventListener('input', event => {
        const form = event.target.closest?.('form[data-comment-submit-flow]');
        if (form) save(form);
    });
    document.addEventListener('change', event => {
        const form = event.target.closest?.('form[data-comment-submit-flow]');
        if (form) save(form);
    });
    window.RegisterCommentSubmission = {clearSent, clearPath};
    document.addEventListener('DOMContentLoaded', enhance);
    document.addEventListener('register:fragment-updated', enhance);
    if (document.readyState !== 'loading') enhance();
})();
