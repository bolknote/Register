(function () {
    'use strict';

    const storageKey = 'register.commentUndo.v2';
    const legacyKey = 'register.commentUndo.v1';
    const user = document.querySelector('meta[name="register-comment-undo-user"]')?.content;
    const entries = new Map();
    const pending = new Set();
    const views = new Map();
    let container = null;
    let collapsed = false;
    let timer = null;
    const defaults = {
        deleted: 'Comment deleted', restore: 'Undo deletion', restoring: 'Restoring…',
        restored: 'Comment restored', expired: 'The restoration period has ended.',
        remaining: 'Time to restore: %time%', history: 'Recent deletions (%count%)',
        collapse: 'Collapse. Restoration remains available in recent deletions.',
        network_error: 'No response from the server. Check your connection and try again.',
        server_error: 'The server could not restore the comment. Try again.',
        session_error: 'Your session expired. Refresh the page.',
        changed: 'The comment changed after deletion. Refresh the discussion to see its current state.',
        already_restored: 'The comment has already been restored.',
        progress: 'Restored: %done% of %total%', restore_all: 'Restore all',
        open: 'Open comment', close: 'Close'
    };

    function save() {
        if (!user) return;
        try {
            sessionStorage.setItem(storageKey, JSON.stringify({user, entries: [...entries.values()], collapsed}));
        } catch (_) {
            // Recovery controls remain available in memory when storage is blocked.
        }
    }

    function safeUrl(value) {
        try {
            const url = new URL(value, window.location.href);
            return url.origin === window.location.origin && /^https?:$/.test(url.protocol) ? url.href : null;
        } catch (_) {
            return null;
        }
    }

    function validItem(item) {
        return item && typeof item.url === 'string' && safeUrl(item.url) !== null
            && item.data && typeof item.data === 'object' && !Array.isArray(item.data)
            && typeof item.data.undo_token === 'string' && item.data.undo_token !== ''
            && Object.values(item.data).every((value) => typeof value === 'string' || typeof value === 'number');
    }

    function labels(entry) { return {...defaults, ...entry.labels}; }
    function text(template, values) {
        return Object.entries(values).reduce((result, [key, value]) => result.replaceAll('%' + key + '%', String(value)), template);
    }
    function element(tag, className, content) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (content) node.textContent = content;
        return node;
    }
    function button(className, content, listener) {
        const node = element('button', className, content);
        node.type = 'button';
        node.addEventListener('click', listener);
        return node;
    }

    function onCurrentPage(entry) {
        if (!entry.commentUrl) return false;
        const url = new URL(entry.commentUrl);
        return url.pathname === window.location.pathname && url.search === window.location.search;
    }

    function openComment(entry) {
        if (onCurrentPage(entry)) {
            window.history.replaceState(window.history.state, '', entry.commentUrl);
            window.location.reload();
        } else if (entry.commentUrl) window.location.assign(entry.commentUrl);
    }

    function updateView(entry, view) {
        const l = labels(entry);
        const busy = pending.has(entry.id);
        const active = entry.state === 'deleted';
        view.restore.disabled = !active || pending.size > 0;
        view.restore.textContent = busy ? l.restoring : l.restore;
        view.form.setAttribute('aria-busy', busy ? 'true' : 'false');
        view.message.textContent = entry.error || (entry.state === 'restored' ? (entry.alreadyRestored ? l.already_restored : l.restored)
            : entry.state === 'expired' ? l.expired : entry.state === 'unavailable' ? l.changed : l.deleted);
        view.message.setAttribute('role', entry.error ? 'alert' : 'status');
        view.form.dataset.state = entry.state;
        const seconds = Math.max(0, Math.ceil((entry.expires - Date.now()) / 1000));
        view.time.textContent = active ? text(l.remaining, {time: Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0')}) : '';
        view.restore.hidden = !active;
        view.link.hidden = active || !entry.commentUrl;
    }

    function createView(entry, inline = false) {
        const l = labels(entry);
        const form = element('form', 'comment-undo-notice' + (inline ? ' comment-undo-inline' : ''));
        form.dataset.undoEntry = entry.id;
        const content = element('div', 'comment-undo-content');
        content.append(element('strong', 'comment-undo-context', entry.context || l.deleted));
        if (entry.excerpt) content.append(element('p', 'comment-undo-excerpt', entry.excerpt));
        const message = element('p', 'comment-undo-message');
        const time = element('span', 'comment-undo-time');
        // The countdown is outside the live region to avoid announcing every second.
        content.append(message, time);
        const actions = element('div', 'comment-undo-actions');
        const restore = button('comment-undo-restore', l.restore, () => restoreEntries([entry]));
        restore.dataset.undoFocus = entry.id;
        const link = element('a', 'comment-undo-link', l.open);
        if (entry.commentUrl) link.href = entry.commentUrl;
        link.addEventListener('click', (event) => { event.preventDefault(); openComment(entry); });
        actions.append(restore, link);
        form.addEventListener('submit', (event) => { event.preventDefault(); restoreEntries([entry]); });
        form.append(content, actions);
        const view = {form, restore, message, time, link, inline};
        if (!views.has(entry.id)) views.set(entry.id, []);
        views.get(entry.id).push(view);
        updateView(entry, view);
        return form;
    }

    function renderInline() {
        document.querySelectorAll('.comment-undo-inline, .comment-undo-placeholder').forEach((node) => node.remove());
        // A live patch can replace the thread without replacing the fixed history panel.
        entries.forEach((entry, id) => views.set(id, (views.get(id) || []).filter((view) => !view.inline)));
        for (const entry of entries.values()) {
            if (!onCurrentPage(entry) || !entry.data.comment_id || entry.state === 'restored') continue;
            let comment = document.querySelector('.comment-item[data-comment-id="' + entry.commentId + '"]');
            if (!comment) {
                const thread = document.querySelector('.comment-thread');
                if (!thread) continue;
                comment = element('article', 'comment-item is-deleted comment-undo-placeholder');
                comment.dataset.commentId = String(entry.commentId);
                comment.dataset.moderationState = 'deleted';
                comment.setAttribute('role', 'listitem');
                const anchor = element('span', 'comment-stable-anchor');
                anchor.id = 'comment-' + entry.commentId;
                comment.append(anchor);
                const next = [...thread.children].find((node) => Number(node.id) > Number(entry.data.comment_anchor));
                if (next) thread.insertBefore(comment, next);
                else thread.append(comment);
            }
            if (comment.dataset.moderationState !== 'deleted') continue;
            const host = comment.querySelector(':scope > .comment-tombstone') || comment;
            host.append(createView(entry, true));
        }
    }

    function focusRestored() {
        for (const entry of entries.values()) {
            if (!entry.focus || !onCurrentPage(entry)) continue;
            const target = document.getElementById('admin-comment-' + entry.commentId)
                || document.querySelector('.comment-item[data-comment-id="' + entry.commentId + '"]');
            if (!target || target.dataset.moderationState === 'deleted') continue;
            entry.focus = false;
            save();
            target.classList.add('comment-undo-restored');
            target.setAttribute('tabindex', '-1');
            target.focus({preventScroll: true});
            target.scrollIntoView({block: 'center', behavior: 'instant'});
            window.setTimeout(() => target.classList.remove('comment-undo-restored'), 5000);
            break;
        }
    }

    function render() {
        const focusKey = document.activeElement?.dataset.undoFocus;
        const scrollTop = container?.querySelector('.comment-undo-history')?.scrollTop || 0;
        if (container) container.remove();
        views.clear();
        container = element('aside', 'comment-undo-notices');
        const current = [...entries.values()];
        if (current.length === 0) {
            renderInline();
            return;
        }
        const l = labels(current[current.length - 1]);
        const panelId = 'comment-undo-history';
        const header = element('div', 'comment-undo-header');
        const toggle = button('comment-undo-toggle', text(l.history, {count: current.length}), () => {
            collapsed = !collapsed; save(); render();
            container.querySelector('.comment-undo-toggle')?.focus();
        });
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-controls', panelId);
        header.append(toggle);
        const collapse = button('comment-undo-collapse', '×', () => {
            collapsed = true; save(); render();
            container.querySelector('.comment-undo-toggle')?.focus();
        });
        collapse.setAttribute('aria-label', l.collapse);
        collapse.hidden = collapsed;
        header.append(collapse);
        const panel = element('div', 'comment-undo-history');
        panel.id = panelId;
        panel.hidden = collapsed;
        const deleted = current.filter((entry) => entry.state === 'deleted');
        if (deleted.length > 1) {
            const restoreAll = button('comment-undo-restore-all', l.restore_all, () => restoreEntries(deleted));
            restoreAll.disabled = pending.size > 0;
            panel.append(restoreAll);
        }
        const groups = new Map();
        current.forEach((entry) => {
            if (!groups.has(entry.group)) groups.set(entry.group, []);
            groups.get(entry.group).push(entry);
        });
        groups.forEach((group) => {
            if (group.length > 1) {
                const progress = element('p', 'comment-undo-progress', text(l.progress, {
                    done: group.filter((entry) => entry.state === 'restored').length, total: group.length
                }));
                progress.setAttribute('role', 'status');
                panel.append(progress);
            }
            group.forEach((entry) => panel.append(createView(entry)));
        });
        container.append(header, panel);
        document.body.append(container);
        panel.scrollTop = scrollTop;
        renderInline();
        if (focusKey) {
            const view = views.get(focusKey)?.[0];
            if (view) (view.restore.hidden ? view.link : view.restore).focus();
        }
        focusRestored();
        if (timer === null) timer = window.setInterval(tick, 1000);
    }

    function tick() {
        let changed = false;
        const now = Date.now();
        entries.forEach((entry, id) => {
            const retainedUntil = entry.state === 'restored' ? entry.updated : entry.expires;
            if (now > retainedUntil + 60000 && !pending.has(id)) {
                entries.delete(id); changed = true;
            } else if (entry.state === 'deleted' && entry.expires <= now && !pending.has(id)) {
                entry.state = 'expired'; entry.error = ''; changed = true;
            }
        });
        if (changed) { save(); render(); }
        else entries.forEach((entry, id) => views.get(id)?.forEach((view) => updateView(entry, view)));
        if (entries.size === 0 && timer !== null) { window.clearInterval(timer); timer = null; }
    }

    async function requestRestore(entry) {
        const controller = new AbortController();
        let timeout;
        try {
            return await Promise.race([
                (async () => {
                    const response = await window.fetch(entry.url, {
                        method: 'POST', body: new URLSearchParams(entry.data), credentials: 'same-origin',
                        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                        signal: controller.signal, registerHandleErrorsInline: true
                    });
                    const payload = await response.json().catch(() => null);
                    return {response, payload};
                })(),
                new Promise((_, reject) => {
                    timeout = window.setTimeout(() => { controller.abort(); reject(new Error('timeout')); }, 10000);
                })
            ]);
        } finally {
            window.clearTimeout(timeout);
        }
    }

    async function restoreEntries(selected) {
        if (pending.size > 0) return;
        const restored = [];
        for (const entry of selected) {
            if (entry.state !== 'deleted') continue;
            if (entry.expires <= Date.now()) { entry.state = 'expired'; continue; }
            pending.add(entry.id);
            entry.error = '';
            render();
            try {
                const {response, payload} = await requestRestore(entry);
                const code = payload?.code;
                if ((response.ok && payload?.success) || code === 'undo_already_restored') {
                    entry.state = 'restored';
                    entry.alreadyRestored = code === 'undo_already_restored';
                    collapsed = false;
                    entry.updated = Date.now();
                    entry.focus = true;
                    restored.push(entry);
                } else {
                    const l = labels(entry);
                    if (code === 'undo_expired') entry.state = 'expired';
                    else if (code === 'undo_changed' || response.status === 404) {
                        entry.state = 'unavailable'; entry.error = l.changed;
                    } else if ([401, 403, 422].includes(response.status)) {
                        entry.state = 'unavailable'; entry.error = l.session_error;
                    } else entry.error = l.server_error;
                }
            } catch (_) {
                entry.error = labels(entry).network_error;
            } finally {
                pending.delete(entry.id);
                save(); render();
            }
            if (entry.state === 'deleted' && entry.error) break;
        }
        save(); render();
        if (restored.length === 0) return;
        const last = restored[restored.length - 1];
        if (document.querySelector('meta[name="register-live-updates"]') && onCurrentPage(last)) {
            document.dispatchEvent(new CustomEvent('register:live-refresh'));
        } else if (selected.every((entry) => entry.state === 'restored') && last.commentUrl) {
            // Persist the success notice before navigation, then focus the restored row on arrival.
            openComment(last);
        }
    }

    function remember(payload, items, requestedAt = Date.now()) {
        const group = String(Date.now()) + ':' + items[0]?.data?.undo_token;
        for (const item of items.filter(validItem)) {
            if (entries.has(item.data.undo_token)) continue;
            const meta = {...payload, ...item};
            const deadline = Number(meta.undo_expires_at);
            const serverTime = Number(meta.undo_server_time);
            // Adjust for the browser clock without starting a fresh ten-minute period on reload.
            const expires = Number.isFinite(deadline) && Number.isFinite(serverTime)
                ? Math.min(Date.now(), requestedAt) + Math.max(0, Math.min(600000, deadline - serverTime))
                : Number.isFinite(item.expires) ? item.expires : Number(item.data.undo_token.split('.')[0]) * 1000;
            if (!Number.isFinite(expires)) continue;
            const commentId = Number(meta.undo_comment_id || item.data.comment_id);
            if (!Number.isSafeInteger(commentId) || commentId <= 0) continue;
            const commentUrl = safeUrl(item.comment_url || (item.data.return_to
                ? item.data.return_to + '#comment-' + commentId : window.location.href.split('#')[0] + '#admin-comment-' + commentId));
            entries.set(item.data.undo_token, {
                id: item.data.undo_token, group, url: safeUrl(item.url), data: item.data, commentId, commentUrl,
                context: typeof meta.undo_context === 'string' ? meta.undo_context : 'Comment #' + commentId,
                excerpt: typeof meta.undo_excerpt === 'string' ? meta.undo_excerpt : '',
                labels: {...defaults, ...meta.undo_labels}, expires,
                state: expires <= Date.now() ? 'expired' : 'deleted', error: ''
            });
        }
        collapsed = false;
        save(); render();
    }

    window.RegisterCommentUndo = {remember};
    document.addEventListener('DOMContentLoaded', () => {
        try {
            const stored = JSON.parse(sessionStorage.getItem(storageKey) || '{}');
            const sameUser = user && stored.user === user;
            collapsed = sameUser && stored.collapsed === true;
            for (const entry of sameUser && Array.isArray(stored.entries) ? stored.entries : []) {
                if (validItem(entry) && entry.id === entry.data.undo_token && Number.isFinite(entry.expires)
                    && Number.isSafeInteger(entry.commentId) && entry.commentId > 0
                    && ['deleted', 'expired', 'restored', 'unavailable'].includes(entry.state)
                    && (!entry.commentUrl || safeUrl(entry.commentUrl))
                ) entries.set(entry.id, entry);
            }
            // Preserve an Undo issued just before the interface was updated.
            const old = user ? JSON.parse(sessionStorage.getItem(legacyKey) || '[]') : [];
            if (Array.isArray(old)) old.forEach((notice) => {
                if (Array.isArray(notice?.items) && Number.isFinite(notice.expires)) {
                    remember(notice, notice.items.filter(validItem).map((item) => ({...item, expires: notice.expires,
                        undo_comment_id: item.data?.comment_id || new URL(item.url, window.location.href).searchParams.get('id') })));
                }
            });
            sessionStorage.removeItem(legacyKey);
            save();
            if (!user) sessionStorage.removeItem(storageKey);
        } catch (_) {
            // Immediate recovery still works without storage.
        }
        tick(); render();
    });
    document.addEventListener('register:live-synchronized', () => { renderInline(); focusRestored(); });
}());
