(() => {
    'use strict';

    const editorStates = new WeakMap();

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

    function editErrorFor(card) {
        return card?.querySelector(':scope > .post-inplace-edit-error') || null;
    }

    function errorFor(scope) {
        if (scope instanceof HTMLFormElement && scope.matches('.post-inplace-edit-form')) {
            return editErrorFor(cardFor(scope));
        }
        return scope?.querySelector?.('.post-inplace-error') || null;
    }

    function clearError(scope) {
        const error = errorFor(scope);
        if (error) {
            error.hidden = true;
            error.textContent = '';
        }
    }

    function showError(scope, message) {
        const error = errorFor(scope);
        if (!error) {
            return;
        }
        error.textContent = message;
        error.hidden = false;
        error.focus();
    }

    function clearStatus(card) {
        const status = card.querySelector(':scope > .post-inplace-status');
        if (status) {
            status.hidden = true;
            status.textContent = '';
        }
    }

    function toggleEditingTools(card, editing) {
        const tools = card.querySelector(':scope > .post-inplace-tools');
        if (!tools) {
            return;
        }
        tools.querySelector('.post-edit-start')?.toggleAttribute('hidden', editing);
        tools.querySelector('.post-delete-start')?.toggleAttribute('hidden', editing);
        tools.querySelector('.post-edit-save')?.toggleAttribute('hidden', !editing);
        tools.querySelector('.post-edit-cancel')?.toggleAttribute('hidden', !editing);
    }

    function setEditable(element, label, multiline) {
        element.setAttribute('contenteditable', 'true');
        element.setAttribute('role', 'textbox');
        element.setAttribute('aria-label', label);
        element.setAttribute('spellcheck', 'true');
        if (multiline) {
            element.setAttribute('aria-multiline', 'true');
        }
    }

    function unsetEditable(element) {
        element.removeAttribute('contenteditable');
        element.removeAttribute('role');
        element.removeAttribute('aria-label');
        element.removeAttribute('aria-multiline');
        element.removeAttribute('spellcheck');
    }

    function focusEdge(element, atEnd) {
        element.focus();
        const selection = window.getSelection();
        if (!selection) {
            return;
        }
        const range = document.createRange();
        range.selectNodeContents(element);
        range.collapse(!atEnd);
        selection.removeAllRanges();
        selection.addRange(range);
    }

    function restoreHeadingLink(state) {
        if (!state.titleLink || !state.titleLinkHadHref) {
            return;
        }
        state.titleLink.setAttribute('href', state.titleLinkHref);
    }

    function stopEditing(state) {
        unsetEditable(state.title);
        unsetEditable(state.body);
        restoreHeadingLink(state);
        state.card.classList.remove('is-editing');
        state.card.removeAttribute('aria-busy');
        toggleEditingTools(state.card, false);
        editorStates.delete(state.card);
    }

    function editorElements(card) {
        const form = card.querySelector(':scope > .post-inplace-edit-form');
        const title = card.querySelector(':scope > .post.head [data-post-inplace-title]');
        const body = card.querySelector(':scope > .post.body[data-post-inplace-body]');
        const titleField = form?.elements.namedItem('title');
        const bodyField = form?.elements.namedItem('body');
        if (
            !(form instanceof HTMLFormElement)
            || !(title instanceof HTMLElement)
            || !(body instanceof HTMLElement)
            || !(titleField instanceof HTMLInputElement)
            || !(bodyField instanceof HTMLTextAreaElement)
        ) {
            return null;
        }
        return {form, title, body, titleField, bodyField};
    }

    function closeEditor(card, restoreFocus) {
        const state = editorStates.get(card);
        if (!state) {
            return;
        }

        state.title.textContent = state.originalTitle;
        state.body.innerHTML = state.originalBody;
        stopEditing(state);
        state.form.reset();
        clearError(state.form);
        enhanceWidgets(state.body);
        unlock();

        if (restoreFocus) {
            card.querySelector(':scope > .post-inplace-tools .post-edit-start')?.focus();
        }
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
        if (!card) {
            return false;
        }
        if (editorStates.has(card)) {
            return true;
        }

        const elements = editorElements(card);
        if (!elements) {
            return false;
        }

        closeOtherCards(card);
        closeConfirmation(card, false);
        clearError(elements.form);
        clearStatus(card);

        const titleLink = elements.title.closest('a');
        const state = {
            ...elements,
            card,
            originalTitle: elements.titleField.value,
            originalBody: elements.bodyField.value,
            titleDirty: false,
            bodyDirty: false,
            titleLink,
            titleLinkHadHref: titleLink?.hasAttribute('href') || false,
            titleLinkHref: titleLink?.getAttribute('href') || '',
        };

        destroyWidgets(elements.body);
        elements.title.textContent = state.originalTitle;
        elements.body.innerHTML = state.originalBody;
        if (state.titleLinkHadHref) {
            titleLink.removeAttribute('href');
        }

        setEditable(elements.title, card.dataset.titleLabel || 'Title', false);
        setEditable(elements.body, card.dataset.bodyLabel || 'Post text', true);
        editorStates.set(card, state);
        card.classList.add('is-editing');
        toggleEditingTools(card, true);
        document.execCommand('defaultParagraphSeparator', false, 'p');
        focusEdge(elements.title, true);

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

    function syncEditor(state) {
        const titleSource = state.titleDirty ? (state.title.textContent || '') : state.originalTitle;
        const title = titleSource
            .replace(/\u00a0/gu, ' ')
            .replace(/\s+/gu, ' ')
            .trim();
        if (title === '' || title.length > 255) {
            showError(state.form, state.card.dataset.invalidContent || state.card.dataset.editError || 'Invalid post content.');
            focusEdge(state.title, true);
            return false;
        }

        state.title.textContent = title;
        state.titleField.value = title;
        state.bodyField.value = state.bodyDirty ? state.body.innerHTML : state.originalBody;
        return true;
    }

    function parseBody(html) {
        const template = document.createElement('template');
        template.innerHTML = html;
        return template.content.querySelector('[data-post-inplace-body]');
    }

    function updateEditedCard(card, form, payload) {
        const state = editorStates.get(card);
        const title = state?.title || card.querySelector(':scope > .post.head [data-post-inplace-title]');
        const currentBody = state?.body || card.querySelector(':scope > .post.body[data-post-inplace-body]');
        const replacementBody = typeof payload.body_html === 'string' ? parseBody(payload.body_html) : null;
        if (!title || !currentBody || !replacementBody || typeof payload.title !== 'string') {
            throw new Error(card.dataset.applyError || 'Unable to apply the updated post.');
        }

        if (state) {
            stopEditing(state);
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

        clearError(form);
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

        if (form.matches('.post-inplace-edit-form')) {
            const state = editorStates.get(card);
            if (!state) {
                return;
            }
            if (!state.titleDirty && !state.bodyDirty) {
                closeEditor(card, true);
                return;
            }
            if (!syncEditor(state)) {
                return;
            }
        }

        const buttons = card.querySelectorAll('.post-inplace-tools button, .post-delete-confirmation button');
        buttons.forEach((button) => {
            button.disabled = true;
        });
        card.setAttribute('aria-busy', 'true');
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
                throw new Error('Unable to process the server response.');
            }
        } catch (error) {
            card.removeAttribute('aria-busy');
            showError(
                form,
                error instanceof Error ? error.message : (card.dataset.editError || 'Unable to change the post.'),
            );
        } finally {
            if (card.isConnected) {
                card.removeAttribute('aria-busy');
                buttons.forEach((button) => {
                    button.disabled = false;
                });
            }
        }
    }

    function selectionIsInside(element) {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            return false;
        }
        const range = selection.getRangeAt(0);
        return element.contains(range.commonAncestorContainer);
    }

    function runFormattingCommand(state, command, value = null) {
        if (!selectionIsInside(state.body)) {
            return false;
        }
        const bodyBefore = state.body.innerHTML;
        state.body.focus();
        const changed = document.execCommand(command, false, value);
        if (changed && state.body.innerHTML !== bodyBefore) {
            state.bodyDirty = true;
        }
        return changed;
    }

    function handleEditingShortcut(event, state) {
        if (event.isComposing) {
            return false;
        }

        const modifier = event.ctrlKey || event.metaKey;
        const matchesKey = (code, key) => event.code === code || event.key.toLowerCase() === key;
        if (modifier && !event.altKey && matchesKey('KeyS', 's')) {
            event.preventDefault();
            submit(state.form);
            return true;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeEditor(state.card, true);
            return true;
        }

        if (state.title.contains(event.target) && event.key === 'Enter') {
            event.preventDefault();
            focusEdge(state.body, false);
            return true;
        }

        if (!state.body.contains(event.target) || !modifier) {
            return false;
        }

        let command = null;
        let value = null;
        if (!event.shiftKey && !event.altKey && matchesKey('KeyB', 'b')) {
            command = 'bold';
        } else if (!event.shiftKey && !event.altKey && matchesKey('KeyI', 'i')) {
            command = 'italic';
        } else if (event.shiftKey && !event.altKey && matchesKey('KeyX', 'x')) {
            command = 'strikeThrough';
        } else if (event.shiftKey && !event.altKey && matchesKey('Digit7', '7')) {
            command = 'insertOrderedList';
        } else if (event.shiftKey && !event.altKey && matchesKey('Digit8', '8')) {
            command = 'insertUnorderedList';
        } else if (event.shiftKey && !event.altKey && matchesKey('Digit9', '9')) {
            command = 'formatBlock';
            value = 'blockquote';
        } else if (event.altKey && !event.shiftKey && ['Digit2', 'Digit3', 'Digit4'].includes(event.code)) {
            command = 'formatBlock';
            value = 'h' + event.code.slice(-1);
        } else if (!event.shiftKey && !event.altKey && matchesKey('KeyK', 'k')) {
            event.preventDefault();
            const url = window.prompt(state.card.dataset.linkPrompt || 'Link address');
            if (url !== null) {
                runFormattingCommand(state, url.trim() === '' ? 'unlink' : 'createLink', url.trim() || null);
            }
            return true;
        }

        if (!command) {
            return false;
        }
        event.preventDefault();
        runFormattingCommand(state, command, value);
        return true;
    }

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const card = cardFor(target);
        if (card?.classList.contains('is-editing')) {
            const editableLink = target?.closest('a');
            if (editableLink && (
                card.querySelector('[data-post-inplace-title]')?.contains(editableLink)
                || card.querySelector('[data-post-inplace-body]')?.contains(editableLink)
            )) {
                event.preventDefault();
            }
        }

        const edit = target?.closest('.post-edit-start');
        if (edit && beginEdit(edit)) {
            event.preventDefault();
            return;
        }

        const editSave = target?.closest('.post-edit-save');
        if (editSave) {
            const editCard = cardFor(editSave);
            const state = editCard ? editorStates.get(editCard) : null;
            if (state) {
                submit(state.form);
            }
            return;
        }

        const editCancel = target?.closest('.post-edit-cancel');
        if (editCancel) {
            const editCard = cardFor(editCancel);
            if (editCard) {
                closeEditor(editCard, true);
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
            const deleteCard = cardFor(deleteCancel);
            if (deleteCard) {
                closeConfirmation(deleteCard, true);
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
        const card = cardFor(event.target);
        const state = card ? editorStates.get(card) : null;
        if (state && handleEditingShortcut(event, state)) {
            return;
        }

        if (event.key !== 'Escape' || !card?.classList.contains('is-confirming')) {
            return;
        }
        event.preventDefault();
        closeConfirmation(card, true);
    }, false);

    document.addEventListener('paste', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const title = target?.closest('[data-post-inplace-title][contenteditable="true"]');
        if (!title) {
            return;
        }
        event.preventDefault();
        const text = (event.clipboardData?.getData('text/plain') || '').replace(/\s+/gu, ' ');
        document.execCommand('insertText', false, text);
    }, false);

    document.addEventListener('input', (event) => {
        const card = cardFor(event.target);
        const state = card ? editorStates.get(card) : null;
        if (!state) {
            return;
        }
        if (state.title.contains(event.target)) {
            state.titleDirty = true;
        }
        if (state.body.contains(event.target)) {
            state.bodyDirty = true;
        }
        clearError(card.querySelector(':scope > .post-inplace-edit-form'));
        clearStatus(card);
    }, false);
})();
