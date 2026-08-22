(() => {
    'use strict';

    const editorStates = new WeakMap();
    const imageExtensions = new Set(['avif', 'bmp', 'gif', 'ico', 'jpeg', 'jpg', 'png', 'webp']);
    const audioExtensions = new Set(['flac', 'mkv', 'mp3', 'mp4', 'ogg', 'wav', 'webm']);
    const editorPlatform = (() => {
        const platform = String(navigator.userAgentData?.platform || navigator.platform || '').toLowerCase();
        if (/mac|iphone|ipad|ipod/u.test(platform)) {
            return 'macos';
        }
        if (platform.includes('win')) {
            return 'windows';
        }
        return 'linux';
    })();
    const editorShortcuts = editorPlatform === 'macos'
        ? {
            'save': ['⌘S', 'Meta+S'],
            'cancel': ['Esc', 'Escape'],
            'undo': ['⌘Z', 'Meta+Z'],
            'redo': ['⇧⌘Z', 'Meta+Shift+Z'],
            'copy': ['⌘C', 'Meta+C'],
            'cut': ['⌘X', 'Meta+X'],
            'select-all': ['⌘A', 'Meta+A'],
            'bold': ['⌘B', 'Meta+B'],
            'italic': ['⌘I', 'Meta+I'],
            'strike': ['⇧⌘X', 'Meta+Shift+X'],
            'open-link': ['⌘K', 'Meta+K'],
            'h2': ['⌥⌘2', 'Meta+Alt+2'],
            'h3': ['⌥⌘3', 'Meta+Alt+3'],
            'h4': ['⌥⌘4', 'Meta+Alt+4'],
            'quote': ['⇧⌘9', 'Meta+Shift+9'],
            'ordered-list': ['⇧⌘7', 'Meta+Shift+7'],
            'unordered-list': ['⇧⌘8', 'Meta+Shift+8'],
            'apply-link': ['↵', 'Enter'],
        }
        : {
            'save': ['Ctrl+S', 'Control+S'],
            'cancel': ['Esc', 'Escape'],
            'undo': ['Ctrl+Z', 'Control+Z'],
            'redo': editorPlatform === 'windows'
                ? ['Ctrl+Y', 'Control+Y']
                : ['Ctrl+Shift+Z', 'Control+Shift+Z'],
            'copy': ['Ctrl+C', 'Control+C'],
            'cut': ['Ctrl+X', 'Control+X'],
            'select-all': ['Ctrl+A', 'Control+A'],
            'bold': ['Ctrl+B', 'Control+B'],
            'italic': ['Ctrl+I', 'Control+I'],
            'strike': ['Ctrl+Shift+X', 'Control+Shift+X'],
            'open-link': ['Ctrl+K', 'Control+K'],
            'h2': ['Ctrl+Alt+2', 'Control+Alt+2'],
            'h3': ['Ctrl+Alt+3', 'Control+Alt+3'],
            'h4': ['Ctrl+Alt+4', 'Control+Alt+4'],
            'quote': ['Ctrl+Shift+9', 'Control+Shift+9'],
            'ordered-list': ['Ctrl+Shift+7', 'Control+Shift+7'],
            'unordered-list': ['Ctrl+Shift+8', 'Control+Shift+8'],
            'apply-link': ['↵', 'Enter'],
        };

    function applyShortcutHints(root) {
        root.querySelectorAll('[data-editor-shortcut], [data-context-action]').forEach((element) => {
            const action = element.dataset.editorShortcut || element.dataset.contextAction;
            const shortcut = editorShortcuts[action];
            if (!shortcut) {
                return;
            }

            const [label, ariaLabel] = shortcut;
            const key = element.querySelector(':scope > kbd');
            if (key) {
                key.textContent = label;
            }
            if (element.hasAttribute('title')) {
                const title = element.dataset.shortcutTitle || element.getAttribute('title') || '';
                element.dataset.shortcutTitle = title;
                element.setAttribute('title', `${title} — ${label}`);
            }
            element.setAttribute('aria-keyshortcuts', ariaLabel);
        });
    }

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
            status.classList.remove('is-editor-toast', 'is-error');
        }
    }

    function showEditorStatus(state, message, error = false) {
        const status = state.card.querySelector(':scope > .post-inplace-status');
        if (!status) {
            return;
        }
        status.textContent = message;
        status.hidden = false;
        status.classList.add('is-editor-toast');
        status.classList.toggle('is-error', error);
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

    function localDateTimeValue(timestamp) {
        const date = new Date(timestamp * 1000);
        const part = (value) => String(value).padStart(2, '0');
        return `${date.getFullYear()}-${part(date.getMonth() + 1)}-${part(date.getDate())}`
            + `T${part(date.getHours())}:${part(date.getMinutes())}:${part(date.getSeconds())}`;
    }

    function releasePendingMedia(state) {
        if (state.uploadedMediaIds.size === 0) {
            return;
        }
        const token = state.form.elements.namedItem('inplace_token');
        const data = new FormData();
        data.set('inplace_action', 'media_release');
        data.set('inplace_token', token instanceof HTMLInputElement ? token.value : '');
        data.set('media_ids', Array.from(state.uploadedMediaIds).join(','));
        state.uploadedMediaIds.clear();
        window.fetch(state.form.action, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            keepalive: true,
        }).catch(() => {});
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

    function prepareEditableMedia(root) {
        root.querySelectorAll('audio[controls]').forEach((audio) => {
            audio.setAttribute('data-register-audio-native', '');
        });
    }

    function editableBodyHtml(state) {
        const clone = state.body.cloneNode(true);
        clone.querySelectorAll('[data-register-audio-native]').forEach((audio) => {
            audio.removeAttribute('data-register-audio-native');
        });
        clone.querySelectorAll('.post-editor-context-anchor').forEach((anchor) => anchor.remove());
        clone.querySelectorAll('.post-media-caption-toolbar').forEach((toolbar) => toolbar.remove());
        clone.querySelectorAll('.post-media-overlay-caption.is-editing-caption').forEach((caption) => {
            caption.classList.remove('is-editing-caption');
            caption.removeAttribute('contenteditable');
            caption.removeAttribute('role');
            caption.removeAttribute('aria-label');
            caption.removeAttribute('aria-multiline');
            caption.removeAttribute('spellcheck');
            caption.removeAttribute('data-placeholder');
            caption.removeAttribute('tabindex');
        });
        clone.querySelectorAll('.post-caption.is-editing-inline-caption').forEach((caption) => {
            const text = inlineMediaCaptionText(caption);
            if (text === '') {
                caption.remove();
                return;
            }
            clearInlineMediaCaptionAttributes(caption);
            caption.textContent = text;
        });
        return clone.innerHTML;
    }

    function restoreHeadingLink(state) {
        if (!state.titleLink || !state.titleLinkHadHref) {
            return;
        }
        state.titleLink.setAttribute('href', state.titleLinkHref);
    }

    function stopEditing(state) {
        closeContextMenu(state, false);
        state.imageCaptionEditor?.controller.abort();
        state.imageCaptionEditor = null;
        state.mediaCaptionEditors.forEach((controller) => controller.abort());
        state.mediaCaptionEditors.clear();
        state.aiController?.abort();
        state.aiController = null;
        state.mediaControllers.forEach((controller) => controller.abort());
        state.mediaControllers.clear();
        state.body.classList.remove('is-media-dragover');
        state.dateInput.hidden = true;
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
        const tags = card.querySelector(':scope > .post.foot [data-post-inplace-tags-values]');
        const tagsHost = tags?.closest('.post-foot-tags');
        const titleField = form?.elements.namedItem('title');
        const bodyField = form?.elements.namedItem('body');
        const tagsField = form?.elements.namedItem('tags');
        const publishedAtField = form?.elements.namedItem('published_at');
        const uploadedMediaField = form?.elements.namedItem('uploaded_media_ids');
        const dateInput = card.querySelector(':scope > .post.time > .post-inplace-datetime');
        const time = card.querySelector(':scope > .post.time > time');
        if (
            !(form instanceof HTMLFormElement)
            || !(title instanceof HTMLElement)
            || !(body instanceof HTMLElement)
            || !(tags instanceof HTMLElement)
            || !(tagsHost instanceof HTMLElement)
            || !(titleField instanceof HTMLInputElement)
            || !(bodyField instanceof HTMLTextAreaElement)
            || !(tagsField instanceof HTMLInputElement)
            || !(publishedAtField instanceof HTMLInputElement)
            || !(uploadedMediaField instanceof HTMLInputElement)
            || !(dateInput instanceof HTMLInputElement)
            || !(time instanceof HTMLTimeElement)
        ) {
            return null;
        }
        return {
            form,
            title,
            body,
            tags,
            tagsHost,
            titleField,
            bodyField,
            tagsField,
            publishedAtField,
            uploadedMediaField,
            dateInput,
            time,
        };
    }

    function closeEditor(card, restoreFocus) {
        const state = editorStates.get(card);
        if (!state) {
            return;
        }

        const creating = state.creating;
        releasePendingMedia(state);
        state.title.textContent = state.originalTitle;
        state.body.innerHTML = state.originalBody;
        state.tags.innerHTML = state.originalTagsHtml;
        state.tagsHost.classList.toggle('is-empty', state.originalTags === '');
        state.titleField.value = state.originalTitle;
        state.bodyField.value = state.originalBody;
        state.tagsField.value = state.originalTags;
        state.publishedAtField.value = String(state.originalPublishedAt);
        stopEditing(state);
        state.form.reset();
        clearError(state.form);
        enhanceWidgets(state.body);
        unlock();

        if (creating) {
            const slot = card.previousElementSibling?.matches?.('[data-post-create-slot]')
                ? card.previousElementSibling
                : card.parentElement?.querySelector?.('[data-post-create-slot]');
            card.remove();
            if (restoreFocus) {
                slot?.querySelector('.post-create-start')?.focus();
            }
            return;
        }

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
            originalTags: elements.tagsField.value,
            originalTagsHtml: elements.tags.innerHTML,
            originalPublishedAt: Number(elements.publishedAtField.value),
            titleDirty: false,
            bodyDirty: false,
            tagsDirty: false,
            dateDirty: false,
            creating: card.hasAttribute('data-post-creating'),
            mediaUploads: new Set(),
            mediaControllers: new Set(),
            uploadedMediaIds: new Set(),
            mediaCaptionEditors: new Map(),
            mediaCaptionBodyContentEditable: null,
            contextMenu: null,
            imageCaptionEditor: null,
            aiController: null,
            submitting: false,
            titleLink,
            titleLinkHadHref: titleLink?.hasAttribute('href') || false,
            titleLinkHref: titleLink?.getAttribute('href') || '',
        };

        destroyWidgets(elements.body);
        elements.title.textContent = state.originalTitle;
        elements.body.innerHTML = state.originalBody;
        prepareEditableMedia(elements.body);
        elements.tags.replaceChildren();
        if (state.titleLinkHadHref) {
            titleLink.removeAttribute('href');
        }

        setEditable(elements.title, card.dataset.titleLabel || 'Title', false);
        setEditable(elements.body, card.dataset.bodyLabel || 'Post text', true);
        elements.title.dataset.placeholder = card.dataset.titlePlaceholder || '';
        elements.dateInput.value = localDateTimeValue(state.originalPublishedAt);
        elements.dateInput.hidden = false;
        state.tagEditor = createTagEditor(state);
        editorStates.set(card, state);
        card.classList.add('is-editing');
        applyShortcutHints(card);
        toggleEditingTools(card, true);
        document.execCommand('defaultParagraphSeparator', false, 'p');
        focusEdge(elements.title, true);

        return true;
    }

    function beginCreate(button) {
        const slot = button.closest('[data-post-create-slot]');
        const existing = slot?.nextElementSibling;
        if (existing?.matches?.('.post-card[data-post-creating]')) {
            const state = editorStates.get(existing);
            state?.title.focus();
            return true;
        }
        const template = slot?.querySelector('.post-create-template');
        if (!(slot instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) {
            return false;
        }

        const fragment = template.content.cloneNode(true);
        const card = fragment.querySelector('.post-card[data-post-creating]');
        const edit = card?.querySelector('.post-edit-start');
        if (!(card instanceof HTMLElement) || !(edit instanceof HTMLElement)) {
            return false;
        }
        slot.after(fragment);
        if (!beginEdit(edit)) {
            card.remove();
            return false;
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

    function syncEditor(state) {
        finishImageCaptionEditing(state, true, false);
        finishInlineMediaCaptions(state);
        closeContextMenu(state, false);
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
        state.bodyField.value = state.bodyDirty ? editableBodyHtml(state) : state.originalBody;

        const publishedAt = state.dateDirty
            ? Math.floor(new Date(state.dateInput.value).getTime() / 1000)
            : state.originalPublishedAt;
        if (!Number.isInteger(publishedAt) || publishedAt < 1 || publishedAt > 4102444799) {
            showError(state.form, state.card.dataset.invalidContent || state.card.dataset.editError || 'Invalid post content.');
            state.dateInput.focus();
            return false;
        }
        state.publishedAtField.value = String(publishedAt);

        const tags = state.tagEditor.sync();
        if (tags === null) {
            showError(state.form, state.card.dataset.invalidTags || state.card.dataset.editError || 'Invalid post tags.');
            state.tagEditor.focus();
            return false;
        }
        const tagValue = tags.join(', ');
        state.tagsHost.classList.toggle('is-empty', tagValue === '');
        state.tagsField.value = tagValue;
        state.uploadedMediaField.value = Array.from(state.uploadedMediaIds).join(',');
        return true;
    }

    function normalizeTags(value) {
        const tags = [];
        const used = new Set();
        for (const part of String(value).split(/[,;\n]+/u)) {
            const tag = part
                .replace(/^\s*#+\s*/u, '')
                .replace(/\s+/gu, ' ')
                .trim();
            if (tag === '') {
                continue;
            }
            if (tag.length > 255 || !/^[\p{L}\p{N}_\- !.]+$/u.test(tag)) {
                return null;
            }
            const key = tag.toLocaleLowerCase();
            if (!used.has(key)) {
                used.add(key);
                tags.push(tag);
            }
            if (tags.length > 100) {
                return null;
            }
        }
        return tags;
    }

    function createTagEditor(state) {
        const root = document.createElement('span');
        const surface = document.createElement('span');
        const input = document.createElement('input');
        let tags = normalizeTags(state.originalTags) || [];

        root.className = 'post-tags-editor';
        surface.className = 'post-tags-surface';
        input.type = 'text';
        input.className = 'post-tags-text-input';
        input.placeholder = state.tags.dataset.placeholder || '';
        input.autocomplete = 'off';
        input.setAttribute('aria-label', state.card.dataset.tagsLabel || 'Tags');
        surface.append(input);
        root.append(surface);
        state.tags.append(root);

        function changed() {
            state.tagsDirty = true;
            clearError(state.form);
            clearStatus(state.card);
        }

        function syncSurface() {
            state.tagsHost.classList.toggle('is-empty', tags.length === 0 && input.value.trim() === '');
        }

        function render() {
            surface.querySelectorAll('.post-tag-chip').forEach((chip) => chip.remove());
            const fragment = document.createDocumentFragment();
            tags.forEach((tag, index) => {
                const chip = document.createElement('span');
                const label = document.createElement('span');
                const remove = document.createElement('button');

                chip.className = 'post-tag-chip';
                label.className = 'post-tag-chip-label';
                label.textContent = tag;
                remove.type = 'button';
                remove.className = 'post-tag-chip-remove';
                remove.dataset.tagIndex = String(index);
                remove.textContent = '×';
                remove.setAttribute(
                    'aria-label',
                    (state.card.dataset.removeTagLabel || 'Remove tag') + ': ' + tag,
                );
                chip.append(label, remove);
                fragment.append(chip);
            });
            surface.insertBefore(fragment, input);
            syncSurface();
        }

        function add(value) {
            const additions = normalizeTags(value);
            if (additions === null) {
                return false;
            }
            const merged = normalizeTags([...tags, ...additions].join(', '));
            if (merged === null) {
                return false;
            }
            tags = merged;
            input.value = '';
            changed();
            render();
            return true;
        }

        function commit() {
            if (input.value.trim() === '') {
                input.value = '';
                syncSurface();
                return true;
            }
            return add(input.value);
        }

        surface.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const remove = target?.closest('.post-tag-chip-remove');
            if (remove) {
                const index = Number(remove.dataset.tagIndex);
                if (Number.isInteger(index) && index >= 0 && index < tags.length) {
                    tags.splice(index, 1);
                    changed();
                    render();
                }
            }
            input.focus();
        });

        input.addEventListener('input', () => {
            changed();
            syncSurface();
        });
        input.addEventListener('keydown', (event) => {
            if (event.isComposing) {
                return;
            }
            if (event.key === 'Enter' || event.key === ',' || event.key === ';') {
                event.preventDefault();
                event.stopPropagation();
                if (!commit()) {
                    showError(state.form, state.card.dataset.invalidTags || state.card.dataset.editError || 'Invalid post tags.');
                }
                return;
            }
            if (event.key === 'Backspace' && input.value === '' && tags.length > 0) {
                event.preventDefault();
                tags.pop();
                changed();
                render();
            }
        });
        input.addEventListener('paste', (event) => {
            const pasted = event.clipboardData?.getData('text/plain') || '';
            if (!/[,;\n]/u.test(pasted)) {
                return;
            }
            const additions = normalizeTags(pasted);
            if (additions === null) {
                return;
            }
            event.preventDefault();
            add(pasted);
        });
        input.addEventListener('blur', () => {
            if (!root.isConnected || !state.card.classList.contains('is-editing')) {
                return;
            }
            if (!commit()) {
                showError(state.form, state.card.dataset.invalidTags || state.card.dataset.editError || 'Invalid post tags.');
            }
        });

        render();

        return {
            focus: () => input.focus(),
            sync: () => commit() ? [...tags] : null,
            replace: (value) => {
                const replacements = normalizeTags(value);
                if (replacements === null) {
                    return false;
                }
                tags = replacements;
                input.value = '';
                changed();
                render();
                return true;
            },
        };
    }

    function mediaKindForFile(file) {
        const dot = file.name.lastIndexOf('.');
        const extension = dot >= 0 ? file.name.slice(dot + 1).toLowerCase() : '';
        const type = file.type.toLowerCase();
        if (imageExtensions.has(extension) && (type === '' || type.startsWith('image/'))) {
            return 'image';
        }
        if (
            audioExtensions.has(extension)
            && (type === '' || type.startsWith('audio/') || type === 'application/ogg')
        ) {
            return 'audio';
        }
        return null;
    }

    function mediaMessage(template, fileName) {
        return String(template || '').replace('%s', fileName);
    }

    function bodyRange(state, candidate = null) {
        if (candidate instanceof Range && state.body.contains(candidate.commonAncestorContainer)) {
            const range = candidate.cloneRange();
            range.collapse(true);
            return range;
        }

        const range = document.createRange();
        range.selectNodeContents(state.body);
        range.collapse(false);
        return range;
    }

    function bodyRangeFromPoint(state, clientX, clientY) {
        let range = null;
        if (typeof document.caretPositionFromPoint === 'function') {
            const position = document.caretPositionFromPoint(clientX, clientY);
            if (position) {
                range = document.createRange();
                range.setStart(position.offsetNode, position.offset);
                range.collapse(true);
            }
        } else if (typeof document.caretRangeFromPoint === 'function') {
            range = document.caretRangeFromPoint(clientX, clientY);
        }

        return bodyRange(state, range);
    }

    function createMediaElement(state, payload, file) {
        if (payload.kind === 'image') {
            const image = document.createElement('img');
            image.className = 'post-media-image';
            image.setAttribute('src', payload.url);
            image.setAttribute('alt', '');
            image.setAttribute('loading', 'lazy');
            image.setAttribute('decoding', 'async');
            image.dataset.postMediaId = String(payload.media_id);
            if (Number.isInteger(payload.width) && payload.width > 0) {
                image.setAttribute('width', String(payload.width));
            }
            if (Number.isInteger(payload.height) && payload.height > 0) {
                image.setAttribute('height', String(payload.height));
            }

            const picture = document.createElement('div');
            picture.className = 'post-picture post-media-picture';
            const caption = document.createElement('div');
            caption.className = 'post-caption';
            picture.append(image, caption);
            beginInlineMediaCaption(state, caption);
            return {element: picture, caption};
        }

        const audio = document.createElement('audio');
        audio.className = 'post-media-audio';
        audio.setAttribute('src', payload.url);
        audio.setAttribute('preload', 'metadata');
        audio.setAttribute('controls', '');
        audio.setAttribute('data-register-audio-native', '');
        audio.dataset.postMediaId = String(payload.media_id);
        audio.dataset.title = typeof payload.name === 'string' && payload.name !== ''
            ? payload.name
            : file.name;
        return {element: audio, caption: null};
    }

    function inlineMediaCaptionText(caption) {
        const text = typeof caption.innerText === 'string' ? caption.innerText : caption.textContent;
        return String(text || '').replace(/\r\n?/gu, '\n').trim();
    }

    function clearInlineMediaCaptionAttributes(caption) {
        caption.classList.remove('is-editing-inline-caption');
        caption.removeAttribute('contenteditable');
        caption.removeAttribute('role');
        caption.removeAttribute('aria-label');
        caption.removeAttribute('aria-multiline');
        caption.removeAttribute('spellcheck');
        caption.removeAttribute('data-placeholder');
        caption.removeAttribute('tabindex');
    }

    function finishInlineMediaCaption(state, caption, restoreFocus = false) {
        const controller = state.mediaCaptionEditors.get(caption);
        if (!(controller instanceof AbortController)) {
            return;
        }
        state.mediaCaptionEditors.delete(caption);
        controller.abort();
        const selection = window.getSelection();
        const selectionWasInside = Boolean(selection?.anchorNode && caption.contains(selection.anchorNode));
        if (document.activeElement === caption) {
            caption.blur();
        }
        if (selectionWasInside) {
            selection?.removeAllRanges();
        }

        const text = inlineMediaCaptionText(caption);
        clearInlineMediaCaptionAttributes(caption);
        if (text === '') {
            caption.remove();
        } else {
            caption.textContent = text;
        }
        if (state.mediaCaptionEditors.size === 0) {
            if (state.mediaCaptionBodyContentEditable === null) {
                state.body.removeAttribute('contenteditable');
            } else {
                state.body.setAttribute('contenteditable', state.mediaCaptionBodyContentEditable);
            }
            state.mediaCaptionBodyContentEditable = null;
        }
        markBodyChanged(state);

        if (restoreFocus) {
            state.body.focus({preventScroll: true});
            window.getSelection()?.removeAllRanges();
        }
    }

    function finishInlineMediaCaptions(state) {
        Array.from(state.mediaCaptionEditors.keys()).forEach((caption) => {
            finishInlineMediaCaption(state, caption, false);
        });
    }

    function beginInlineMediaCaption(state, caption) {
        const controller = new AbortController();
        const placeholder = state.card.dataset.mediaCaptionPlaceholder || 'Add a caption…';
        if (state.mediaCaptionEditors.size === 0) {
            state.mediaCaptionBodyContentEditable = state.body.getAttribute('contenteditable');
            state.body.setAttribute('contenteditable', 'false');
        }
        state.mediaCaptionEditors.set(caption, controller);
        caption.classList.add('is-editing-inline-caption');
        caption.setAttribute('contenteditable', 'true');
        caption.setAttribute('role', 'textbox');
        caption.setAttribute('aria-label', placeholder);
        caption.setAttribute('aria-multiline', 'true');
        caption.setAttribute('spellcheck', 'true');
        caption.setAttribute('tabindex', '0');
        caption.dataset.placeholder = placeholder;
        caption.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' || (event.key === 'Enter' && (event.ctrlKey || event.metaKey))) {
                event.preventDefault();
                event.stopPropagation();
                finishInlineMediaCaption(state, caption, true);
                return;
            }
            if (event.key === 'Enter') {
                event.preventDefault();
                event.stopPropagation();
                document.execCommand('insertText', false, '\n');
            }
        }, {signal: controller.signal});
        caption.addEventListener('paste', (event) => {
            event.preventDefault();
            document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') || '');
        }, {signal: controller.signal});
    }

    function focusInlineMediaCaption(state, caption) {
        window.requestAnimationFrame(() => {
            if (!caption.isConnected || !state.mediaCaptionEditors.has(caption)) {
                return;
            }
            caption.focus({preventScroll: true});
            const selection = window.getSelection();
            if (!selection) {
                return;
            }
            const range = document.createRange();
            range.selectNodeContents(caption);
            range.collapse(false);
            selection.removeAllRanges();
            selection.addRange(range);
        });
    }

    function resolveMediaConflict(state, conflict, controller) {
        const template = state.card.querySelector(':scope > .post-media-conflict-template');
        const fragment = template instanceof HTMLTemplateElement ? template.content.cloneNode(true) : null;
        const backdrop = fragment?.querySelector('.post-media-conflict-backdrop');
        const dialog = fragment?.querySelector('.post-media-conflict-dialog');
        const existingImage = fragment?.querySelector('[data-media-conflict-existing]');
        const incomingImage = fragment?.querySelector('[data-media-conflict-incoming]');
        const overwrite = fragment?.querySelector('[data-media-conflict-action="overwrite"]');
        if (
            !(backdrop instanceof HTMLElement)
            || !(dialog instanceof HTMLElement)
            || !(existingImage instanceof HTMLImageElement)
            || !(incomingImage instanceof HTMLImageElement)
            || !(overwrite instanceof HTMLButtonElement)
        ) {
            return Promise.reject(new Error(state.card.dataset.mediaUploadFailed || 'Unable to upload the image.'));
        }

        existingImage.src = conflict.existing.preview_url || conflict.existing.url;
        existingImage.alt = conflict.existing.name || '';
        incomingImage.src = conflict.incoming.preview_url || conflict.incoming.url;
        incomingImage.alt = conflict.incoming.name || '';
        overwrite.hidden = conflict.can_overwrite !== true;
        document.body.append(backdrop);

        return new Promise((resolve, reject) => {
            let settled = false;
            let choosing = false;
            const cleanup = () => {
                document.removeEventListener('keydown', handleKeyDown, true);
                backdrop.remove();
            };
            const finish = (value, error = null) => {
                if (settled) {
                    return;
                }
                settled = true;
                cleanup();
                if (error) {
                    reject(error);
                } else {
                    resolve(value);
                }
            };
            const choose = async (decision) => {
                if (choosing || settled) {
                    return;
                }
                choosing = true;
                dialog.querySelectorAll('button').forEach((button) => {
                    button.disabled = true;
                });
                dialog.setAttribute('aria-busy', 'true');
                const token = state.form.elements.namedItem('inplace_token');
                const data = new FormData();
                data.set('inplace_action', 'media_conflict');
                data.set('inplace_token', token instanceof HTMLInputElement ? token.value : '');
                data.set('media_id', String(conflict.incoming.media_id));
                data.set('existing_id', String(conflict.existing.media_id));
                data.set('conflict_action', decision);
                try {
                    const response = await window.fetch(state.form.action, {
                        method: 'POST',
                        body: data,
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        signal: controller.signal,
                    });
                    const payload = await response.json().catch(() => null);
                    if (!response.ok || !payload || payload.success !== true) {
                        throw new Error(payload?.message || state.card.dataset.mediaUploadFailed || 'Unable to upload the image.');
                    }
                    finish(payload.action === 'media_cancelled' ? null : payload);
                } catch (error) {
                    finish(null, error);
                }
            };
            const handleKeyDown = (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    choose('cancel');
                }
            };
            backdrop.addEventListener('click', (event) => {
                const button = event.target instanceof Element
                    ? event.target.closest('[data-media-conflict-action]')
                    : null;
                if (button instanceof HTMLButtonElement) {
                    choose(button.dataset.mediaConflictAction || 'cancel');
                }
            });
            controller.signal.addEventListener('abort', () => {
                finish(null, new DOMException('The upload was cancelled.', 'AbortError'));
            }, {once: true});
            document.addEventListener('keydown', handleKeyDown, true);
            dialog.focus();
        });
    }

    function startMediaUpload(state, file, kind, placeholder) {
        const token = state.form.elements.namedItem('inplace_token');
        const controller = new AbortController();
        const formData = new FormData();
        formData.append('inplace_action', 'media');
        formData.append('inplace_token', token instanceof HTMLInputElement ? token.value : '');
        formData.append('media', file, file.name);
        state.mediaControllers.add(controller);

        const upload = (async () => {
            try {
                const response = await window.fetch(state.form.action, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: controller.signal,
                });
                let payload = await response.json().catch(() => null);
                let accepted = response.ok;
                if (
                    response.status === 409
                    && payload?.action === 'media_conflict'
                    && payload.incoming?.media_id
                    && payload.existing?.media_id
                ) {
                    state.uploadedMediaIds.add(Number(payload.incoming.media_id));
                    const candidateId = Number(payload.incoming.media_id);
                    payload = await resolveMediaConflict(state, payload, controller);
                    state.uploadedMediaIds.delete(candidateId);
                    if (payload === null) {
                        placeholder.remove();
                        return;
                    }
                    accepted = true;
                }
                if (
                    !accepted
                    || !payload
                    || payload.success !== true
                    || payload.action !== 'media'
                    || payload.kind !== kind
                    || !Number.isInteger(payload.media_id)
                    || payload.media_id <= 0
                    || typeof payload.url !== 'string'
                    || payload.url === ''
                ) {
                    throw new Error(
                        payload?.message
                        || mediaMessage(state.card.dataset.mediaUploadFailed || 'Unable to upload “%s”.', file.name),
                    );
                }
                if (editorStates.get(state.card) !== state || !placeholder.isConnected) {
                    return;
                }

                const media = createMediaElement(state, payload, file);
                state.uploadedMediaIds.add(payload.media_id);
                placeholder.replaceWith(media.element);
                state.bodyDirty = true;
                clearStatus(state.card);
                if (media.caption instanceof HTMLElement) {
                    focusInlineMediaCaption(state, media.caption);
                }
            } catch (error) {
                placeholder.remove();
                if (error instanceof DOMException && error.name === 'AbortError') {
                    return;
                }
                showError(
                    state.form,
                    error instanceof Error
                        ? error.message
                        : mediaMessage(state.card.dataset.mediaUploadFailed || 'Unable to upload “%s”.', file.name),
                );
            } finally {
                state.mediaControllers.delete(controller);
            }
        })();

        state.mediaUploads.add(upload);
        upload.finally(() => state.mediaUploads.delete(upload));
    }

    function insertMediaFiles(state, files, initialRange) {
        clearError(state.form);
        clearStatus(state.card);
        let range = bodyRange(state, initialRange);
        const unsupported = [];

        files.forEach((file) => {
            const kind = mediaKindForFile(file);
            if (kind === null) {
                unsupported.push(file.name);
                return;
            }

            const placeholder = document.createElement('span');
            const message = mediaMessage(
                state.card.dataset.mediaUploading || 'Uploading “%s”…',
                file.name,
            );
            placeholder.className = 'post-media-upload';
            placeholder.contentEditable = 'false';
            placeholder.dataset.mediaKind = kind;
            placeholder.setAttribute('role', 'status');
            placeholder.setAttribute('aria-label', message);
            placeholder.textContent = message;
            range.insertNode(placeholder);
            range.setStartAfter(placeholder);
            range.collapse(true);
            startMediaUpload(state, file, kind, placeholder);
        });

        if (unsupported.length > 0) {
            showError(
                state.form,
                mediaMessage(
                    state.card.dataset.mediaUnsupported || '“%s” is not supported. Drop an image or audio file.',
                    unsupported.join(', '),
                ),
            );
        }
    }

    function transferHasFiles(transfer) {
        return transfer instanceof DataTransfer
            && (
                Array.from(transfer.types || []).includes('Files')
                || Array.from(transfer.items || []).some((item) => item.kind === 'file')
            );
    }

    function bodyDropState(target) {
        const card = cardFor(target);
        const state = card ? editorStates.get(card) : null;
        return state && state.body.contains(target) ? state : null;
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
        const tagValues = state?.tags || card.querySelector(':scope > .post.foot [data-post-inplace-tags-values]');
        const tagsHost = state?.tagsHost || tagValues?.closest('.post-foot-tags');
        const time = state?.time || card.querySelector(':scope > .post.time > time');
        const dateInput = state?.dateInput || card.querySelector(':scope > .post.time > .post-inplace-datetime');
        const replacementBody = typeof payload.body_html === 'string' ? parseBody(payload.body_html) : null;
        if (
            !title
            || !currentBody
            || !replacementBody
            || !(tagValues instanceof HTMLElement)
            || !(tagsHost instanceof HTMLElement)
            || !(time instanceof HTMLTimeElement)
            || !(dateInput instanceof HTMLInputElement)
            || typeof payload.title !== 'string'
            || !Number.isInteger(payload.published_at)
            || typeof payload.datetime !== 'string'
            || typeof payload.time !== 'string'
            || !Array.isArray(payload.tags)
            || payload.tags.some((tag) => !tag || typeof tag.name !== 'string' || typeof tag.url !== 'string')
        ) {
            throw new Error(card.dataset.applyError || 'Unable to apply the updated post.');
        }

        if (state) {
            stopEditing(state);
        }
        title.textContent = payload.title;
        time.dateTime = payload.datetime;
        time.textContent = payload.time;
        dateInput.value = localDateTimeValue(payload.published_at);

        const tagFragment = document.createDocumentFragment();
        payload.tags.forEach((tag, index) => {
            if (index > 0) {
                tagFragment.append(', ');
            }
            const link = document.createElement('a');
            link.href = tag.url;
            link.textContent = tag.name;
            tagFragment.append(link);
        });
        tagValues.replaceChildren(tagFragment);
        tagsHost.classList.toggle('is-empty', payload.tags.length === 0);

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
        const tagsField = form.elements.namedItem('tags');
        const publishedAtField = form.elements.namedItem('published_at');
        const uploadedMediaField = form.elements.namedItem('uploaded_media_ids');
        if (titleField instanceof HTMLInputElement) {
            titleField.value = payload.title;
            titleField.defaultValue = payload.title;
        }
        if (bodyField instanceof HTMLTextAreaElement) {
            bodyField.defaultValue = bodyField.value;
        }
        if (tagsField instanceof HTMLInputElement) {
            tagsField.value = payload.tags.map((tag) => tag.name).join(', ');
            tagsField.defaultValue = tagsField.value;
        }
        if (publishedAtField instanceof HTMLInputElement) {
            publishedAtField.value = String(payload.published_at);
            publishedAtField.defaultValue = publishedAtField.value;
        }
        if (uploadedMediaField instanceof HTMLInputElement) {
            uploadedMediaField.value = '';
            uploadedMediaField.defaultValue = '';
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

    function updateCreatedCard(card, form, payload) {
        if (
            !Number.isInteger(payload.id)
            || payload.id <= 0
            || typeof payload.url !== 'string'
            || typeof payload.action_url !== 'string'
            || typeof payload.admin_edit_url !== 'string'
            || typeof payload.token !== 'string'
        ) {
            throw new Error(card.dataset.applyError || 'Unable to apply the created post.');
        }

        card.dataset.postId = String(payload.id);
        card.removeAttribute('data-post-creating');
        card.classList.remove('is-creating');
        form.action = payload.action_url;
        const action = form.elements.namedItem('inplace_action');
        const token = form.elements.namedItem('inplace_token');
        if (action instanceof HTMLInputElement) {
            action.value = 'edit';
            action.defaultValue = 'edit';
        }
        if (token instanceof HTMLInputElement) {
            token.value = payload.token;
            token.defaultValue = payload.token;
        }

        const deleteForm = card.querySelector(':scope > .post-delete-confirmation .post-inplace-delete-form');
        if (deleteForm instanceof HTMLFormElement) {
            deleteForm.action = payload.action_url;
            const deleteToken = deleteForm.elements.namedItem('inplace_token');
            if (deleteToken instanceof HTMLInputElement) {
                deleteToken.value = payload.token;
                deleteToken.defaultValue = payload.token;
            }
        }
        const editLink = card.querySelector(':scope > .post-inplace-tools .post-edit-start');
        if (editLink instanceof HTMLAnchorElement) {
            editLink.href = payload.admin_edit_url;
        }

        updateEditedCard(card, form, payload);
        const titleLink = card.querySelector(':scope > .post.head > a');
        if (titleLink instanceof HTMLAnchorElement) {
            titleLink.href = payload.url;
        }
        const slot = card.previousElementSibling?.matches?.('[data-post-create-slot]')
            ? card.previousElementSibling
            : card.parentElement?.querySelector?.('[data-post-create-slot]');
        const feed = card.closest('.live-post-feed');
        if (slot instanceof HTMLElement && feed) {
            const postCount = feed.querySelectorAll('.post-card:not([data-post-creating])').length;
            slot.classList.toggle('is-always-visible', postCount < 3);
        }
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

        const state = form.matches('.post-inplace-edit-form') ? editorStates.get(card) : null;
        if (form.matches('.post-inplace-edit-form') && !state) {
            return;
        }
        if (state?.submitting) {
            return;
        }
        if (state) {
            state.submitting = true;
            state.aiController?.abort();
            state.aiController = null;
            state.card.classList.remove('is-ai-working');
        }

        const buttons = card.querySelectorAll('.post-inplace-tools button, .post-delete-confirmation button');
        const setBusy = (busy) => {
            card.toggleAttribute('aria-busy', busy);
            buttons.forEach((button) => {
                button.disabled = busy;
            });
        };

        try {
            setBusy(true);
            if (state) {
                if (state.mediaUploads.size > 0) {
                    await Promise.all(Array.from(state.mediaUploads));
                }
                if (editorStates.get(card) !== state || !syncEditor(state)) {
                    return;
                }
                if (!state.creating && !state.titleDirty && !state.bodyDirty && !state.tagsDirty && !state.dateDirty) {
                    closeEditor(card, true);
                    return;
                }
            }

            clearError(form);
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
            } else if (payload.action === 'create') {
                updateCreatedCard(card, form, payload);
            } else if (payload.action === 'delete') {
                removeDeletedCard(card, payload);
            } else {
                throw new Error('Unable to process the server response.');
            }
        } catch (error) {
            showError(
                form,
                error instanceof Error ? error.message : (card.dataset.editError || 'Unable to change the post.'),
            );
        } finally {
            if (state) {
                state.submitting = false;
            }
            if (card.isConnected) {
                setBusy(false);
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

    function rangeIsInside(element, range) {
        return element.contains(range.startContainer)
            && element.contains(range.endContainer)
            && element.contains(range.commonAncestorContainer);
    }

    function selectRange(state, range) {
        if (!rangeIsInside(state.body, range)) {
            return false;
        }
        state.body.focus();
        const selection = window.getSelection();
        if (!selection) {
            return false;
        }
        selection.removeAllRanges();
        selection.addRange(range);
        return true;
    }

    function closeContextMenu(state, restoreSelection) {
        const context = state.contextMenu;
        if (!context) {
            return;
        }

        const range = context.range.cloneRange();
        context.anchor.remove();
        state.contextMenu = null;
        if (restoreSelection) {
            selectRange(state, range);
        }
    }

    function detachContextMenu(state) {
        const context = state.contextMenu;
        if (!context) {
            return null;
        }
        const snapshot = {
            range: context.range.cloneRange(),
            selected: context.selected,
            targetLink: context.targetLink,
        };
        closeContextMenu(state, false);
        return snapshot;
    }

    function contextLink(context) {
        if (context.targetLink instanceof HTMLAnchorElement && context.targetLink.isConnected) {
            return context.targetLink;
        }

        const node = context.range.startContainer;
        const element = node instanceof Element ? node : node.parentElement;
        const link = element?.closest('a');
        return link instanceof HTMLAnchorElement ? link : null;
    }

    function positionContextMenu(anchor, menu) {
        anchor.classList.remove('is-left', 'is-above');
        let rect = menu.getBoundingClientRect();
        if (rect.right > window.innerWidth - 12) {
            anchor.classList.add('is-left');
            rect = menu.getBoundingClientRect();
        }
        if (rect.bottom > window.innerHeight - 12) {
            anchor.classList.add('is-above');
        }
    }

    function visibleContextButtons(menu) {
        return Array.from(menu.querySelectorAll('button:not(:disabled)')).filter((button) => {
            return button.closest('[hidden]') === null;
        });
    }

    function showContextMain(state) {
        const context = state.contextMenu;
        if (!context) {
            return;
        }
        const imageMode = context.targetImage instanceof HTMLImageElement;
        context.main.hidden = imageMode;
        context.linkPanel.hidden = true;
        context.imagePanel.hidden = !imageMode;
        context.linkError.hidden = true;
        context.linkError.textContent = '';
        if (imageMode) {
            context.imagePanel.querySelector('button, input')?.focus();
        } else {
            visibleContextButtons(context.menu)[0]?.focus();
        }
        positionContextMenu(context.anchor, context.menu);
    }

    function showLinkPanel(state) {
        const context = state.contextMenu;
        if (!context) {
            return;
        }
        const link = contextLink(context);
        context.main.hidden = true;
        context.linkPanel.hidden = false;
        context.imagePanel.hidden = true;
        context.linkError.hidden = true;
        context.linkError.textContent = '';
        context.linkInput.value = link?.getAttribute('href') || '';
        context.removeLink.hidden = !link;
        context.linkInput.focus();
        context.linkInput.select();
        positionContextMenu(context.anchor, context.menu);
    }

    function normalizeLinkUrl(value) {
        const url = String(value).trim();
        if (url === '') {
            return '';
        }
        if (
            /[\u0000-\u001f\u007f\s]/u.test(url)
            || /^(?:data|file|javascript|vbscript):/iu.test(url)
        ) {
            return null;
        }
        return url;
    }

    function markBodyChanged(state) {
        state.bodyDirty = true;
        clearError(state.form);
        clearStatus(state.card);
    }

    function imageTargetLink(state, image) {
        const link = image.closest('a');
        return link instanceof HTMLAnchorElement && state.body.contains(link) ? link : null;
    }

    function linkableImageNode(state, image) {
        const proportional = image.closest('.post-proportional-wrapper');
        if (proportional instanceof HTMLElement && state.body.contains(proportional)) {
            return proportional;
        }

        const picture = image.closest('picture');
        if (picture instanceof HTMLPictureElement && state.body.contains(picture)) {
            return picture;
        }

        return image;
    }

    function imageCaptionContext(state, image) {
        const generated = image.closest('[data-post-media-overlay]');
        if (generated instanceof HTMLElement && state.body.contains(generated)) {
            return {
                wrapper: generated,
                caption: generated.querySelector(':scope > .post-media-overlay-caption'),
                generated: true,
            };
        }

        const postPicture = image.closest('.post-picture');
        if (postPicture instanceof HTMLElement && state.body.contains(postPicture)) {
            return {
                wrapper: postPicture,
                caption: postPicture.querySelector(':scope > .post-caption, :scope > .post-media-overlay-caption'),
                generated: false,
            };
        }

        const figure = image.closest('figure');
        if (
            figure instanceof HTMLElement
            && state.body.contains(figure)
            && figure.querySelectorAll('img').length === 1
        ) {
            return {
                wrapper: figure,
                caption: figure.querySelector(':scope > figcaption, :scope > .post-media-overlay-caption'),
                generated: false,
            };
        }

        return {wrapper: null, caption: null, generated: false};
    }

    function imageCaptionText(state, image) {
        return imageCaptionContext(state, image).caption?.textContent || '';
    }

    function setImageLink(state, image, url) {
        const existing = imageTargetLink(state, image);
        if (existing) {
            existing.setAttribute('href', url);
            return;
        }

        const node = linkableImageNode(state, image);
        const link = document.createElement('a');
        link.className = 'post-media-image-link';
        link.setAttribute('href', url);
        node.replaceWith(link);
        link.append(node);
    }

    function removeImageLink(state, image) {
        const link = imageTargetLink(state, image);
        if (!link) {
            return false;
        }

        const fragment = document.createDocumentFragment();
        while (link.firstChild) {
            fragment.append(link.firstChild);
        }
        link.replaceWith(fragment);
        return true;
    }

    function ensureImageCaption(state, image) {
        let context = imageCaptionContext(state, image);
        if (!(context.wrapper instanceof HTMLElement)) {
            const link = imageTargetLink(state, image);
            const visual = linkableImageNode(state, image);
            const media = link && link.contains(visual) ? link : visual;
            const wrapper = document.createElement('span');
            wrapper.className = 'post-media-overlay';
            wrapper.dataset.postMediaOverlay = '';
            wrapper.setAttribute('role', 'figure');
            media.replaceWith(wrapper);
            wrapper.append(media);
            context = {wrapper, caption: null, generated: true};
        }

        let caption = context.caption;
        if (!(caption instanceof HTMLElement)) {
            if (context.wrapper.tagName === 'FIGURE') {
                caption = document.createElement('figcaption');
            } else if (context.wrapper.classList.contains('post-picture')) {
                caption = document.createElement('div');
                caption.className = 'post-caption';
            } else {
                caption = document.createElement('span');
            }
            context.wrapper.append(caption);
        }

        context.wrapper.classList.add('post-media-overlay');
        caption.classList.add('post-media-overlay-caption');
        if (!caption.hasAttribute('data-caption-font')) {
            caption.dataset.captionFont = 'sans';
        }
        if (!caption.hasAttribute('data-caption-background')) {
            caption.dataset.captionBackground = 'dark';
        }
        return {...context, caption};
    }

    function applyImageCaption(state, image, value) {
        const text = String(value).replace(/\r\n?/gu, '\n').trim();
        const context = imageCaptionContext(state, image);

        if (text === '') {
            context.caption?.remove();
            context.wrapper?.classList.remove('post-media-overlay');
            if (context.generated && context.wrapper instanceof HTMLElement) {
                const fragment = document.createDocumentFragment();
                while (context.wrapper.firstChild) {
                    fragment.append(context.wrapper.firstChild);
                }
                context.wrapper.replaceWith(fragment);
            }
            return;
        }

        const captionContext = ensureImageCaption(state, image);
        captionContext.caption.textContent = text;
    }

    function imageCaptionEditorText(caption) {
        const text = typeof caption.innerText === 'string' ? caption.innerText : caption.textContent;
        return String(text || '').replace(/\r\n?/gu, '\n').trim();
    }

    function finishImageCaptionEditing(state, commit, restoreFocus = false) {
        const editor = state.imageCaptionEditor;
        if (!editor) {
            return;
        }
        state.imageCaptionEditor = null;
        editor.controller.abort();
        if (document.activeElement === editor.caption) {
            editor.caption.blur();
        }
        window.getSelection()?.removeAllRanges();
        editor.caption.classList.remove('is-editing-caption');
        editor.caption.removeAttribute('contenteditable');
        editor.caption.removeAttribute('role');
        editor.caption.removeAttribute('aria-label');
        editor.caption.removeAttribute('aria-multiline');
        editor.caption.removeAttribute('spellcheck');
        editor.caption.removeAttribute('data-placeholder');
        editor.caption.removeAttribute('tabindex');
        editor.toolbar.remove();
        if (editor.bodyContentEditable === null) {
            state.body.removeAttribute('contenteditable');
        } else {
            state.body.setAttribute('contenteditable', editor.bodyContentEditable);
        }

        if (!commit) {
            if (editor.originalFontAttribute === null) {
                editor.caption.removeAttribute('data-caption-font');
            } else {
                editor.caption.setAttribute('data-caption-font', editor.originalFontAttribute);
            }
            if (editor.originalBackgroundAttribute === null) {
                editor.caption.removeAttribute('data-caption-background');
            } else {
                editor.caption.setAttribute('data-caption-background', editor.originalBackgroundAttribute);
            }
        }

        const text = commit ? imageCaptionEditorText(editor.caption) : editor.original;
        const styleChanged = text !== '' && (
            editor.caption.dataset.captionFont !== editor.originalFont
            || editor.caption.dataset.captionBackground !== editor.originalBackground
        );
        applyImageCaption(state, editor.image, text);
        if (commit && (text !== editor.original || styleChanged)) {
            markBodyChanged(state);
        } else if (!commit) {
            state.bodyDirty = editor.bodyDirtyBefore;
        }
        if (restoreFocus) {
            state.body.focus({preventScroll: true});
            window.getSelection()?.removeAllRanges();
        }
    }

    function beginImageCaptionEditing(state, image, placeholder) {
        finishImageCaptionEditing(state, true, false);
        const originalContext = imageCaptionContext(state, image);
        const original = (originalContext.caption?.textContent || '').replace(/\r\n?/gu, '\n').trim();
        const originalFontAttribute = originalContext.caption?.getAttribute('data-caption-font') ?? null;
        const originalBackgroundAttribute = originalContext.caption?.getAttribute('data-caption-background') ?? null;
        const context = ensureImageCaption(state, image);
        const caption = context.caption;
        const toolbarTemplate = state.card.querySelector(':scope > .post-image-caption-toolbar-template');
        const toolbarFragment = toolbarTemplate instanceof HTMLTemplateElement
            ? toolbarTemplate.content.cloneNode(true)
            : null;
        const toolbar = toolbarFragment?.querySelector('.post-media-caption-toolbar');
        if (!(toolbar instanceof HTMLElement)) {
            applyImageCaption(state, image, original);
            return;
        }
        context.wrapper.append(toolbarFragment);
        const controller = new AbortController();
        state.imageCaptionEditor = {
            image,
            caption,
            toolbar,
            controller,
            original,
            originalFontAttribute,
            originalBackgroundAttribute,
            originalFont: originalFontAttribute || 'sans',
            originalBackground: originalBackgroundAttribute || 'dark',
            bodyDirtyBefore: state.bodyDirty,
            bodyContentEditable: state.body.getAttribute('contenteditable'),
        };

        state.body.setAttribute('contenteditable', 'false');
        caption.classList.add('is-editing-caption');
        caption.setAttribute('contenteditable', 'true');
        caption.setAttribute('role', 'textbox');
        caption.setAttribute('aria-label', placeholder);
        caption.setAttribute('aria-multiline', 'true');
        caption.setAttribute('spellcheck', 'true');
        caption.tabIndex = 0;
        caption.dataset.placeholder = placeholder;
        caption.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                finishImageCaptionEditing(state, false, true);
                return;
            }
            if (event.key === 'Enter') {
                event.preventDefault();
                event.stopPropagation();
                if (event.ctrlKey || event.metaKey) {
                    finishImageCaptionEditing(state, true, true);
                    return;
                }
                document.execCommand('insertText', false, '\n');
            }
        }, {signal: controller.signal});
        caption.addEventListener('paste', (event) => {
            event.preventDefault();
            document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') || '');
        }, {signal: controller.signal});
        caption.addEventListener('blur', () => {
            window.setTimeout(() => {
                if (state.imageCaptionEditor?.caption === caption && document.activeElement !== caption) {
                    finishImageCaptionEditing(state, true, false);
                }
            }, 0);
        }, {signal: controller.signal});
        toolbar.addEventListener('pointerdown', (event) => {
            event.preventDefault();
        }, {signal: controller.signal});
        const commitButton = toolbar.querySelector('[data-caption-action="commit"]');
        if (commitButton instanceof HTMLButtonElement) {
            const shortcutLabel = editorPlatform === 'macos' ? '⌘↵' : 'Ctrl+↵';
            const shortcutAria = editorPlatform === 'macos' ? 'Meta+Enter' : 'Control+Enter';
            commitButton.title = `${commitButton.title} — ${shortcutLabel}`;
            commitButton.setAttribute('aria-keyshortcuts', shortcutAria);
        }
        toolbar.addEventListener('click', (event) => {
            const button = event.target instanceof Element ? event.target.closest('button') : null;
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }
            if (button.dataset.captionAction === 'commit') {
                finishImageCaptionEditing(state, true, true);
                return;
            }
            if (button.dataset.captionAction === 'cancel') {
                finishImageCaptionEditing(state, false, true);
                return;
            }
            if (button.dataset.captionFont) {
                caption.dataset.captionFont = button.dataset.captionFont;
            }
            if (button.dataset.captionBackground) {
                caption.dataset.captionBackground = button.dataset.captionBackground;
            }
            toolbar.querySelectorAll('[data-caption-font]').forEach((fontButton) => {
                fontButton.setAttribute('aria-pressed', String(
                    fontButton.getAttribute('data-caption-font') === caption.dataset.captionFont,
                ));
            });
            toolbar.querySelectorAll('[data-caption-background]').forEach((backgroundButton) => {
                backgroundButton.setAttribute('aria-pressed', String(
                    backgroundButton.getAttribute('data-caption-background') === caption.dataset.captionBackground,
                ));
            });
            caption.focus();
        }, {signal: controller.signal});
        toolbar.querySelectorAll('[data-caption-font]').forEach((fontButton) => {
            fontButton.setAttribute('aria-pressed', String(
                fontButton.getAttribute('data-caption-font') === caption.dataset.captionFont,
            ));
        });
        toolbar.querySelectorAll('[data-caption-background]').forEach((backgroundButton) => {
            backgroundButton.setAttribute('aria-pressed', String(
                backgroundButton.getAttribute('data-caption-background') === caption.dataset.captionBackground,
            ));
        });
        window.requestAnimationFrame(() => {
            if (state.imageCaptionEditor?.caption === caption) {
                focusEdge(caption, true);
            }
        });
    }

    function editImageCaption(state) {
        const context = state.contextMenu;
        if (!context?.targetImage?.isConnected) {
            return;
        }

        const image = context.targetImage;
        const placeholder = context.imageCaptionButton.dataset.captionPlaceholder || 'Type a caption';
        closeContextMenu(state, false);
        beginImageCaptionEditing(state, image, placeholder);
    }

    function applyContextLink(state) {
        const context = state.contextMenu;
        if (!context) {
            return;
        }
        const url = normalizeLinkUrl(context.linkInput.value);
        if (url === null) {
            context.linkError.textContent = state.card.dataset.invalidLink || 'Enter a safe link address.';
            context.linkError.hidden = false;
            context.linkInput.focus();
            return;
        }
        if (url === '') {
            removeContextLink(state);
            return;
        }

        if (context.targetImage instanceof HTMLImageElement && context.targetImage.isConnected) {
            const image = context.targetImage;
            detachContextMenu(state);
            setImageLink(state, image, url);
            markBodyChanged(state);
            state.body.focus();
            return;
        }

        const link = contextLink(context);
        const snapshot = detachContextMenu(state);
        if (!snapshot) {
            return;
        }
        if (link) {
            link.setAttribute('href', url);
            markBodyChanged(state);
            state.body.focus();
            return;
        }
        if (!selectRange(state, snapshot.range)) {
            return;
        }
        if (snapshot.range.collapsed) {
            const insertedLink = document.createElement('a');
            insertedLink.setAttribute('href', url);
            insertedLink.textContent = url;
            snapshot.range.insertNode(insertedLink);
            const afterLink = document.createRange();
            afterLink.setStartAfter(insertedLink);
            afterLink.collapse(true);
            selectRange(state, afterLink);
            markBodyChanged(state);
            return;
        }
        runFormattingCommand(state, 'createLink', url);
    }

    function removeContextLink(state) {
        const context = state.contextMenu;
        if (!context) {
            return;
        }
        if (context.targetImage instanceof HTMLImageElement && context.targetImage.isConnected) {
            const image = context.targetImage;
            detachContextMenu(state);
            if (removeImageLink(state, image)) {
                markBodyChanged(state);
            }
            state.body.focus();
            return;
        }
        const link = contextLink(context);
        const snapshot = detachContextMenu(state);
        if (!snapshot) {
            return;
        }
        if (link) {
            const fragment = document.createDocumentFragment();
            while (link.firstChild) {
                fragment.append(link.firstChild);
            }
            link.replaceWith(fragment);
            markBodyChanged(state);
            state.body.focus();
            return;
        }
        if (selectRange(state, snapshot.range)) {
            runFormattingCommand(state, 'unlink');
        }
    }

    function chooseContextMedia(state) {
        const context = detachContextMenu(state);
        if (!context) {
            return;
        }
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*,audio/*,.mkv';
        input.multiple = true;
        input.addEventListener('change', () => {
            const files = Array.from(input.files || []);
            if (files.length > 0 && editorStates.get(state.card) === state) {
                insertMediaFiles(state, files, context.range);
            }
        }, {once: true});
        input.click();
    }

    function contextFormat(state, command, value = null) {
        const context = detachContextMenu(state);
        if (!context || !selectRange(state, context.range)) {
            return;
        }
        runFormattingCommand(state, command, value);
    }

    function selectWholeBody(state) {
        closeContextMenu(state, false);
        const range = document.createRange();
        range.selectNodeContents(state.body);
        selectRange(state, range);
    }

    function htmlForRange(range) {
        const container = document.createElement('div');
        container.append(range.cloneContents());
        container.querySelectorAll('[data-register-audio-native]').forEach((audio) => {
            audio.removeAttribute('data-register-audio-native');
        });
        container.querySelectorAll('.post-editor-context-anchor').forEach((anchor) => anchor.remove());
        container.querySelectorAll('.post-media-caption-toolbar').forEach((toolbar) => toolbar.remove());
        return container.innerHTML;
    }

    function replaceRangeHtml(state, range, html) {
        if (!rangeIsInside(state.body, range)) {
            return false;
        }
        const fragment = range.createContextualFragment(html);
        const lastNode = fragment.lastChild;
        range.deleteContents();
        range.insertNode(fragment);
        if (lastNode) {
            const after = document.createRange();
            after.setStartAfter(lastNode);
            after.collapse(true);
            selectRange(state, after);
        }
        prepareEditableMedia(state.body);
        markBodyChanged(state);
        return true;
    }

    async function runContextAi(state, action) {
        const context = state.contextMenu;
        if (!context) {
            return;
        }

        const usesSelection = context.selected && !['tags', 'title'].includes(action);
        const sourceRange = usesSelection ? context.range.cloneRange() : null;
        const source = sourceRange ? htmlForRange(sourceRange) : editableBodyHtml(state);
        const wholeSource = editableBodyHtml(state);
        closeContextMenu(state, false);
        if (source.trim() === '') {
            showEditorStatus(state, state.card.dataset.aiFailed || 'Unable to get a response from AI.', true);
            return;
        }

        state.aiController?.abort();
        const controller = new AbortController();
        state.aiController = controller;
        state.card.classList.add('is-ai-working');
        showEditorStatus(state, state.card.dataset.aiWorking || 'AI is processing the text…');

        const token = state.form.elements.namedItem('inplace_token');
        const data = new FormData();
        data.set('inplace_action', 'ai');
        data.set('inplace_token', token instanceof HTMLInputElement ? token.value : '');
        data.set('ai_action', action);
        data.set('title', state.title.textContent || '');
        data.set('text', source);

        try {
            const response = await window.fetch(state.form.action, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller.signal,
            });
            const payload = await response.json().catch(() => null);
            if (
                !response.ok
                || !payload
                || payload.success !== true
                || payload.action !== 'ai'
                || payload.ai_action !== action
                || typeof payload.result !== 'string'
            ) {
                throw new Error(payload?.message || state.card.dataset.aiFailed || 'Unable to get a response from AI.');
            }
            if (editorStates.get(state.card) !== state || state.aiController !== controller) {
                return;
            }

            if (sourceRange) {
                if (!rangeIsInside(state.body, sourceRange) || htmlForRange(sourceRange) !== source) {
                    showEditorStatus(state, state.card.dataset.aiSourceChanged || 'The source text has changed.', true);
                    return;
                }
            } else if (editableBodyHtml(state) !== wholeSource) {
                showEditorStatus(state, state.card.dataset.aiSourceChanged || 'The source text has changed.', true);
                return;
            }

            if (payload.result === source && !['tags', 'title'].includes(action)) {
                showEditorStatus(
                    state,
                    action === 'proofread'
                        ? (state.card.dataset.aiProofreadClean || 'No errors found.')
                        : (state.card.dataset.aiUnchanged || 'AI did not change the text.'),
                );
                return;
            }

            if (action === 'title') {
                const title = payload.result.replace(/\s+/gu, ' ').trim();
                if (title === '' || title.length > 255) {
                    throw new Error(state.card.dataset.invalidContent || 'Invalid post content.');
                }
                state.title.textContent = title;
                state.titleDirty = true;
                focusEdge(state.title, true);
            } else if (action === 'tags') {
                if (!state.tagEditor.replace(payload.result)) {
                    throw new Error(state.card.dataset.invalidTags || 'Invalid post tags.');
                }
                state.tagsDirty = true;
                state.tagEditor.focus();
            } else if (sourceRange) {
                if (!replaceRangeHtml(state, sourceRange, payload.result)) {
                    throw new Error(state.card.dataset.aiSourceChanged || 'The source text has changed.');
                }
            } else {
                state.body.innerHTML = payload.result;
                prepareEditableMedia(state.body);
                markBodyChanged(state);
                focusEdge(state.body, false);
            }

            clearError(state.form);
            showEditorStatus(state, state.card.dataset.aiApplied || 'AI changes applied.');
        } catch (error) {
            if (!(error instanceof DOMException && error.name === 'AbortError')) {
                showEditorStatus(
                    state,
                    error instanceof Error
                        ? error.message
                        : (state.card.dataset.aiFailed || 'Unable to get a response from AI.'),
                    true,
                );
            }
        } finally {
            if (state.aiController === controller) {
                state.aiController = null;
                state.card.classList.remove('is-ai-working');
            }
        }
    }

    function handleContextAction(state, action) {
        const formats = {
            'bold': ['bold'],
            'italic': ['italic'],
            'strike': ['strikeThrough'],
            'clear-format': ['removeFormat'],
            'paragraph': ['formatBlock', 'p'],
            'h2': ['formatBlock', 'h2'],
            'h3': ['formatBlock', 'h3'],
            'h4': ['formatBlock', 'h4'],
            'quote': ['formatBlock', 'blockquote'],
            'code': ['formatBlock', 'pre'],
            'unordered-list': ['insertUnorderedList'],
            'ordered-list': ['insertOrderedList'],
            'divider': ['insertHorizontalRule'],
            'undo': ['undo'],
            'redo': ['redo'],
            'cut': ['cut'],
        };
        if (formats[action]) {
            contextFormat(state, ...formats[action]);
            return;
        }
        if (action === 'copy') {
            const context = detachContextMenu(state);
            if (context && selectRange(state, context.range)) {
                document.execCommand('copy');
            }
            return;
        }
        if (action === 'select-all') {
            selectWholeBody(state);
            return;
        }
        if (action === 'media') {
            chooseContextMedia(state);
            return;
        }
        if (action === 'open-link') {
            showLinkPanel(state);
            return;
        }
        if (action === 'link-back') {
            showContextMain(state);
            return;
        }
        if (action === 'apply-link') {
            applyContextLink(state);
            return;
        }
        if (action === 'remove-link') {
            removeContextLink(state);
            return;
        }
        if (action === 'edit-image-caption') {
            editImageCaption(state);
        }
    }

    function openContextMenu(state, event = null, targetOverride = null) {
        const template = state.card.querySelector(':scope > .post-editor-context-menu-template');
        if (!(template instanceof HTMLTemplateElement)) {
            return false;
        }
        const target = targetOverride instanceof Element
            ? targetOverride
            : (event?.target instanceof Element ? event.target : null);
        const image = target?.matches('img')
            ? target
            : target?.closest('[data-post-media-overlay], .post-picture, figure')?.querySelector('img');
        const targetImage = image instanceof HTMLImageElement && state.body.contains(image) ? image : null;
        finishImageCaptionEditing(state, true, false);
        closeContextMenu(state, false);

        const selection = window.getSelection();
        let range = selection && selection.rangeCount > 0 ? selection.getRangeAt(0).cloneRange() : null;
        const selected = targetImage === null
            && range instanceof Range
            && rangeIsInside(state.body, range)
            && !range.collapsed
            && range.toString().trim() !== '';
        if (targetImage) {
            range = document.createRange();
            range.selectNode(targetImage);
        } else if (!selected) {
            range = event
                ? bodyRangeFromPoint(state, event.clientX, event.clientY)
                : bodyRange(state, range);
        }
        if (!(range instanceof Range) || !rangeIsInside(state.body, range)) {
            range = bodyRange(state);
        }

        const anchor = document.createElement('span');
        anchor.className = 'post-editor-context-anchor';
        anchor.contentEditable = 'false';
        if (targetImage) {
            const host = targetImage.closest('[data-post-media-overlay], .post-picture, figure, a')
                || targetImage.parentElement;
            if (!(host instanceof HTMLElement)) {
                return false;
            }
            anchor.classList.add('is-image');
            host.append(anchor);
        } else {
            const anchorRange = event
                ? bodyRangeFromPoint(state, event.clientX, event.clientY)
                : range.cloneRange();
            anchorRange.collapse(false);
            anchorRange.insertNode(anchor);
        }

        const fragment = template.content.cloneNode(true);
        const menu = fragment.querySelector('.post-editor-context-menu');
        if (!(menu instanceof HTMLElement)) {
            anchor.remove();
            return false;
        }
        menu.classList.toggle('is-image-menu', targetImage !== null);
        anchor.append(fragment);
        menu.querySelectorAll('[data-context-selection-only]').forEach((element) => {
            element.hidden = targetImage !== null || !selected;
        });
        menu.querySelectorAll('[data-context-caret-only]').forEach((element) => {
            element.hidden = targetImage !== null || selected;
        });
        menu.querySelectorAll('[data-context-image-only]').forEach((element) => {
            element.hidden = targetImage === null;
        });
        applyShortcutHints(menu);

        const main = menu.querySelector('.post-editor-context-main');
        const linkPanel = menu.querySelector('.post-editor-link-panel');
        const linkInput = menu.querySelector('[data-context-link-input]');
        const linkError = menu.querySelector('.post-editor-link-error');
        const removeLink = menu.querySelector('[data-context-action="remove-link"]');
        const imagePanel = menu.querySelector('.post-editor-image-panel');
        const imageAltInput = menu.querySelector('[data-context-image-alt-input]');
        const imageLinkButton = imagePanel?.querySelector('[data-context-action="open-link"]');
        const imageCaptionButton = imagePanel?.querySelector('[data-context-action="edit-image-caption"]');
        if (
            !(main instanceof HTMLElement)
            || !(linkPanel instanceof HTMLElement)
            || !(linkInput instanceof HTMLInputElement)
            || !(linkError instanceof HTMLElement)
            || !(removeLink instanceof HTMLButtonElement)
            || !(imagePanel instanceof HTMLElement)
            || !(imageAltInput instanceof HTMLInputElement)
            || !(imageLinkButton instanceof HTMLButtonElement)
            || !(imageCaptionButton instanceof HTMLButtonElement)
        ) {
            anchor.remove();
            return false;
        }

        const targetLink = targetImage ? imageTargetLink(state, targetImage) : target?.closest('a');
        main.hidden = targetImage !== null;
        linkPanel.hidden = true;
        imagePanel.hidden = targetImage === null;
        if (targetImage) {
            const targetImageLink = imageTargetLink(state, targetImage);
            imageAltInput.value = targetImage.getAttribute('alt') || '';
            imageLinkButton.classList.toggle('is-active', targetImageLink !== null);
            imageLinkButton.setAttribute('aria-pressed', String(targetImageLink !== null));
            imageCaptionButton.classList.toggle('is-active', imageCaptionText(state, targetImage).trim() !== '');
        }
        state.contextMenu = {
            anchor,
            menu,
            main,
            linkPanel,
            linkInput,
            linkError,
            removeLink,
            imagePanel,
            imageAltInput,
            imageLinkButton,
            imageCaptionButton,
            range,
            selected,
            targetImage,
            targetLink: targetLink instanceof HTMLAnchorElement && state.body.contains(targetLink)
                ? targetLink
                : null,
        };

        if (targetImage) {
            imageAltInput.addEventListener('input', () => {
                targetImage.setAttribute('alt', imageAltInput.value);
                markBodyChanged(state);
            });
        }

        menu.addEventListener('pointerdown', (pointerEvent) => {
            if (
                !(pointerEvent.target instanceof HTMLInputElement)
                && !(pointerEvent.target instanceof HTMLTextAreaElement)
            ) {
                pointerEvent.preventDefault();
            }
        });
        menu.addEventListener('contextmenu', (contextEvent) => {
            contextEvent.preventDefault();
            contextEvent.stopPropagation();
        });
        menu.addEventListener('click', (clickEvent) => {
            const button = clickEvent.target instanceof Element
                ? clickEvent.target.closest('button')
                : null;
            if (!(button instanceof HTMLButtonElement) || button.disabled) {
                return;
            }
            const aiAction = button.dataset.contextAiAction;
            if (aiAction) {
                runContextAi(state, aiAction);
                return;
            }
            const action = button.dataset.contextAction;
            if (action) {
                handleContextAction(state, action);
            }
        });
        menu.addEventListener('keydown', (keyEvent) => {
            if (keyEvent.key === 'Escape') {
                keyEvent.preventDefault();
                keyEvent.stopPropagation();
                if (!linkPanel.hidden) {
                    showContextMain(state);
                } else {
                    closeContextMenu(state, targetImage === null);
                }
                return;
            }
            if (keyEvent.target === linkInput && keyEvent.key === 'Enter') {
                keyEvent.preventDefault();
                keyEvent.stopPropagation();
                applyContextLink(state);
                return;
            }
            if (keyEvent.target === imageAltInput && keyEvent.key === 'Enter') {
                keyEvent.preventDefault();
                keyEvent.stopPropagation();
                closeContextMenu(state, false);
                state.body.focus();
                return;
            }
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(keyEvent.key)) {
                return;
            }
            const buttons = visibleContextButtons(menu);
            if (buttons.length === 0) {
                return;
            }
            keyEvent.preventDefault();
            keyEvent.stopPropagation();
            const current = buttons.indexOf(document.activeElement);
            let next = 0;
            if (keyEvent.key === 'End') {
                next = buttons.length - 1;
            } else if (keyEvent.key === 'ArrowUp') {
                next = current <= 0 ? buttons.length - 1 : current - 1;
            } else if (keyEvent.key === 'ArrowDown') {
                next = current < 0 || current === buttons.length - 1 ? 0 : current + 1;
            }
            buttons[next].focus();
        });

        positionContextMenu(anchor, menu);
        if (targetImage) {
            imageLinkButton.focus();
        } else {
            visibleContextButtons(menu)[0]?.focus();
        }
        return true;
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

        if (event.target instanceof Element && event.target.closest('.post-editor-context-menu')) {
            return false;
        }

        if (
            state.body.contains(event.target)
            && (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10'))
        ) {
            event.preventDefault();
            openContextMenu(
                state,
                null,
                event.target instanceof Element ? event.target : null,
            );
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
        if (!event.shiftKey && !event.altKey && matchesKey('KeyZ', 'z')) {
            command = 'undo';
        } else if (
            !event.altKey
            && (
                (editorPlatform === 'windows' && !event.shiftKey && matchesKey('KeyY', 'y'))
                || (editorPlatform !== 'windows' && event.shiftKey && matchesKey('KeyZ', 'z'))
            )
        ) {
            command = 'redo';
        } else if (!event.shiftKey && !event.altKey && matchesKey('KeyB', 'b')) {
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
            if (openContextMenu(state)) {
                showLinkPanel(state);
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

    document.addEventListener('dragenter', (event) => {
        const state = bodyDropState(event.target);
        if (state && transferHasFiles(event.dataTransfer)) {
            state.body.classList.add('is-media-dragover');
        }
    }, false);

    document.addEventListener('dragover', (event) => {
        const state = bodyDropState(event.target);
        if (!state || !transferHasFiles(event.dataTransfer)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        event.dataTransfer.dropEffect = 'copy';
        state.body.classList.add('is-media-dragover');
    }, false);

    document.addEventListener('dragleave', (event) => {
        const state = bodyDropState(event.target);
        if (!state) {
            return;
        }
        const relatedTarget = event.relatedTarget;
        if (!(relatedTarget instanceof Node) || !state.body.contains(relatedTarget)) {
            state.body.classList.remove('is-media-dragover');
        }
    }, false);

    document.addEventListener('drop', (event) => {
        const state = bodyDropState(event.target);
        if (!state || !transferHasFiles(event.dataTransfer)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        state.body.classList.remove('is-media-dragover');
        insertMediaFiles(
            state,
            Array.from(event.dataTransfer.files || []),
            bodyRangeFromPoint(state, event.clientX, event.clientY),
        );
    }, false);

    document.addEventListener('contextmenu', (event) => {
        if (event.shiftKey) {
            return;
        }
        const target = event.target instanceof Element ? event.target : null;
        if (target?.closest('.post-editor-context-menu')) {
            return;
        }
        const card = cardFor(target);
        const state = card ? editorStates.get(card) : null;
        if (!state || !state.body.contains(target)) {
            return;
        }
        event.preventDefault();
        openContextMenu(state, event);
    }, false);

    document.addEventListener('pointerdown', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        document.querySelectorAll('.post-card.is-editing').forEach((card) => {
            const state = editorStates.get(card);
            state?.mediaCaptionEditors.forEach((_controller, caption) => {
                if (!caption.contains(target)) {
                    finishInlineMediaCaption(state, caption, false);
                }
            });
            const caption = state?.imageCaptionEditor?.caption;
            const toolbar = state?.imageCaptionEditor?.toolbar;
            if (state && caption && toolbar && !caption.contains(target) && !toolbar.contains(target)) {
                finishImageCaptionEditing(state, true, false);
            }
        });
    }, true);

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const card = cardFor(target);
        document.querySelectorAll('.post-card.is-editing').forEach((editingCard) => {
            const editingState = editorStates.get(editingCard);
            if (editingState?.contextMenu && !editingState.contextMenu.menu.contains(target)) {
                closeContextMenu(editingState, false);
            }
        });
        if (card?.classList.contains('is-editing')) {
            const editableLink = target?.closest('a');
            if (editableLink && (
                card.querySelector('[data-post-inplace-title]')?.contains(editableLink)
                || card.querySelector('[data-post-inplace-body]')?.contains(editableLink)
                || card.querySelector('[data-post-inplace-tags-values]')?.contains(editableLink)
            )) {
                event.preventDefault();
            }
        }

        const create = target?.closest('.post-create-start');
        if (create && beginCreate(create)) {
            event.preventDefault();
            return;
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
        const clipboardText = event.clipboardData?.getData('text/plain') || '';
        const text = clipboardText.replace(/\s+/gu, ' ');
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
        if (event.target === state.dateInput) {
            state.dateDirty = true;
        }
        clearError(card.querySelector(':scope > .post-inplace-edit-form'));
        clearStatus(card);
    }, false);

    window.addEventListener('resize', () => {
        document.querySelectorAll('.post-card.is-editing').forEach((card) => {
            const state = editorStates.get(card);
            if (state?.contextMenu) {
                closeContextMenu(state, false);
            }
        });
    }, false);

    window.addEventListener('pagehide', (event) => {
        if (event.persisted) {
            return;
        }
        document.querySelectorAll('.post-card.is-editing').forEach((card) => {
            const state = editorStates.get(card);
            if (state) {
                releasePendingMedia(state);
            }
        });
    }, false);
})();
