/* Public editor recovery: dependencies are supplied by the entry module. */
(() => {
    'use strict';

    function create({beginCreate, beginEdit, clearStatus, closeEditor, editableBodyHtml, editorConfig, editorTemplate, focusEdge, prepareEditableMedia, showEditorStatus, editorStates, recoverySessions}) {
        let discardConfirmation = null;

        function closeDiscardConfirmation(restoreFocus) {
            if (!discardConfirmation) return;
            const {backdrop, controller, restoreTarget} = discardConfirmation;
            discardConfirmation = null;
            controller.abort();
            backdrop.remove();
            if (restoreFocus && restoreTarget?.isConnected) restoreTarget.focus({preventScroll: true});
        }

        function confirmDiscardRecoveryCopy(onConfirm, restoreTarget) {
            if (discardConfirmation) {
                discardConfirmation.cancelButton.focus({preventScroll: true});
                return;
            }
            const template = editorTemplate('.post-recovery-discard-template');
            const fragment = template instanceof HTMLTemplateElement
                ? template.content.cloneNode(true)
                : null;
            const backdrop = fragment?.querySelector('.post-recovery-discard-backdrop');
            const cancelButton = fragment?.querySelector('[data-recovery-discard-action="cancel"]');
            if (!(backdrop instanceof HTMLElement) || !(cancelButton instanceof HTMLButtonElement)) return;
            const controller = new AbortController();
            const cancel = () => closeDiscardConfirmation(true);
            discardConfirmation = {backdrop, cancelButton, controller, restoreTarget};
            document.body.append(fragment);
            backdrop.addEventListener('click', event => {
                const button = event.target instanceof Element
                    ? event.target.closest('[data-recovery-discard-action]')
                    : null;
                if (button instanceof HTMLButtonElement) {
                    if (button.dataset.recoveryDiscardAction === 'confirm') {
                        closeDiscardConfirmation(false);
                        onConfirm();
                    } else {
                        cancel();
                    }
                    return;
                }
                if (event.target === backdrop) cancel();
            }, {signal: controller.signal});
            document.addEventListener('keydown', event => {
                if (event.key !== 'Escape') return;
                event.preventDefault();
                event.stopPropagation();
                cancel();
            }, {capture: true, signal: controller.signal});
            cancelButton.focus({preventScroll: true});
        }

        function postRecoveryStore() {
            try {
                const config = editorConfig();
                return window.RegisterPostRecovery?.createStore(
                    {
                        get length() { return window.localStorage.length; },
                        key(index) { return window.localStorage.key(index); },
                        getItem(key) { return window.localStorage.getItem(key); },
                        setItem(key, value) { window.localStorage.setItem(key, value); },
                        removeItem(key) { window.localStorage.removeItem(key); },
                    },
                    config.tagSuggestionsUrl,
                    config.recoveryUserId,
                ) || null;
            } catch (_) {
                return null;
            }
        }

        function recoverySnapshot(state) {
            const body = state.body.cloneNode(true);
            body.querySelectorAll('.post-media-upload, .post-media-picture.is-processing').forEach(node => node.remove());
            return {
                title: state.title.textContent || '',
                body: editableBodyHtml({...state, body}),
                tags: state.tagEditor.snapshot(),
                date: state.dateInput.value,
                slug: state.form.elements.namedItem('slug')?.value || '',
                slugChanged: Boolean(state.slugField && state.slugField.value !== state.originalSlug),
                mediaIds: Array.from(state.uploadedMediaIds),
                pendingMedia: state.mediaUploads.size > 0,
                ...(state.creating ? {requestId: state.requestId} : {}),
            };
        }

        function startPostRecovery(state) {
            const store = postRecoveryStore();
            if (!store) {
                if (editorConfig().recoveryUserId) {
                    showEditorStatus(state, editorConfig().recoveryUnavailable || 'Unable to save a local copy. Keep this tab open.', true);
                }
                return;
            }
            const userId = editorConfig().recoveryUserId;
            const initial = JSON.stringify(recoverySnapshot(state));
            const id = (window.crypto?.randomUUID?.() || `${Date.now()}_${Math.random().toString(36).slice(2)}`).replaceAll('-', '');
            const target = state.creating ? 'new' : state.card.dataset.postId;
            const revision = Number(state.form.elements.namedItem('revision')?.value || 0);
            let stored = null;
            let restored = null;
            let last = initial;
            let timer = null;
            let stopped = false;
            const controller = new AbortController();
            function persist() {
                window.clearTimeout(timer);
                if (stopped || userId !== editorConfig().recoveryUserId) return false;
                const snapshot = recoverySnapshot(state);
                const serialized = JSON.stringify(snapshot);
                if (serialized === initial && !restored) {
                    if (stored) store.remove(stored);
                    stored = null;
                    last = initial;
                    return true;
                }
                if (serialized === last && stored && store.list(target).some(record => (
                    record.id === id && record.savedAt === stored.savedAt
                ))) return true;
                const record = {version: 1, id, target, revision, savedAt: Date.now(), snapshot};
                if (!store.save(record)) {
                    showEditorStatus(state, editorConfig().recoveryUnavailable || 'Unable to save a local copy. Keep this tab open.', true);
                    return false;
                }
                snapshot.mediaIds.forEach(mediaId => state.recoveryMediaIds.add(mediaId));
                stored = record;
                last = serialized;
                const status = state.card.querySelector(':scope > .post-inplace-status');
                if (status?.textContent === editorConfig().recoveryUnavailable) clearStatus(state.card);
                if (restored) {
                    store.remove(restored);
                    restored = null;
                }
                return true;
            }
            function schedule() {
                window.clearTimeout(timer);
                timer = window.setTimeout(persist, 250);
            }
            const observer = new MutationObserver(schedule);
            observer.observe(state.body, {childList: true, subtree: true, characterData: true, attributes: true});
            observer.observe(state.title, {childList: true, subtree: true, characterData: true});
            state.card.addEventListener('input', schedule, {signal: controller.signal});
            state.card.addEventListener('change', schedule, {signal: controller.signal});
            state.recovery = {
                id,
                persist,
                hasStored: () => stored !== null,
                restored(record) { restored = record; return persist(); },
                stop(discard = false) {
                    if (!discard) persist();
                    const preserved = !discard && stored !== null;
                    stopped = true;
                    window.clearTimeout(timer);
                    observer.disconnect();
                    controller.abort();
                    if (discard) {
                        if (stored) store.remove(stored);
                        if (restored) store.remove(restored);
                    }
                    recoverySessions.delete(state);
                    state.recovery = null;
                    return preserved;
                },
            };
            recoverySessions.add(state);
        }

        function restorePostRecovery(state, record) {
            if (!window.RegisterPostRecovery) return;
            const snapshot = record.snapshot;
            if (state.creating && snapshot.requestId) {
                state.requestId = snapshot.requestId;
                state.form.elements.namedItem('request_id').value = snapshot.requestId;
            }
            state.history?.before();
            state.titleHistory?.before();
            state.title.textContent = snapshot.title;
            const legacyHtml = snapshot.htmlSource === true;
            const safeBody = legacyHtml ? snapshot.body : window.RegisterPostRecovery.cleanBody(snapshot.body);
            const comparison = document.createElement('template');
            comparison.innerHTML = snapshot.body;
            window.RegisterEditorHtmlBlocks.strip(comparison.content);
            const markupChanged = !legacyHtml && safeBody !== comparison.innerHTML;
            if (legacyHtml) state.body.replaceChildren(window.RegisterEditorHtmlBlocks.block(snapshot.body));
            else state.body.innerHTML = safeBody;
            window.RegisterEditorHtmlBlocks.strip(state.body);
            prepareEditableMedia(state.body);
            state.htmlBlocks?.prepare();
            state.tagEditor.restore(snapshot.tags);
            state.dateInput.value = snapshot.date;
            const slug = state.form.elements.namedItem('slug');
            if (slug && snapshot.slugChanged) slug.value = snapshot.slug;
            state.uploadedMediaIds = new Set(snapshot.mediaIds);
            snapshot.mediaIds.forEach(mediaId => state.recoveryMediaIds.add(mediaId));
            state.titleDirty = state.bodyDirty = state.tagsDirty = state.dateDirty = true;
            state.history?.record();
            state.titleHistory?.record();
            const persisted = state.recovery?.restored(record);
            if (persisted) showEditorStatus(state, [
                editorConfig().recoveryRestored || 'Text restored. Review it before saving.',
                snapshot.pendingMedia || snapshot.mediaIds.length > 0 ? editorConfig().recoveryMedia : '',
                markupChanged ? editorConfig().recoveryMarkup : '',
            ].filter(Boolean).join(' '), false, 5000);
            else showEditorStatus(state, editorConfig().recoveryUnavailable || 'Unable to save a local copy. Keep this tab open.', true);
            focusEdge(state.body, true);
            refreshPostRecoveryOffers();
        }

        function refreshPostRecoveryOffers() {
            document.querySelectorAll('.post-recovery-notice').forEach(notice => notice.remove());
            const store = postRecoveryStore();
            if (!store) return;
            const active = new Set(Array.from(recoverySessions, state => state.recovery?.id));
            store.list().filter(record => !active.has(record.id)).forEach(record => {
                const card = record.target === 'new' ? null
                    : document.querySelector(`.post-card.is-manageable[data-post-id="${record.target}"]`);
                const button = card?.querySelector('.post-edit-start')
                    || (record.target === 'new' ? document.querySelector('.post-create-start') : null);
                if (!(button instanceof HTMLButtonElement)) return;
                const anchor = card || document.querySelector('.live-post-feed, .tag-post-list, #content');
                if (!(anchor instanceof HTMLElement)) return;
                const notice = document.createElement('aside');
                notice.className = 'post-recovery-notice';
                notice.dataset.recoveryId = record.id;
                const summary = document.createElement('p');
                summary.textContent = (editorConfig().recoveryFound || 'This device has unsaved text:') + ' '
                    + (record.snapshot.title || editorConfig().titlePlaceholder || 'New post');
                notice.append(summary);
                const currentRevision = Number(card?.querySelector('input[name="revision"]')?.value || 0);
                if (record.target !== 'new' && currentRevision !== record.revision) {
                    const warning = document.createElement('p');
                    warning.textContent = editorConfig().recoveryChanged || 'The site has a newer version. Review the restored text before saving.';
                    notice.append(warning);
                }
                const actions = document.createElement('div');
                actions.className = 'post-recovery-actions';
                const restore = document.createElement('button');
                restore.type = 'button';
                restore.textContent = editorConfig().recoveryRestore || 'Restore text';
                const discard = document.createElement('button');
                discard.type = 'button';
                discard.textContent = editorConfig().recoveryDiscard || 'Delete local copy';
                restore.addEventListener('click', () => {
                    // Open through the same permission-scoped controls as ordinary
                    // editing; retain the CURRENT server revision for conflict checks.
                    const editing = card || document.querySelector('.post-card[data-post-creating]');
                    const previous = editing ? editorStates.get(editing) : null;
                    if (previous?.submitting) return;
                    if (previous && !closeEditor(editing, false)) return;
                    if (!(record.target === 'new' ? beginCreate(button) : beginEdit(button))) return;
                    const opened = card || document.querySelector('.post-card[data-post-creating]');
                    const state = editorStates.get(opened);
                    if (state) restorePostRecovery(state, record);
                });
                discard.addEventListener('click', () => {
                    confirmDiscardRecoveryCopy(() => {
                        store.remove(record);
                        refreshPostRecoveryOffers();
                    }, discard);
                });
                actions.append(restore, discard);
                notice.append(actions);
                if (card) card.before(notice);
                else anchor.prepend(notice);
            });
        }

        return {postRecoveryStore, recoverySnapshot, startPostRecovery, restorePostRecovery, refreshPostRecoveryOffers};
    }

    window.RegisterEditorRecovery = Object.freeze({create});
})();
