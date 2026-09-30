/* Public editor history: dependencies are supplied by the entry module. */
(() => {
    'use strict';

    function create({clearBoundaryCaret, clearError, clearStatus, closeContextMenu, focusEdge, selectionIsInside, syncBoundaryCaret, updateMediaUrls, getPendingMediaClipboard, editorPlatform}) {
        function createBodyHistory(state, restoreCallback = null) {
            const uploads = new Map();
            const liveImages = new WeakMap();
            const ignored = '[data-post-inline-code-exit], .post-editor-context-anchor';
            let uploadSequence = 0;
            let index = 0;
            let depth = 0;
            let queued = false;
            let destroyed = false;
            let previousInput = null;
            let mergeInput = false;

            const children = (node) => Array.from(node.childNodes).filter((child) => (
                !(child instanceof Element) || !child.matches(ignored)
            ));
            function point(node, offset) {
                if (!node || !state.body.contains(node)) {
                    return null;
                }
                const path = [];
                const position = node.nodeType === Node.TEXT_NODE
                    ? offset
                    : Array.from(node.childNodes).slice(0, offset).filter((child) => (
                        !(child instanceof Element) || !child.matches(ignored)
                    )).length;
                while (node !== state.body) {
                    const parent = node.parentNode;
                    path.unshift(children(parent).indexOf(node));
                    node = parent;
                }
                return {path, offset: position};
            }
            function selection() {
                const selected = window.getSelection();
                const anchor = point(selected?.anchorNode, selected?.anchorOffset);
                const focus = point(selected?.focusNode, selected?.focusOffset);
                return anchor && focus ? {anchor, focus} : null;
            }
            const imagesIn = (root) => root instanceof HTMLImageElement ? [root] : Array.from(root.querySelectorAll('img'));
            function cloneWithImages(source) {
                const root = source.cloneNode(true);
                const images = imagesIn(source);
                imagesIn(root).forEach((image, index) => liveImages.set(image, images[index]));
                return root;
            }
            function snapshot() {
                const root = cloneWithImages(state.body);
                root.querySelectorAll(ignored).forEach((node) => node.remove());
                root.querySelectorAll('.has-leading-boundary-caret').forEach(clearBoundaryCaret);
                // A pending upload is one stable slot, not a succession of progress
                // messages. Restoration reuses its live node, including its listeners.
                root.querySelectorAll('[data-post-history-upload]').forEach((node) => {
                    const slot = document.createElement('span');
                    slot.dataset.postHistoryUpload = node.dataset.postHistoryUpload;
                    node.replaceWith(slot);
                });
                root.querySelectorAll('[class=""]').forEach((node) => node.removeAttribute('class'));
                return {root, html: root.innerHTML, selection: selection()};
            }
            let entries = [snapshot()];
            const suspended = () => destroyed || depth > 0 || state.imageCaptionEditor || state.mediaCaptionEditors.size > 0;
            function trim() {
                let size = entries.reduce((sum, entry) => sum + entry.html.length, 0);
                // Bound both operation count and serialized size. Always retain the
                // current and previous state, even for an exceptionally large post.
                while (entries.length > 2 && index > 1 && (entries.length > 100 || size > 4 * 1024 * 1024)) {
                    size -= entries.shift().html.length;
                    index--;
                }
            }
            function record(type = '') {
                if (suspended()) {
                    return;
                }
                queued = false;
                const next = snapshot();
                if (next.html === entries[index].html) {
                    if (next.selection) entries[index].selection = next.selection;
                    return;
                }
                entries.splice(index + 1);
                if (mergeInput && previousInput?.type === type && index > 0) {
                    entries[index] = next;
                } else {
                    entries.push(next);
                    index++;
                }
                mergeInput = false;
                previousInput = /^(insertText|insertCompositionText|deleteContentBackward|deleteContentForward)$/u.test(type)
                    ? {type, time: Date.now(), selection: next.selection}
                    : null;
                trim();
            }
            function before(type = '') {
                if (suspended()) return;
                if (queued) record();
                const current = snapshot();
                if (current.html !== entries[index].html) record();
                mergeInput = Boolean(previousInput && previousInput.type === type
                    && Date.now() - previousInput.time < 1000
                    && JSON.stringify(previousInput.selection) === JSON.stringify(current.selection));
                if (current.selection) entries[index].selection = current.selection;
            }
            function restorePoint(saved) {
                let node = state.body;
                for (const part of saved.path) {
                    if (!node.childNodes[part]) break;
                    node = node.childNodes[part];
                }
                return [node, Math.min(saved.offset, node.nodeType === Node.TEXT_NODE ? node.data.length : node.childNodes.length)];
            }
            function travel(direction) {
                if (suspended()) return false;
                if (queued) record();
                const target = index + direction;
                if (target < 0 || target >= entries.length) return false;
                closeContextMenu(state, false);
                index = target;
                previousInput = null;
                mergeInput = false;
                const entry = entries[index];
                const restored = entry.root.cloneNode(true);
                // Keep each image occurrence's identity so delayed AI descriptions
                // still target it. Restore attributes from the snapshot, preserving
                // the existing source/alt guards against stale replies.
                const savedImages = imagesIn(entry.root);
                imagesIn(restored).forEach((image, index) => {
                    const live = liveImages.get(savedImages[index]);
                    if (!live) return;
                    Array.from(live.attributes).forEach(({name}) => {
                        if (!image.hasAttribute(name)) live.removeAttribute(name);
                    });
                    Array.from(image.attributes).forEach(({name, value}) => {
                        if (live.getAttribute(name) !== value) live.setAttribute(name, value);
                    });
                    image.replaceWith(live);
                });
                restored.querySelectorAll('[data-post-history-upload]').forEach((slot) => {
                    const pending = uploads.get(slot.dataset.postHistoryUpload);
                    if (pending) slot.replaceWith(pending.element);
                    else slot.remove();
                });
                state.body.replaceChildren(...restored.childNodes);
                state.bodyDirty = true;
                state.body.focus({preventScroll: true});
                if (entry.selection) {
                    window.getSelection()?.setBaseAndExtent(
                        ...restorePoint(entry.selection.anchor), ...restorePoint(entry.selection.focus),
                    );
                } else {
                    focusEdge(state.body, true);
                }
                clearError(state.form);
                clearStatus(state.card);
                syncBoundaryCaret();
                restoreCallback?.();
                return true;
            }
            function trackUpload(pending) {
                const id = String(++uploadSequence);
                pending.element.dataset.postHistoryUpload = id;
                uploads.set(id, pending);
            }
            return {
                before,
                record,
                transact(change) {
                    before();
                    depth++;
                    try { return change(); }
                    finally { depth--; record(); }
                },
                schedule() {
                    if (suspended()) return;
                    queued = true;
                    queueMicrotask(() => { if (queued) record(); });
                },
                undo: () => travel(-1),
                redo: () => travel(1),
                redateMedia(mediaById) {
                    // Renaming a server file is not an edit to undo. Every retained
                    // state, including the redo branch, must point to its new URL.
                    entries.forEach((entry) => {
                        updateMediaUrls(entry.root, mediaById);
                        entry.html = entry.root.innerHTML;
                    });
                    if (getPendingMediaClipboard()?.state === state) {
                        getPendingMediaClipboard().uploads.forEach(pending => {
                            if (pending.completed) updateMediaUrls(pending.completed, mediaById);
                        });
                    }
                    trim();
                },
                clipboardUploads(root) {
                    return new Map(Array.from(root.querySelectorAll('[data-post-history-upload]'))
                        .map(node => {
                            const pending = uploads.get(node.dataset.postHistoryUpload);
                            return [node.dataset.postHistoryUpload, pending?.original || pending];
                        })
                        .filter(([_id, pending]) => pending));
                },
                trackUpload,
                cloneUpload(pending) {
                    const element = pending.element.cloneNode(true);
                    trackUpload({element, original: pending});
                    return element;
                },
                resolveUpload(pending, completed) {
                    pending.element.removeAttribute('data-post-history-upload');
                    if (destroyed) return;
                    // Completion amends the insertion in every retained state. It
                    // never adds an undo step or resurrects an insertion already undone.
                    uploads.forEach((occurrence, id) => {
                        if (occurrence !== pending && occurrence.original !== pending) return;
                        uploads.delete(id);
                        // Copies share a request, but retain distinct image identities
                        // through completion and every undo/redo snapshot.
                        const result = occurrence === pending ? completed : completed?.cloneNode(true);
                        if (occurrence !== pending) {
                            if (result) occurrence.element.replaceWith(result);
                            else occurrence.element.remove();
                        }
                        entries.forEach((entry) => {
                            entry.root.querySelectorAll(`[data-post-history-upload="${id}"]`).forEach((slot) => {
                                if (result) slot.replaceWith(cloneWithImages(result));
                                else slot.remove();
                            });
                            entry.html = entry.root.innerHTML;
                        });
                    });
                    trim();
                },
                destroy() { destroyed = true; queued = false; entries = []; uploads.clear(); },
                get length() { return entries.length; },
            };
        }

        function createFieldHistory(state, field, controller, restoreCallback = null) {
            const history = createBodyHistory({
                ...state,
                body: field,
                contextMenu: null,
                imageCaptionEditor: null,
                mediaCaptionEditors: new Map(),
            }, restoreCallback);
            const keydown = (event) => {
                if (!selectionIsInside(field) || event.isComposing || event.altKey || !(event.ctrlKey || event.metaKey)) return;
                const key = String(event.key || '').toLowerCase();
                const undo = !event.shiftKey && (event.code === 'KeyZ' || key === 'z');
                const redo = editorPlatform === 'windows'
                    ? !event.shiftKey && (event.code === 'KeyY' || key === 'y')
                    : event.shiftKey && (event.code === 'KeyZ' || key === 'z');
                if (undo || redo) {
                    event.preventDefault();
                    event.stopPropagation();
                    history[undo ? 'undo' : 'redo']();
                }
            };
            field.addEventListener('keydown', keydown, {signal: controller.signal});
            state.body.addEventListener('keydown', keydown, {signal: controller.signal});
            field.addEventListener('beforeinput', (event) => {
                if (event.inputType === 'historyUndo' || event.inputType === 'historyRedo') {
                    event.preventDefault();
                    history[event.inputType === 'historyUndo' ? 'undo' : 'redo']();
                } else {
                    history.before(event.inputType);
                }
            }, {signal: controller.signal});
            field.addEventListener('input', (event) => {
                history.record(event.inputType);
            }, {signal: controller.signal});
            const destroy = history.destroy;
            history.destroy = () => {
                controller.abort();
                destroy();
            };
            return history;
        }

        return {createBodyHistory, createFieldHistory};
    }

    window.RegisterEditorHistory = Object.freeze({create});
})();
