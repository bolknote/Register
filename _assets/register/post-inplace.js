(() => {
    'use strict';

    function cardFor(element) {
        return element instanceof Element ? element.closest('.post-card[data-post-id]') : null;
    }

    function dispatch(name, root) {
        document.dispatchEvent(new CustomEvent(name, {detail: {root}}));
    }

    function destroyWidgets(root) {
        if (window.RegisterReactions && typeof window.RegisterReactions.destroy === 'function') {
            window.RegisterReactions.destroy(root);
        }
        dispatch('register:fragment-will-update', root);
    }

    function enhanceWidgets(root) {
        const enhancers = [
            [window.RegisterReactions, 'enhance'],
            [window.RegisterLocalTime, 'enhance'],
            [window.RegisterSyntaxHighlighting, 'highlight'],
            [window.RegisterMath, 'render'],
            [window.RegisterAudioPlayerLoader, 'enhance'],
        ];
        for (const [api, method] of enhancers) {
            if (api && typeof api[method] === 'function') {
                const result = api[method](root);
                if (result && typeof result.catch === 'function') {
                    result.catch(() => {});
                }
            }
        }
        dispatch('register:fragment-updated', root);
    }

    function unlock() {
        document.dispatchEvent(new CustomEvent('register:live-unlock'));
    }

    function refresh() {
        document.dispatchEvent(new CustomEvent('register:live-refresh'));
    }

    function clearError(scope) {
        const error = scope.querySelector('.post-inplace-error');
        if (error) {
            error.hidden = true;
            error.textContent = '';
        }
    }

    function clearStatus(card) {
        const status = card.querySelector(':scope > .post-inplace-status');
        if (status) {
            status.hidden = true;
            status.textContent = '';
        }
    }

    function showError(form, message) {
        const error = form.querySelector('.post-inplace-error');
        if (!error) {
            return;
        }
        error.textContent = message;
        error.hidden = false;
        error.focus?.();
    }

    function closeEditor(card, restoreFocus) {
        const form = card.querySelector(':scope > .post-inplace-edit-form');
        if (!form) {
            return;
        }
        form.reset();
        form.hidden = true;
        card.classList.remove('is-editing');
        clearError(form);
        if (restoreFocus) {
            card.querySelector(':scope > .post-inplace-tools .post-edit-start')?.focus();
        }
        unlock();
    }

    function closeConfirmation(card, restoreFocus) {
        const confirmation = card.querySelector(':scope > .post-delete-confirmation');
        if (!confirmation) {
            return;
        }
        confirmation.hidden = true;
        card.classList.remove('is-confirming');
        clearError(confirmation);
        if (restoreFocus) {
            card.querySelector(':scope > .post-inplace-tools .post-delete-start')?.focus();
        }
        unlock();
    }

    function closeOtherCards(activeCard) {
        document.querySelectorAll('.post-card.is-editing, .post-card.is-confirming').forEach((card) => {
            if (card === activeCard) {
                return;
            }
            closeEditor(card, false);
            closeConfirmation(card, false);
        });
    }

    function beginEdit(link) {
        const card = cardFor(link);
        const form = card?.querySelector(':scope > .post-inplace-edit-form');
        if (!card || !form) {
            return false;
        }

        closeOtherCards(card);
        closeConfirmation(card, false);
        card.classList.add('is-editing');
        form.hidden = false;
        clearError(form);
        clearStatus(card);
        const title = form.elements.namedItem('title');
        if (title instanceof HTMLInputElement) {
            title.focus();
            title.setSelectionRange(title.value.length, title.value.length);
        }

        return true;
    }

    function beginDelete(button) {
        const card = cardFor(button);
        const confirmation = card?.querySelector(':scope > .post-delete-confirmation');
        if (!card || !confirmation) {
            return;
        }

        closeOtherCards(card);
        closeEditor(card, false);
        card.classList.add('is-confirming');
        confirmation.hidden = false;
        clearError(confirmation);
        clearStatus(card);
        confirmation.querySelector('.post-delete-confirm')?.focus();
    }

    function parseBody(html) {
        const template = document.createElement('template');
        template.innerHTML = html;
        return template.content.querySelector('[data-post-inplace-body]');
    }

    function updateEditedCard(card, form, payload) {
        const title = card.querySelector(':scope > .post.head .post-title-text');
        const currentBody = card.querySelector(':scope > .post.body[data-post-inplace-body]');
        const replacementBody = typeof payload.body_html === 'string' ? parseBody(payload.body_html) : null;
        if (!title || !currentBody || !replacementBody || typeof payload.title !== 'string') {
            throw new Error(card.dataset.applyError || 'Unable to apply the updated post.');
        }

        title.textContent = payload.title;
        const confirmation = card.querySelector(':scope > .post-delete-confirmation');
        const warningTemplate = confirmation?.dataset.warningTemplate;
        if (confirmation && typeof warningTemplate === 'string') {
            const warning = warningTemplate.replace('%s', payload.title);
            confirmation.setAttribute('aria-label', warning);
            const warningText = confirmation.querySelector(':scope > p');
            if (warningText) {
                warningText.textContent = warning;
            }
        }
        destroyWidgets(currentBody);
        currentBody.replaceWith(replacementBody);
        enhanceWidgets(replacementBody);

        const titleField = form.elements.namedItem('title');
        const bodyField = form.elements.namedItem('body');
        if (titleField instanceof HTMLInputElement) {
            titleField.value = payload.title;
            titleField.defaultValue = payload.title;
        }
        if (bodyField instanceof HTMLTextAreaElement) {
            bodyField.defaultValue = bodyField.value;
        }
        card.querySelectorAll('input[name="revision"]').forEach((field) => {
            field.value = String(payload.revision);
            field.defaultValue = String(payload.revision);
        });

        form.hidden = true;
        card.classList.remove('is-editing');
        const status = card.querySelector(':scope > .post-inplace-status');
        if (status && typeof payload.message === 'string') {
            status.textContent = payload.message;
            status.hidden = false;
        }
        unlock();
        refresh();
        card.querySelector(':scope > .post-inplace-tools .post-edit-start')?.focus();
    }

    function removeDeletedCard(card, payload) {
        closeConfirmation(card, false);
        destroyWidgets(card);
        const feed = card.closest('.live-post-feed');
        if (feed) {
            const focusTarget = card.nextElementSibling?.querySelector?.('.post-edit-start')
                || card.previousElementSibling?.querySelector?.('.post-edit-start')
                || feed;
            card.remove();
            refresh();
            if (focusTarget instanceof HTMLElement) {
                if (focusTarget === feed) {
                    focusTarget.tabIndex = -1;
                }
                focusTarget.focus();
            }
            return;
        }

        const notice = document.createElement('div');
        notice.className = 'post-deleted-notice';
        notice.setAttribute('role', 'status');
        notice.tabIndex = -1;
        notice.textContent = typeof payload.message === 'string'
            ? payload.message
            : (card.dataset.deletedMessage || 'Post deleted');
        if (typeof payload.redirect === 'string' && payload.redirect !== '') {
            const link = document.createElement('a');
            link.href = payload.redirect;
            link.textContent = card.dataset.listLabel || 'Back to posts';
            notice.append(' — ', link);
        }
        card.replaceWith(notice);
        document.querySelectorAll('.comments-section, .comment-form-block').forEach((section) => section.remove());
        refresh();
        notice.focus();
    }

    async function submit(form) {
        const card = cardFor(form);
        if (!card) {
            return;
        }

        const buttons = form.querySelectorAll('button');
        buttons.forEach((button) => {
            button.disabled = true;
        });
        form.setAttribute('aria-busy', 'true');
        clearError(form);

        try {
            const response = await window.fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await response.json().catch(() => null);
            if (!response.ok || !payload || payload.success !== true) {
                throw new Error(payload?.message || card.dataset.editError || 'Unable to change the post.');
            }

            if (payload.action === 'edit') {
                updateEditedCard(card, form, payload);
            } else if (payload.action === 'delete') {
                removeDeletedCard(card, payload);
            } else {
                throw new Error('Не удалось обработать ответ сервера.');
            }
        } catch (error) {
            showError(
                form,
                error instanceof Error ? error.message : (card.dataset.editError || 'Unable to change the post.'),
            );
        } finally {
            if (form.isConnected) {
                form.removeAttribute('aria-busy');
                buttons.forEach((button) => {
                    button.disabled = false;
                });
            }
        }
    }

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const edit = target?.closest('.post-edit-start');
        if (edit && beginEdit(edit)) {
            event.preventDefault();
            return;
        }

        const editCancel = target?.closest('.post-edit-cancel');
        if (editCancel) {
            const card = cardFor(editCancel);
            if (card) {
                closeEditor(card, true);
            }
            return;
        }

        const deleteStart = target?.closest('.post-delete-start');
        if (deleteStart) {
            beginDelete(deleteStart);
            return;
        }

        const deleteCancel = target?.closest('.post-delete-cancel');
        if (deleteCancel) {
            const card = cardFor(deleteCancel);
            if (card) {
                closeConfirmation(card, true);
            }
        }
    }, false);

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('.post-inplace-edit-form, .post-inplace-delete-form')) {
            return;
        }
        event.preventDefault();
        submit(form);
    }, false);

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        const card = cardFor(event.target);
        if (!card) {
            return;
        }
        if (card.classList.contains('is-editing')) {
            event.preventDefault();
            closeEditor(card, true);
        } else if (card.classList.contains('is-confirming')) {
            event.preventDefault();
            closeConfirmation(card, true);
        }
    }, false);
})();
