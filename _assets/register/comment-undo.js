(function () {
    'use strict';

    const storageKey = 'register.commentUndo.v1';
    let notices = [];
    let container = null;

    function save() {
        try {
            sessionStorage.setItem(storageKey, JSON.stringify(notices));
        } catch (_) {
            // Undo still works on this page when browser storage is unavailable.
        }
    }

    function validItem(item) {
        if (!item || typeof item.url !== 'string' || !item.data || typeof item.data !== 'object') return false;
        try {
            return new URL(item.url, window.location.href).origin === window.location.origin
                && typeof item.data.undo_token === 'string';
        } catch (_) {
            return false;
        }
    }

    function render() {
        notices = notices.filter((notice) => notice.expires > Date.now() && notice.items.length > 0);
        if (container) container.remove();
        container = document.createElement('aside');
        container.className = 'comment-undo-notices';
        notices.forEach((notice) => {
            const form = document.createElement('form');
            form.className = 'comment-undo-notice';
            const message = document.createElement('span');
            message.setAttribute('role', 'status');
            message.textContent = notice.message;
            const button = document.createElement('button');
            button.type = 'submit';
            button.textContent = notice.label;
            const dismiss = document.createElement('button');
            dismiss.type = 'button';
            dismiss.textContent = '×';
            dismiss.setAttribute('aria-label', notice.dismissLabel);
            dismiss.addEventListener('click', () => {
                notices = notices.filter((entry) => entry !== notice);
                save();
                render();
            });
            form.append(message, button, dismiss);
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                button.disabled = true;
                dismiss.disabled = true;
                try {
                    while (notice.items.length > 0) {
                        const item = notice.items[0];
                        const response = await window.fetch(item.url, {
                            method: 'POST',
                            body: new URLSearchParams(item.data),
                            credentials: 'same-origin',
                            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
                        });
                        const payload = await response.json();
                        if (!response.ok || !payload.success) {
                            throw new Error(payload.message || payload.errors?.[0] || notice.errorLabel);
                        }
                        notice.items.shift();
                        save();
                    }
                    notices = notices.filter((entry) => entry !== notice);
                    save();
                    window.location.reload();
                } catch (error) {
                    message.setAttribute('role', 'alert');
                    message.textContent = error.message || notice.errorLabel;
                    button.disabled = false;
                    dismiss.disabled = false;
                }
            });
            container.appendChild(form);
        });
        if (notices.length > 0) document.body.appendChild(container);
    }

    function remember(payload, items) {
        const validItems = items.filter(validItem).map((item) => ({
            url: new URL(item.url, window.location.href).href,
            data: item.data
        }));
        if (validItems.length === 0) return;
        notices.push({
            message: payload.message || 'Comment deleted',
            label: payload.undo_label || 'Undo',
            dismissLabel: payload.dismiss_label || 'Close',
            errorLabel: payload.undo_error || 'Unable to undo deletion.',
            items: validItems,
            expires: Date.now() + 600000
        });
        save();
        render();
    }

    window.RegisterCommentUndo = {remember};
    document.addEventListener('DOMContentLoaded', () => {
        try {
            const stored = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
            if (Array.isArray(stored)) {
                notices = stored.filter((notice) => notice && Array.isArray(notice.items)
                    && notice.items.every(validItem) && typeof notice.expires === 'number');
            }
        } catch (_) {
            notices = [];
        }
        render();
    });
}());
