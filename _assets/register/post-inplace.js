(() => {
    'use strict';

    const editorStates = new WeakMap();
    const editorConfigs = new WeakMap();
    const emptyEditorConfig = Object.freeze({});
    const recoverySessions = new Set();
    let inlineCodeBoundarySequence = 0;
    let pendingMediaClipboard = null;
    let activeMediaDrag = null;
    const mediaDragType = 'application/x-register-media';
    const imageOptimizerUrl = (() => {
        const source = new URL(document.currentScript?.src || window.location.href, window.location.href);
        const target = new URL('image-optimizer/js/optimizer.js', source);
        const version = source.searchParams.get('v');
        if (version !== null) {
            target.searchParams.set('v', version);
        }
        return target.toString();
    })();
    let imageOptimizerPromise = null;
    const aiImagePreviewMaxDimension = 1600;
    const aiImagePreviewPreferPngBytes = 1.5 * 1024 * 1024;
    const aiImagePreviewMaxPngBytes = 9 * 1024 * 1024;
    const aiImagePreviewJpegQuality = 0.9;
    const imageExtensions = new Set(['avif', 'bmp', 'gif', 'ico', 'jpeg', 'jpg', 'png', 'webp']);
    const audioExtensions = new Set(['flac', 'mkv', 'mp3', 'mp4', 'ogg', 'wav', 'webm']);
    const aiCorrectionTokenPattern = /<[^>]*>|[\p{L}\p{N}_]+|\s+|[^\p{L}\p{N}_\s<>]+|[<>]/gu;
    const aiCorrectionMaxLookAhead = 24;
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
            'create': ['⌥⌘N', 'Meta+Alt+N'],
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
            'create': ['Ctrl+Alt+N', 'Control+Alt+N'],
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

    const {createEditorFieldSurfaces} = window.RegisterEditorFields.create({});

    const {clearBoundaryCaret, clearSyntheticBoundaryCaret, boundaryNodeIsEmpty, isMediaBoundaryElement, editorBoundaryParagraphIsEmpty, topLevelBodyChild, hoistMediaFromParagraph, normalizeLeadingNestedMedia, leadingMediaIndex, prepareMediaInsertionRange, focusBeforeLeadingMedia, focusAfterMedia, mediaBoundaryAtRange, syncBoundaryCaret, moveInsertionBeforeMediaBoundary, protectSelectedMediaBoundary, collapseEmptyParagraphBesideMedia, expandCollapsedBoundaryParagraph, collapseEmptyLeadingParagraphAfterDelete, isMediaOwnedDirectChild, normalizeMediaBodyStructure} = window.RegisterEditorBoundaries.create({
        rangeIsInside, editorStates,
    });

    const {createBodyHistory, createFieldHistory} = window.RegisterEditorHistory.create({
        clearBoundaryCaret, clearError, clearStatus, closeContextMenu, focusEdge, selectionIsInside, syncBoundaryCaret, updateMediaUrls, getPendingMediaClipboard: () => pendingMediaClipboard, editorPlatform,
    });

    const {normalizeTags, loadTagSuggestions, createTagEditor} = window.RegisterEditorTags.create({
        clearError, clearStatus, editorConfig, handleEditingSaveShortcut, showError,
    });

    const {postRecoveryStore, recoverySnapshot, startPostRecovery, restorePostRecovery, refreshPostRecoveryOffers} = window.RegisterEditorRecovery.create({
        beginCreate, beginEdit, clearStatus, closeEditor, editableBodyHtml, editorConfig, focusEdge, prepareEditableMedia, showEditorStatus, editorStates, recoverySessions,
    });

    // The page owns one config and template set, including after partial navigation.
    // Cache by DOM node so replacing the page also replaces its language and settings.
    function editorConfig() {
        const resources = document.getElementById('post-editor-resources');
        if (!resources) {
            return emptyEditorConfig;
        }
        if (!editorConfigs.has(resources)) {
            let config = emptyEditorConfig;
            try {
                const parsed = JSON.parse(resources.dataset.config);
                if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                    config = Object.freeze(parsed);
                }
            } catch (_error) {
                // The editor can still use its built-in labels if configuration is missing.
            }
            editorConfigs.set(resources, config);
        }
        return editorConfigs.get(resources);
    }

    function editorTemplate(selector) {
        return document.getElementById('post-editor-resources')?.querySelector(selector) || null;
    }

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

    // Keep this comparison aligned with the admin editor's text/corrections.js.
    function tokenizeAiCorrection(text) {
        const result = [];
        let match;
        aiCorrectionTokenPattern.lastIndex = 0;
        while ((match = aiCorrectionTokenPattern.exec(text)) !== null) {
            result.push({
                text: match[0],
                start: match.index,
                end: match.index + match[0].length,
            });
        }
        return result;
    }

    function findAiCorrectionAnchor(before, after, beforeIndex, afterIndex) {
        let best = null;
        const beforeLimit = Math.min(before.length, beforeIndex + aiCorrectionMaxLookAhead + 1);
        const afterLimit = Math.min(after.length, afterIndex + aiCorrectionMaxLookAhead + 1);

        for (let i = beforeIndex; i < beforeLimit; i++) {
            for (let j = afterIndex; j < afterLimit; j++) {
                if (before[i].text !== after[j].text) {
                    continue;
                }

                const distance = i - beforeIndex + j - afterIndex;
                if (best === null || distance < best.distance) {
                    best = {beforeIndex: i, afterIndex: j, distance};
                }
            }
        }

        return best;
    }

    function addAiCorrectionRange(ranges, text, start, end) {
        while (start < end && /\s/u.test(text[start])) {
            start++;
        }
        while (end > start && /\s/u.test(text[end - 1])) {
            end--;
        }
        if (start === end) {
            return;
        }

        const previous = ranges[ranges.length - 1];
        if (previous && previous.end === start) {
            previous.end = end;
            return;
        }
        ranges.push({start, end});
    }

    function findAiCorrectionRanges(source, corrected) {
        if (source === corrected) {
            return [];
        }

        const before = tokenizeAiCorrection(source);
        const after = tokenizeAiCorrection(corrected);
        const ranges = [];
        let beforeIndex = 0;
        let afterIndex = 0;

        while (beforeIndex < before.length && afterIndex < after.length) {
            if (before[beforeIndex].text === after[afterIndex].text) {
                beforeIndex++;
                afterIndex++;
                continue;
            }

            const anchor = findAiCorrectionAnchor(before, after, beforeIndex, afterIndex);
            if (anchor === null) {
                addAiCorrectionRange(ranges, corrected, after[afterIndex].start, corrected.length);
                return ranges;
            }

            if (anchor.afterIndex > afterIndex) {
                addAiCorrectionRange(
                    ranges,
                    corrected,
                    after[afterIndex].start,
                    after[anchor.afterIndex - 1].end,
                );
            }
            beforeIndex = anchor.beforeIndex;
            afterIndex = anchor.afterIndex;
        }

        if (afterIndex < after.length) {
            addAiCorrectionRange(ranges, corrected, after[afterIndex].start, corrected.length);
        }

        return ranges;
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

    function acknowledgeLiveMutation(payload) {
        return Number.isSafeInteger(payload?.live_cursor)
            && payload.live_cursor > 0
            && window.RegisterLiveUpdates?.acknowledge?.(payload.live_cursor) === true;
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

    function closePostToolsMenu(tools, restoreFocus = false) {
        if (!(tools instanceof HTMLElement)) {
            return;
        }
        const wasOpen = tools.classList.contains('is-menu-open');
        const toggle = tools.querySelector('.post-tools-menu-toggle');
        tools.classList.remove('is-menu-open');
        toggle?.setAttribute('aria-expanded', 'false');
        if (wasOpen && restoreFocus && toggle instanceof HTMLElement) {
            toggle.focus();
        }
    }

    function closeOtherPostToolsMenus(activeTools = null) {
        document.querySelectorAll('.post-inplace-tools.is-menu-open').forEach((tools) => {
            if (tools !== activeTools) {
                closePostToolsMenu(tools, false);
            }
        });
    }

    function postToolsFocusTarget(card, fallbackSelector) {
        if (!(card instanceof HTMLElement)) {
            return null;
        }
        const toggle = card.querySelector(':scope > .post-inplace-tools .post-tools-menu-toggle');
        if (toggle instanceof HTMLElement && toggle.getClientRects().length > 0) {
            return toggle;
        }
        const fallback = card.querySelector(':scope > .post-inplace-tools ' + fallbackSelector);
        return fallback instanceof HTMLElement ? fallback : null;
    }

    function toggleEditingTools(card, editing) {
        const tools = card.querySelector(':scope > .post-inplace-tools');
        if (!tools) {
            return;
        }
        closePostToolsMenu(tools, false);
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

    function dateTimeText(date, locale) {
        try {
            let dateText = new Intl.DateTimeFormat(locale || undefined, {dateStyle: 'long'}).format(date);
            const timeText = new Intl.DateTimeFormat(locale || undefined, {timeStyle: 'short'}).format(date);
            if (locale?.toLowerCase().startsWith('ru')) {
                dateText = dateText.replace(/\s+г\.$/u, ' года');
                return `${dateText}, ${timeText}`;
            }
            return `${dateText}. ${timeText}`;
        } catch (_error) {
            return date.toLocaleString();
        }
    }

    function updateDatePreview(state) {
        const date = new Date(state.dateInput.value);
        if (Number.isNaN(date.getTime())) {
            return;
        }
        state.time.dateTime = date.toISOString();
        state.time.textContent = dateTimeText(date, state.time.dataset.locale || document.documentElement.lang);
    }

    function editorPublishedAt(state) {
        return state.dateDirty
            ? Math.floor(new Date(state.dateInput.value).getTime() / 1000)
            : state.originalPublishedAt;
    }

    function loadImageOptimizer() {
        // This computed dynamic import is the loading boundary for every image codec and Wasm module.
        // Opening the editor and uploading audio must not fetch any part of the image optimizer.
        imageOptimizerPromise ||= import(imageOptimizerUrl).catch((error) => {
            imageOptimizerPromise = null;
            throw error;
        });
        return imageOptimizerPromise;
    }

    function aiAltAbortError() {
        return new DOMException('Alt text generation was cancelled.', 'AbortError');
    }

    function throwIfAiAltAborted(signal) {
        if (signal.aborted) {
            throw aiAltAbortError();
        }
    }

    function loadAiAltImage(source, signal) {
        if (source instanceof HTMLImageElement && source.complete && source.naturalWidth > 0) {
            return Promise.resolve({image: source, release: () => {}});
        }

        if (source instanceof HTMLImageElement) {
            return new Promise((resolve, reject) => {
                const image = new Image();
                let settled = false;
                const cleanup = () => {
                    signal.removeEventListener('abort', abort);
                    image.onload = null;
                    image.onerror = null;
                };
                const fail = (error) => {
                    if (!settled) {
                        settled = true;
                        cleanup();
                        reject(error);
                    }
                };
                const abort = () => fail(aiAltAbortError());
                image.onload = () => {
                    if (!settled) {
                        settled = true;
                        cleanup();
                        resolve({image, release: () => {}});
                    }
                };
                image.onerror = () => fail(new Error('The image cannot be decoded for alt text.'));
                signal.addEventListener('abort', abort, {once: true});
                image.decoding = 'async';
                image.src = source.currentSrc || source.src;
            });
        }

        if (!(source instanceof Blob)) {
            return Promise.reject(new Error('The image source for alt text is unavailable.'));
        }

        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(source);
            const image = new Image();
            let settled = false;
            const cleanup = () => {
                signal.removeEventListener('abort', abort);
                image.onload = null;
                image.onerror = null;
            };
            const fail = (error) => {
                if (settled) {
                    return;
                }
                settled = true;
                cleanup();
                URL.revokeObjectURL(url);
                reject(error);
            };
            const abort = () => fail(aiAltAbortError());
            image.onload = () => {
                if (settled) {
                    return;
                }
                settled = true;
                cleanup();
                resolve({
                    image,
                    release: () => URL.revokeObjectURL(url),
                });
            };
            image.onerror = () => fail(new Error('The image cannot be decoded for alt text.'));
            signal.addEventListener('abort', abort, {once: true});
            image.decoding = 'async';
            image.src = url;
        });
    }

    function aiAltCanvasBlob(canvas, type, quality, signal) {
        return new Promise((resolve, reject) => {
            throwIfAiAltAborted(signal);
            let settled = false;
            const abort = () => {
                if (!settled) {
                    settled = true;
                    reject(aiAltAbortError());
                }
            };
            signal.addEventListener('abort', abort, {once: true});
            try {
                canvas.toBlob((blob) => {
                    signal.removeEventListener('abort', abort);
                    if (settled) {
                        return;
                    }
                    settled = true;
                    if (blob instanceof Blob && blob.size > 0) {
                        resolve(blob);
                    } else {
                        reject(new Error('The browser cannot prepare the image for alt text.'));
                    }
                }, type, quality);
            } catch (error) {
                signal.removeEventListener('abort', abort);
                settled = true;
                reject(error);
            }
        });
    }

    function aiAltCanvasContext(canvas) {
        let context = null;
        try {
            context = canvas.getContext('2d', {colorSpace: 'srgb'});
        } catch (_error) {
            context = null;
        }
        return context || canvas.getContext('2d');
    }

    async function prepareAiAltPreview(source, signal) {
        const decoded = await loadAiAltImage(source, signal);
        try {
            throwIfAiAltAborted(signal);
            const sourceWidth = decoded.image.naturalWidth || decoded.image.width;
            const sourceHeight = decoded.image.naturalHeight || decoded.image.height;
            if (sourceWidth <= 0 || sourceHeight <= 0) {
                throw new Error('The image has invalid dimensions.');
            }

            const scale = Math.min(1, aiImagePreviewMaxDimension / Math.max(sourceWidth, sourceHeight));
            const width = Math.max(1, Math.round(sourceWidth * scale));
            const height = Math.max(1, Math.round(sourceHeight * scale));
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const context = aiAltCanvasContext(canvas);
            if (context === null) {
                throw new Error('The browser cannot prepare the image for alt text.');
            }
            context.drawImage(decoded.image, 0, 0, width, height);

            const png = await aiAltCanvasBlob(canvas, 'image/png', undefined, signal);
            if (png.size <= aiImagePreviewPreferPngBytes) {
                return {blob: png, extension: 'png'};
            }

            const jpegCanvas = document.createElement('canvas');
            jpegCanvas.width = width;
            jpegCanvas.height = height;
            const jpegContext = aiAltCanvasContext(jpegCanvas);
            if (jpegContext === null) {
                if (png.size <= aiImagePreviewMaxPngBytes) {
                    return {blob: png, extension: 'png'};
                }
                throw new Error('The browser cannot prepare the image for alt text.');
            }
            jpegContext.fillStyle = '#fff';
            jpegContext.fillRect(0, 0, width, height);
            jpegContext.drawImage(decoded.image, 0, 0, width, height);
            const jpeg = await aiAltCanvasBlob(
                jpegCanvas,
                'image/jpeg',
                aiImagePreviewJpegQuality,
                signal,
            );
            if (png.size <= aiImagePreviewMaxPngBytes && png.size <= jpeg.size * 1.25) {
                return {blob: png, extension: 'png'};
            }
            return {blob: jpeg, extension: 'jpg'};
        } finally {
            decoded.release();
        }
    }

    function releasableMediaIds(state) {
        // Once exposed through recovery, a file may be in another live editor
        // even after its local copy is removed. Let the server expire it instead.
        return Array.from(state.uploadedMediaIds).filter(id => !state.recoveryMediaIds.has(id));
    }

    function releasePendingMedia(state) {
        const mediaIds = releasableMediaIds(state);
        state.uploadedMediaIds.clear();
        if (mediaIds.length === 0) {
            return;
        }
        const token = state.form.elements.namedItem('inplace_token');
        const data = new FormData();
        data.set('inplace_action', 'media_release');
        data.set('inplace_token', token instanceof HTMLInputElement ? token.value : '');
        data.set('media_ids', mediaIds.join(','));
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

    function detachEditableBodyStyles(body) {
        return Array.from(body.querySelectorAll('style')).map((style, index) => {
            document.head.append(style);
            return {index, style};
        });
    }

    function restoreEditableBodyStyles(state) {
        const replacements = Array.from(state.body.querySelectorAll('style'));
        state.detachedBodyStyles.forEach(({index, style}) => {
            if (replacements[index]?.isConnected) {
                replacements[index].replaceWith(style);
            } else {
                state.body.append(style);
            }
        });
        state.detachedBodyStyles.length = 0;
    }

    function restoreTypographicNoBreaks(body, words, editing = true) {
        if (words.size === 0) {
            return;
        }
        const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT);
        const nodes = [];
        let node;
        while ((node = walker.nextNode())) {
            if (!node.parentElement.closest('nobr, pre, code, tt, kbd, style, script, textarea')) {
                nodes.push(node);
            }
        }
        nodes.forEach((text) => {
            const matches = Array.from(text.data.matchAll(/[^\s<>-]+-[^\s<]+/gu))
                .filter((match) => words.has(match[0]));
            matches.reverse().forEach((match) => {
                const word = text.splitText(match.index);
                word.splitText(match[0].length);
                const wrapper = document.createElement('nobr');
                if (editing) {
                    wrapper.setAttribute('data-post-editor-nowrap', '');
                }
                word.replaceWith(wrapper);
                wrapper.append(word);
            });
        });
    }

    function createFootHeightSpacer(foot) {
        const spacer = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        spacer.classList.add('post-editor-foot-spacer');
        spacer.setAttribute('width', '0');
        spacer.setAttribute('height', String(foot.getBoundingClientRect().height));
        spacer.setAttribute('aria-hidden', 'true');
        spacer.setAttribute('focusable', 'false');
        return spacer;
    }

    // Paint editor fields outside the content flow. Font leading belongs to the text layout,
    // not to the field's padding; measuring it keeps the visible gutters equal without moving
    // a baseline, changing line wrapping, or adding nodes to the editable/saved HTML.
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
        syncBoundaryCaret();
    }

    function prepareEditableMedia(root) {
        normalizeMediaBodyStructure(root);
        root.querySelectorAll('audio[controls]').forEach((audio) => {
            audio.setAttribute('data-register-audio-native', '');
        });
        prepareInlineMediaCaptionEntries(root);
    }

    function clearAiChangeMarks(root) {
        const marks = root.querySelectorAll('.post-editor-ai-change');
        if (marks.length === 0) {
            return;
        }

        const selection = window.getSelection();
        const preserveSelection = selection?.rangeCount > 0
            && root.contains(selection.anchorNode)
            && root.contains(selection.focusNode);
        const anchorNode = preserveSelection ? selection.anchorNode : null;
        const anchorOffset = preserveSelection ? selection.anchorOffset : 0;
        const focusNode = preserveSelection ? selection.focusNode : null;
        const focusOffset = preserveSelection ? selection.focusOffset : 0;

        marks.forEach((mark) => {
            mark.replaceWith(...mark.childNodes);
        });

        // Moving the marked text nodes makes the browser relocate its live caret
        // to the former span boundary. Restore the original position in the text.
        if (preserveSelection && root.contains(anchorNode) && root.contains(focusNode)) {
            selection.setBaseAndExtent(anchorNode, anchorOffset, focusNode, focusOffset);
        }
    }

    function textFromHtml(html) {
        const template = document.createElement('template');
        template.innerHTML = html;
        return template.content.textContent || '';
    }

    function textSegments(roots) {
        const segments = [];
        let offset = 0;

        roots.forEach((root) => {
            const nodes = [];
            if (root instanceof Text) {
                nodes.push(root);
            } else {
                const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
                let node;
                while ((node = walker.nextNode()) !== null) {
                    nodes.push(node);
                }
            }

            nodes.forEach((node) => {
                const start = offset;
                offset += node.data.length;
                segments.push({node, start, end: offset});
            });
        });

        return segments;
    }

    function markAiChanges(roots, sourceText) {
        const segments = textSegments(roots);
        const correctedText = segments.map((segment) => segment.node.data).join('');
        const ranges = findAiCorrectionRanges(sourceText, correctedText);
        const portions = [];

        ranges.forEach((range) => {
            segments.forEach((segment) => {
                const start = Math.max(range.start, segment.start);
                const end = Math.min(range.end, segment.end);
                if (start < end) {
                    portions.push({
                        node: segment.node,
                        start: start - segment.start,
                        end: end - segment.start,
                        documentOffset: start,
                    });
                }
            });
        });

        portions.sort((left, right) => right.documentOffset - left.documentOffset);
        portions.forEach((portion) => {
            let changedText = portion.node;
            if (portion.end < changedText.data.length) {
                changedText.splitText(portion.end);
            }
            if (portion.start > 0) {
                changedText = changedText.splitText(portion.start);
            }
            const mark = document.createElement('span');
            mark.className = 'post-editor-ai-change';
            changedText.replaceWith(mark);
            mark.append(changedText);
        });
    }

    function removeInlineCodeExitMarkers(root) {
        root.querySelectorAll('[data-post-inline-code-exit]').forEach((marker) => marker.remove());
    }

    function removeTrailingEditorArtifacts(root) {
        root.querySelectorAll('.post-media-picture').forEach((picture) => {
            if (!picture.querySelector('img, video, audio') && picture.textContent.trim() === '') {
                picture.remove();
            }
        });
        while (editorBoundaryParagraphIsEmpty(root.lastElementChild)) {
            root.lastElementChild.remove();
        }
    }

    function editableBodyHtml(state) {
        const clone = state.body.cloneNode(true);
        normalizeMediaBodyStructure(clone);
        removeInlineCodeExitMarkers(clone);
        clone.querySelectorAll('p.post-editor-collapsed-boundary-paragraph').forEach((paragraph) => {
            paragraph.remove();
        });
        removeTrailingEditorArtifacts(clone);
        clone.querySelectorAll('[data-post-editor-nowrap]').forEach((wrapper) => {
            wrapper.replaceWith(...wrapper.childNodes);
        });
        clone.querySelectorAll('.post-editor-body-paragraph').forEach((paragraph) => {
            paragraph.classList.remove('post-editor-body-paragraph');
            if (paragraph.getAttribute('class') === '') {
                paragraph.removeAttribute('class');
            }
        });
        clone.querySelectorAll('.has-leading-boundary-caret').forEach(clearBoundaryCaret);
        clearAiChangeMarks(clone);
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
        clone.querySelectorAll(
            '.is-editing-inline-caption, .is-inline-caption-entry',
        ).forEach((caption) => {
            const text = inlineMediaCaptionText(caption);
            if (text === '') {
                caption.remove();
                return;
            }
            clearInlineMediaCaptionAttributes(caption);
            caption.textContent = text;
        });
        clone.querySelectorAll('span[style]').forEach((span) => {
            const declarations = String(span.getAttribute('style') || '')
                .split(';')
                .map((declaration) => declaration.trim())
                .filter(Boolean);
            const browserDefaultsOnly = declarations.every((declaration) => {
                const separator = declaration.indexOf(':');
                const property = declaration.slice(0, separator).trim().toLowerCase();
                const value = declaration.slice(separator + 1).trim().toLowerCase();
                return (
                    (property === 'color' && value === 'inherit')
                    || (property === 'font-size' && value === '1em')
                    || (property === 'text-wrap-mode' && value === 'initial')
                );
            });
            if (browserDefaultsOnly) {
                span.removeAttribute('style');
            }
            if (span.attributes.length === 0) span.replaceWith(...span.childNodes);
        });
        return clone.innerHTML;
    }

    function restoreHeadingLink(state) {
        if (!state.titleLink || !state.titleLinkHadHref) {
            return;
        }
        state.titleLink.setAttribute('href', state.titleLinkHref);
    }

    function editorHasUnsavedChanges(state) {
        return state.titleDirty
            || state.bodyDirty
            || state.tagsDirty
            || state.dateDirty
            || state.mediaUploads.size > 0
            || state.uploadedMediaIds.size > 0
            || (state.title.textContent || '') !== state.originalTitleText
            || editableBodyHtml(state) !== state.originalEditableBodyHtml
            || state.tagEditor.hasChanges()
            || (state.slugField && state.slugField.value !== state.originalSlug)
            || state.dateInput.value !== state.originalDateInputValue;
    }

    function closeDiscardChangesDialog(state, restoreFocus) {
        const confirmation = state.discardConfirmation;
        if (!confirmation) {
            return;
        }

        state.discardConfirmation = null;
        confirmation.controller.abort();
        confirmation.backdrop.remove();
        if (restoreFocus && confirmation.restoreTarget?.isConnected) {
            confirmation.restoreTarget.focus({preventScroll: true});
        }
    }

    function requestCloseEditor(card, restoreFocus) {
        const state = editorStates.get(card);
        if (!state || state.submitting) {
            return;
        }
        if (!editorHasUnsavedChanges(state)) {
            closeEditor(card, restoreFocus, true);
            return;
        }
        if (state.discardConfirmation) {
            state.discardConfirmation.continueButton.focus({preventScroll: true});
            return;
        }

        const template = editorTemplate('.post-discard-changes-template');
        const fragment = template instanceof HTMLTemplateElement
            ? template.content.cloneNode(true)
            : null;
        const backdrop = fragment?.querySelector('.post-discard-changes-backdrop');
        const dialog = fragment?.querySelector('.post-discard-changes-dialog');
        const continueButton = fragment?.querySelector('[data-discard-changes-action="continue"]');
        if (
            !(backdrop instanceof HTMLElement)
            || !(dialog instanceof HTMLElement)
            || !(continueButton instanceof HTMLButtonElement)
        ) {
            const warning = editorConfig().discardChangesWarning || 'Discard unsaved changes?';
            if (window.confirm(warning)) {
                closeEditor(card, restoreFocus, true);
            }
            return;
        }

        const controller = new AbortController();
        const restoreTarget = document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;
        const keepEditing = () => closeDiscardChangesDialog(state, true);
        const discard = () => {
            closeDiscardChangesDialog(state, false);
            closeEditor(card, restoreFocus, true);
        };
        state.discardConfirmation = {
            backdrop,
            dialog,
            continueButton,
            controller,
            restoreTarget,
        };
        document.body.append(fragment);
        backdrop.addEventListener('click', (event) => {
            const button = event.target instanceof Element
                ? event.target.closest('[data-discard-changes-action]')
                : null;
            if (button instanceof HTMLButtonElement) {
                if (button.dataset.discardChangesAction === 'discard') {
                    discard();
                } else {
                    keepEditing();
                }
                return;
            }
            if (event.target === backdrop) {
                keepEditing();
            }
        }, {signal: controller.signal});
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            keepEditing();
        }, {capture: true, signal: controller.signal});
        continueButton.focus({preventScroll: true});
    }

    function stopEditing(state) {
        if (pendingMediaClipboard?.state === state) pendingMediaClipboard = null;
        if (activeMediaDrag?.state === state) activeMediaDrag = null;
        state.recovery?.stop();
        state.history?.destroy();
        state.titleHistory?.destroy();
        closeDiscardChangesDialog(state, false);
        closeContextMenu(state, false);
        state.imageCaptionEditor?.controller.abort();
        state.imageCaptionEditor?.history?.destroy();
        state.imageCaptionEditor = null;
        state.mediaCaptionEditors.forEach((editor) => {
            editor.controller.abort();
            editor.history?.destroy();
        });
        state.mediaCaptionEditors.clear();
        state.aiController?.abort();
        state.aiController = null;
        state.aiAltControllers.forEach((controller) => controller.abort());
        state.aiAltControllers.clear();
        state.mediaControllers.forEach((controller) => controller.abort());
        state.mediaControllers.clear();
        state.body.classList.remove('is-media-dragover');
        clearBoundaryCaret(state.body);
        clearSyntheticBoundaryCaret(state.body);
        state.body.querySelectorAll('.has-leading-boundary-caret').forEach(clearBoundaryCaret);
        clearAiChangeMarks(state.body);
        state.dateInput.hidden = true;
        state.dateButton.hidden = true;
        unsetEditable(state.title);
        unsetEditable(state.body);
        restoreEditableBodyStyles(state);
        restoreHeadingLink(state);
        state.footSpacer.remove();
        state.fieldSurfaces?.destroy();
        state.card.classList.remove('is-editing');
        state.card.removeAttribute('aria-busy');
        clearStatus(state.card);
        clearError(state.form);
        toggleEditingTools(state.card, false);
        editorStates.delete(state.card);
    }

    function editorElements(card) {
        const form = card.querySelector(':scope > .post-inplace-edit-form');
        const title = card.querySelector(':scope > .post.head [data-post-inplace-title]');
        const body = card.querySelector(':scope > .post.body[data-post-inplace-body]');
        const tags = card.querySelector(':scope > .post.foot [data-post-inplace-tags-values]');
        const tagsHost = tags?.closest('.post-foot-tags');
        const foot = tags?.closest('.post.foot');
        const titleField = form?.elements.namedItem('title');
        const bodyField = form?.elements.namedItem('body');
        const tagsField = form?.elements.namedItem('tags');
        const publishedAtField = form?.elements.namedItem('published_at');
        const slugField = form?.elements.namedItem('slug');
        const uploadedMediaField = form?.elements.namedItem('uploaded_media_ids');
        const dateInput = card.querySelector(':scope > .post.time > .post-inplace-datetime');
        const dateButton = card.querySelector(':scope > .post.time > .post-inplace-date-button');
        const time = card.querySelector(':scope > .post.time > time');
        if (
            !(form instanceof HTMLFormElement)
            || !(title instanceof HTMLElement)
            || !(body instanceof HTMLElement)
            || !(tags instanceof HTMLElement)
            || !(tagsHost instanceof HTMLElement)
            || !(foot instanceof HTMLElement)
            || !(titleField instanceof HTMLInputElement)
            || !(bodyField instanceof HTMLTextAreaElement)
            || !(tagsField instanceof HTMLInputElement)
            || !(publishedAtField instanceof HTMLInputElement)
            || !(uploadedMediaField instanceof HTMLInputElement)
            || !(dateInput instanceof HTMLInputElement)
            || !(dateButton instanceof HTMLButtonElement)
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
            foot,
            titleField,
            bodyField,
            tagsField,
            publishedAtField,
            slugField,
            uploadedMediaField,
            dateInput,
            dateButton,
            time,
        };
    }

    function closeEditor(card, restoreFocus, discard = false) {
        const state = editorStates.get(card);
        if (!state) {
            return true;
        }
        // Switching editors must retain the latest text, not merely an older
        // copy from before storage became unavailable or the draft grew too big.
        if (!discard && editorHasUnsavedChanges(state) && !state.recovery?.persist()) {
            showEditorStatus(state, editorConfig().recoveryUnavailable || 'Unable to save a local copy. Keep this tab open.', true);
            return false;
        }

        const creating = state.creating;
        if (state.recovery?.stop(discard)) {
            // Uploaded files referenced by a saved local copy must survive reload
            // or switching editors. The server still bounds pending-media lifetime.
            state.uploadedMediaIds.clear();
        }
        releasePendingMedia(state);
        state.title.innerHTML = state.originalTitleHtml;
        state.body.innerHTML = state.originalBody;
        restoreTypographicNoBreaks(state.body, state.originalNoBreaks, false);
        state.tags.innerHTML = state.originalTagsHtml;
        state.tagsHost.classList.toggle('is-empty', state.originalTags === '');
        state.titleField.value = state.originalTitle;
        state.bodyField.value = state.originalBody;
        state.tagsField.value = state.originalTags;
        state.publishedAtField.value = String(state.originalPublishedAt);
        if (state.slugField) state.slugField.value = state.originalSlug;
        state.time.dateTime = state.originalTimeDateTime;
        state.time.textContent = state.originalTimeText;
        stopEditing(state);
        state.form.reset();
        clearError(state.form);
        enhanceWidgets(state.body);
        unlock();

        refreshPostRecoveryOffers();

        if (creating) {
            card.remove();
            if (restoreFocus) {
                document.querySelector('.post-create-start')?.focus();
            }
            return true;
        }

        if (restoreFocus) {
            postToolsFocusTarget(card, '.post-edit-start')?.focus();
        }
        return true;
    }

    function closeConfirmation(card, restoreFocus) {
        const confirmation = card.querySelector(':scope > .post-delete-confirmation');
        if (!confirmation) {
            return;
        }
        if (confirmation instanceof HTMLDialogElement && confirmation.open) {
            confirmation.close();
        }
        confirmation.hidden = true;
        card.classList.remove('is-confirming');
        clearError(confirmation);
        if (restoreFocus) {
            postToolsFocusTarget(card, '.post-delete-start')?.focus();
        }
        unlock();
    }

    function closeOtherCards(activeCard) {
        if (Array.from(document.querySelectorAll('.post-card.is-editing')).some((card) => (
            card !== activeCard && editorStates.get(card)?.submitting
        ))) {
            return false;
        }
        for (const card of document.querySelectorAll('.post-card.is-editing, .post-card.is-confirming')) {
            if (card === activeCard) {
                continue;
            }
            if (!closeEditor(card, false)) return false;
            closeConfirmation(card, false);
        }
        return true;
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

        if (!closeOtherCards(card)) {
            return false;
        }
        closeConfirmation(card, false);
        clearError(elements.form);
        clearStatus(card);

        const titleLink = elements.title.closest('a');
        const state = {
            ...elements,
            card,
            originalTitle: elements.titleField.value,
            originalTitleHtml: elements.title.innerHTML,
            originalTitleText: elements.title.textContent || '',
            originalBody: elements.bodyField.value,
            originalNoBreaks: new Set(Array.from(elements.body.querySelectorAll('nobr'), (word) => word.textContent)),
            originalTags: elements.tagsField.value,
            originalTagsHtml: elements.tags.innerHTML,
            originalPublishedAt: Number(elements.publishedAtField.value),
            originalSlug: elements.slugField?.value || '',
            originalDateInputValue: '',
            originalEditableBodyHtml: '',
            originalTimeDateTime: elements.time.dateTime,
            originalTimeText: elements.time.textContent || '',
            titleDirty: false,
            bodyDirty: false,
            tagsDirty: false,
            dateDirty: false,
            creating: card.hasAttribute('data-post-creating'),
            requestId: card.hasAttribute('data-post-creating') ? window.RegisterEditorStorage.recordId() : null,
            mediaUploads: new Set(),
            mediaControllers: new Set(),
            imageUploadTail: Promise.resolve(),
            uploadedMediaIds: new Set(),
            recoveryMediaIds: new Set(),
            mediaCaptionEditors: new Map(),
            contextMenu: null,
            imageCaptionEditor: null,
            aiController: null,
            aiAltControllers: new Set(),
            aiAltTasks: new Set(),
            aiAltImages: new WeakMap(),
            aiAltFailures: new WeakSet(),
            aiAltTail: Promise.resolve(),
            aiAltPending: 0,
            aiAltStatusPending: 0,
            discardConfirmation: null,
            submitting: false,
            titleLink,
            titleLinkHadHref: titleLink?.hasAttribute('href') || false,
            titleLinkHref: titleLink?.getAttribute('href') || '',
            footSpacer: createFootHeightSpacer(elements.foot),
            detachedBodyStyles: detachEditableBodyStyles(elements.body),
        };

        destroyWidgets(elements.body);
        elements.body.innerHTML = state.originalBody;
        restoreTypographicNoBreaks(elements.body, state.originalNoBreaks);
        prepareEditableMedia(elements.body);
        state.originalEditableBodyHtml = editableBodyHtml(state);
        elements.tags.replaceChildren();
        if (state.titleLinkHadHref) {
            titleLink.removeAttribute('href');
        }

        setEditable(elements.title, editorConfig().titleLabel || 'Title', false);
        setEditable(elements.body, editorConfig().bodyLabel || 'Post text', true);
        elements.title.dataset.placeholder = editorConfig().titlePlaceholder || '';
        elements.dateInput.value = localDateTimeValue(state.originalPublishedAt);
        state.originalDateInputValue = elements.dateInput.value;
        elements.dateInput.hidden = false;
        elements.dateButton.hidden = false;
        state.tagEditor = createTagEditor(state);
        editorStates.set(card, state);
        if (state.creating) {
            let operation = state.form.elements.namedItem('request_id');
            if (!operation) {
                operation = document.createElement('input');
                operation.type = 'hidden';
                operation.name = 'request_id';
                state.form.append(operation);
            }
            operation.value = state.requestId;
        }
        if (!state.creating) {
            elements.foot.append(state.footSpacer);
        }
        card.classList.add('is-editing');
        state.fieldSurfaces = createEditorFieldSurfaces(state);
        applyShortcutHints(card);
        toggleEditingTools(card, true);
        document.execCommand('defaultParagraphSeparator', false, 'p');
        state.history = createBodyHistory(state);
        state.titleHistory = createFieldHistory(state, elements.title, new AbortController(), () => {
            state.titleDirty = true;
        });
        startPostRecovery(state);
        focusEdge(elements.title, true);
        queueMicrotask(() => {
            if (editorStates.get(card) === state) {
                generateMissingImageAlts(state);
            }
        });

        return true;
    }

    function beginCreate(button) {
        const slot = button.closest('[data-post-create-slot]')
            || document.querySelector('[data-post-create-slot]');
        const existing = document.querySelector('.post-card[data-post-creating]');
        if (existing instanceof HTMLElement) {
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
        const host = document.querySelector('.live-post-feed')
            || document.querySelector('.tag-post-list')
            || document.querySelector('#content');
        if (!(host instanceof HTMLElement)) {
            return false;
        }

        const publishedAt = Math.floor(Date.now() / 1000);
        const publishedAtField = card.querySelector(
            ':scope > .post-inplace-edit-form input[name="published_at"]',
        );
        const time = card.querySelector(':scope > .post.time > time');
        if (!(publishedAtField instanceof HTMLInputElement) || !(time instanceof HTMLTimeElement)) {
            return false;
        }
        const publicationDate = new Date(publishedAt * 1000);
        publishedAtField.value = String(publishedAt);
        publishedAtField.defaultValue = publishedAtField.value;
        time.dateTime = publicationDate.toISOString();
        time.textContent = dateTimeText(
            publicationDate,
            time.dataset.locale || document.documentElement.lang,
        );
        time.dataset.localTimeReady = '1';

        host.prepend(fragment);
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

        if (editorStates.get(card)?.submitting || !closeOtherCards(card)) {
            return;
        }
        if (!closeEditor(card, false)) return;
        closePostToolsMenu(card.querySelector(':scope > .post-inplace-tools'), false);
        card.classList.add('is-confirming');
        confirmation.hidden = false;
        if (confirmation instanceof HTMLDialogElement && !confirmation.open) {
            confirmation.showModal();
        }
        clearError(confirmation);
        clearStatus(card);
        confirmation.querySelector('.post-delete-cancel')?.focus();
    }

    function syncEditor(state) {
        finishImageCaptionEditing(state, true, false);
        finishInlineMediaCaptions(state);
        closeContextMenu(state, false);
        if (normalizeMediaBodyStructure(state.body)) {
            state.bodyDirty = true;
        }
        const titleSource = state.titleDirty ? (state.title.textContent || '') : state.originalTitle;
        const title = titleSource
            .replace(/\u00a0/gu, ' ')
            .replace(/\s+/gu, ' ')
            .trim();
        if (title === '' || Array.from(title).length > 255) {
            showError(state.form, editorConfig().invalidContent || editorConfig().editError || 'Invalid post content.');
            focusEdge(state.title, true);
            return false;
        }

        state.titleHistory?.before();
        state.title.textContent = title;
        state.titleHistory?.record();
        state.titleField.value = title;
        state.bodyField.value = state.bodyDirty ? editableBodyHtml(state) : state.originalBody;

        const publishedAt = editorPublishedAt(state);
        if (!Number.isInteger(publishedAt) || publishedAt < 1 || publishedAt > 4102444799) {
            showError(state.form, editorConfig().invalidContent || editorConfig().editError || 'Invalid post content.');
            state.dateInput.focus();
            return false;
        }
        state.publishedAtField.value = String(publishedAt);

        const tags = state.tagEditor.sync();
        if (tags === null) {
            showError(state.form, editorConfig().invalidTags || editorConfig().editError || 'Invalid post tags.');
            state.tagEditor.focus();
            return false;
        }
        const tagValue = tags.join(', ');
        state.tagsHost.classList.toggle('is-empty', tagValue === '');
        state.tagsField.value = tagValue;
        // The server uses this list to discard unused uploads on save. Used
        // media is registered from the body, including files shared by recovery.
        state.uploadedMediaField.value = releasableMediaIds(state).join(',');
        return true;
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

    function createMediaUploadPending(state, file, kind) {
        if (kind !== 'image') {
            const element = document.createElement('span');
            const message = mediaMessage(
                editorConfig().mediaUploading || 'Uploading “%s”…',
                file.name,
            );
            element.className = 'post-media-upload';
            element.contentEditable = 'false';
            element.dataset.mediaKind = kind;
            element.setAttribute('role', 'status');
            element.setAttribute('aria-label', message);
            element.textContent = message;
            return {
                element,
                image: null,
                progress: null,
                progressMessage: null,
                previewUrl: null,
            };
        }

        const previewUrl = URL.createObjectURL(file);
        const image = document.createElement('img');
        image.className = 'post-media-image';
        image.setAttribute('src', previewUrl);
        image.setAttribute('alt', '');
        image.setAttribute('decoding', 'async');

        const progressMessage = document.createElement('span');
        progressMessage.className = 'post-media-processing-message';
        const progress = document.createElement('span');
        progress.className = 'post-media-processing-progress';
        progress.setAttribute('role', 'status');
        progress.setAttribute('aria-live', 'polite');
        progress.append(progressMessage);

        const element = document.createElement('div');
        element.className = 'post-picture post-media-picture is-processing';
        element.contentEditable = 'false';
        element.dataset.mediaKind = kind;
        element.append(image, progress);

        const pending = {
            element,
            image,
            progress,
            progressMessage,
            previewUrl,
        };
        updateMediaUploadPending(
            pending,
            mediaMessage(editorConfig().mediaQueued || 'Queued: “%s”', file.name),
            'queued',
        );
        return pending;
    }

    function updateMediaUploadPending(pending, message, stage) {
        pending.element.dataset.mediaStage = stage;
        if (pending.progress instanceof HTMLElement && pending.progressMessage instanceof HTMLElement) {
            pending.progressMessage.textContent = message;
            pending.progress.setAttribute('aria-label', message);
            return;
        }
        pending.element.textContent = message;
        pending.element.setAttribute('aria-label', message);
    }

    function releaseMediaUploadPreview(pending) {
        if (typeof pending.previewUrl !== 'string' || pending.previewUrl === '') {
            return;
        }
        URL.revokeObjectURL(pending.previewUrl);
        pending.previewUrl = null;
    }

    async function revealProcessedImage(pending) {
        if (pending.progress instanceof HTMLElement) {
            pending.progress.classList.add('is-finishing');
            const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
            await new Promise((resolve) => window.setTimeout(resolve, reduceMotion ? 0 : 180));
            pending.progress.remove();
        }
        pending.element.classList.remove('is-processing');
        pending.element.removeAttribute('contenteditable');
        pending.element.removeAttribute('data-media-kind');
        pending.element.removeAttribute('data-media-stage');
    }

    function applyImageMediaPayload(pending, payload) {
        const image = pending.image;
        if (!(image instanceof HTMLImageElement)) {
            throw new Error('The image preview is unavailable.');
        }
        image.dataset.postMediaId = String(payload.media_id);
        if (Number.isInteger(payload.width) && payload.width > 0) {
            image.setAttribute('width', String(payload.width));
        }
        if (Number.isInteger(payload.height) && payload.height > 0) {
            image.setAttribute('height', String(payload.height));
        }
        return image;
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

    function createAudioMediaElement(payload, file) {
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
        return audio;
    }

    function inlineMediaCaptionText(caption) {
        const text = typeof caption.innerText === 'string' ? caption.innerText : caption.textContent;
        return String(text || '').replace(/\r\n?/gu, '\n').trim();
    }

    function inlineMediaCaptionPlaceholder() {
        return editorConfig().mediaCaptionPlaceholder || 'Add a caption…';
    }

    function clearInlineMediaCaptionAttributes(caption) {
        caption.classList.remove('is-editing-inline-caption', 'is-inline-caption-entry');
        if (caption.getAttribute('class') === '') {
            caption.removeAttribute('class');
        }
        caption.removeAttribute('contenteditable');
        caption.removeAttribute('role');
        caption.removeAttribute('aria-label');
        caption.removeAttribute('aria-multiline');
        caption.removeAttribute('spellcheck');
        caption.removeAttribute('data-placeholder');
        caption.removeAttribute('tabindex');
    }

    function configureInlineMediaCaptionEntry(caption, placeholder) {
        clearInlineMediaCaptionAttributes(caption);
        caption.classList.add('is-inline-caption-entry');
        caption.setAttribute('contenteditable', 'false');
        caption.setAttribute('role', 'button');
        caption.setAttribute('aria-label', placeholder);
        caption.setAttribute('tabindex', '0');
        caption.dataset.placeholder = placeholder;
    }

    function prepareInlineMediaCaptionEntries(root) {
        const placeholder = inlineMediaCaptionPlaceholder();
        root.querySelectorAll('.post-media-picture').forEach((picture) => {
            if (!picture.querySelector('img') || picture.classList.contains('is-processing')) {
                return;
            }
            let caption = picture.querySelector(
                ':scope > .post-caption:not(.post-media-overlay-caption), '
                + ':scope > figcaption:not(.post-media-overlay-caption)',
            );
            if (!(caption instanceof HTMLElement)) {
                caption = document.createElement('div');
                caption.className = 'post-caption';
                picture.append(caption);
            }
            if (!caption.classList.contains('is-editing-inline-caption')) {
                configureInlineMediaCaptionEntry(caption, placeholder);
            }
        });
    }

    function finishInlineMediaCaption(state, caption, restoreFocus = false) {
        const editor = state.mediaCaptionEditors.get(caption);
        if (!editor || !(editor.controller instanceof AbortController)) {
            return;
        }
        state.mediaCaptionEditors.delete(caption);
        editor.controller.abort();
        editor.history?.destroy();
        const selection = window.getSelection();
        const selectionWasInside = Boolean(selection?.anchorNode && caption.contains(selection.anchorNode));
        if (document.activeElement === caption) {
            caption.blur();
        }
        if (selectionWasInside) {
            selection?.removeAllRanges();
        }

        const text = inlineMediaCaptionText(caption);
        caption.textContent = text;
        configureInlineMediaCaptionEntry(
            caption,
            editorConfig().mediaCaptionPlaceholder || 'Add a caption…',
        );
        if (editor.bodyContentEditable === null) {
            state.body.removeAttribute('contenteditable');
        } else {
            state.body.setAttribute('contenteditable', editor.bodyContentEditable);
        }
        if (text !== editor.original) {
            markBodyChanged(state);
        }
        // History was suspended even for an unchanged caption. Retain async
        // changes that arrived during the session before the next undo.
        state.history?.record();

        if (restoreFocus) {
            const media = caption.closest('.post-media-picture');
            if (!(media instanceof HTMLElement) || !focusAfterMedia(state.body, media)) {
                focusEdge(state.body, true);
            }
        }
    }

    function finishInlineMediaCaptions(state) {
        Array.from(state.mediaCaptionEditors.keys()).forEach((caption) => {
            finishInlineMediaCaption(state, caption, false);
        });
    }

    function beginInlineMediaCaption(state, caption) {
        if (state.mediaCaptionEditors.has(caption)) {
            focusInlineMediaCaption(state, caption);
            return;
        }
        finishInlineMediaCaptions(state);
        finishImageCaptionEditing(state, true, false);
        state.history?.before();
        const controller = new AbortController();
        const placeholder = editorConfig().mediaCaptionPlaceholder || 'Add a caption…';
        const original = inlineMediaCaptionText(caption);
        const editor = {
            controller,
            original,
            bodyContentEditable: state.body.getAttribute('contenteditable'),
        };
        state.mediaCaptionEditors.set(caption, editor);
        // Give Select All its own editing host. Native undo can still cross host
        // boundaries in Firefox, so keep a separate bounded caption history too.
        state.body.setAttribute('contenteditable', 'false');
        clearInlineMediaCaptionAttributes(caption);
        caption.classList.add('is-editing-inline-caption');
        caption.setAttribute('contenteditable', 'true');
        caption.setAttribute('role', 'textbox');
        caption.setAttribute('aria-label', placeholder);
        caption.setAttribute('aria-multiline', 'true');
        caption.setAttribute('spellcheck', 'true');
        caption.setAttribute('tabindex', '0');
        caption.dataset.placeholder = placeholder;
        editor.history = createFieldHistory(state, caption, controller);
        const selectionIsInside = () => {
            const selection = window.getSelection();
            const range = selection?.rangeCount === 1 ? selection.getRangeAt(0) : null;
            return Boolean(
                range
                && caption.contains(range.startContainer)
                && caption.contains(range.endContainer)
            );
        };
        const keydown = (event) => {
            if (!selectionIsInside()) {
                return;
            }
            if (moveFromInlineMediaCaption(event, state, caption)) {
                return;
            }
            if (
                event.key === 'Escape'
                || (event.key === 'Enter' && !event.shiftKey && !event.isComposing)
            ) {
                event.preventDefault();
                event.stopPropagation();
                finishInlineMediaCaption(state, caption, true);
                return;
            }
            if (event.key === 'Enter' && event.shiftKey && !event.isComposing) {
                event.preventDefault();
                event.stopPropagation();
                document.execCommand('insertLineBreak');
            }
        };
        const paste = (event) => {
            if (!selectionIsInside()) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') || '');
        };
        caption.addEventListener('keydown', keydown, {signal: controller.signal});
        caption.addEventListener('paste', paste, {signal: controller.signal});
        caption.addEventListener('blur', () => {
            window.setTimeout(() => {
                if (state.mediaCaptionEditors.has(caption) && document.activeElement !== caption) {
                    finishInlineMediaCaption(state, caption, false);
                }
            }, 0);
        }, {signal: controller.signal});
        // Retain selection-based routing for browsers that deliver caption keys
        // or clipboard events through the outer body.
        state.body.addEventListener('keydown', keydown, {signal: controller.signal});
        state.body.addEventListener('paste', paste, {signal: controller.signal});
    }

    function selectionStartsAt(element) {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount !== 1) {
            return false;
        }
        const range = selection.getRangeAt(0);
        if (!range.collapsed || !element.contains(range.startContainer)) {
            return false;
        }
        if (inlineMediaCaptionText(element) === '') {
            return true;
        }

        const prefix = document.createRange();
        prefix.selectNodeContents(element);
        prefix.setEnd(range.startContainer, range.startOffset);
        return prefix.toString() === '';
    }

    function selectionEndsAt(element) {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount !== 1) {
            return false;
        }
        const range = selection.getRangeAt(0);
        if (!range.collapsed || !element.contains(range.endContainer)) {
            return false;
        }
        if (inlineMediaCaptionText(element) === '') {
            return true;
        }

        const suffix = document.createRange();
        suffix.selectNodeContents(element);
        suffix.setStart(range.endContainer, range.endOffset);
        return suffix.toString() === '';
    }

    function moveFromLeadingMediaCaption(event, state, caption) {
        if (
            (event.key !== 'ArrowUp' && event.key !== 'ArrowLeft')
            || event.altKey
            || event.ctrlKey
            || event.metaKey
            || event.shiftKey
            || event.isComposing
            || !selectionStartsAt(caption)
        ) {
            return false;
        }

        const media = caption.closest('.post-media-picture');
        if (!(media instanceof HTMLElement) || leadingMediaIndex(state.body, media) < 0) {
            return false;
        }

        event.preventDefault();
        event.stopPropagation();
        finishInlineMediaCaption(state, caption, false);
        focusBeforeLeadingMedia(state.body, media);
        return true;
    }

    function moveFromInlineMediaCaption(event, state, caption) {
        if (moveFromLeadingMediaCaption(event, state, caption)) {
            return true;
        }
        if (
            (event.key !== 'ArrowDown' && event.key !== 'ArrowRight')
            || event.altKey
            || event.ctrlKey
            || event.metaKey
            || event.shiftKey
            || event.isComposing
            || !selectionEndsAt(caption)
        ) {
            return false;
        }

        const media = caption.closest('.post-media-picture');
        if (!(media instanceof HTMLElement) || media.parentElement !== state.body) {
            return false;
        }

        event.preventDefault();
        event.stopPropagation();
        finishInlineMediaCaption(state, caption, false);
        focusAfterMedia(state.body, media);
        return true;
    }

    function moveFromBodyMediaBoundary(event, state) {
        if (
            (event.key !== 'ArrowDown' && event.key !== 'ArrowRight')
            || event.altKey
            || event.ctrlKey
            || event.metaKey
            || event.shiftKey
            || event.isComposing
            || event.target !== state.body
        ) {
            return false;
        }

        const selection = window.getSelection();
        if (!selection || selection.rangeCount !== 1) {
            return false;
        }
        const media = mediaBoundaryAtRange(state.body, selection.getRangeAt(0));
        if (!(media instanceof HTMLElement)) {
            return false;
        }

        event.preventDefault();
        event.stopPropagation();
        if (event.key === 'ArrowDown') {
            focusAfterMedia(state.body, media);
            return true;
        }
        const caption = media.querySelector(
            ':scope > .post-caption:not(.post-media-overlay-caption), '
            + ':scope > figcaption:not(.post-media-overlay-caption)',
        );
        if (caption instanceof HTMLElement) {
            beginInlineMediaCaption(state, caption);
            focusInlineMediaCaption(state, caption);
        } else {
            focusAfterMedia(state.body, media);
        }
        return true;
    }

    function keepCaretInTrailingEmptyParagraph(event, state) {
        if (
            event.key !== 'ArrowDown'
            || event.altKey
            || event.ctrlKey
            || event.metaKey
            || event.shiftKey
            || event.isComposing
            || event.target !== state.body
        ) {
            return false;
        }

        const selection = window.getSelection();
        const range = selection?.rangeCount === 1 ? selection.getRangeAt(0) : null;
        if (!range?.collapsed) {
            return false;
        }
        const paragraph = topLevelBodyChild(state.body, range.startContainer);
        if (
            !editorBoundaryParagraphIsEmpty(paragraph)
            || Array.from(state.body.childNodes)
                .slice(Array.from(state.body.childNodes).indexOf(paragraph) + 1)
                .some((node) => !boundaryNodeIsEmpty(node))
        ) {
            return false;
        }

        // Opera/Chromium can move the selection out of the editing host when
        // ArrowDown is pressed on its last empty line. Keep that final body line
        // active so the next typed character cannot disappear into the page.
        event.preventDefault();
        event.stopPropagation();
        state.body.focus({preventScroll: true});
        const caret = document.createRange();
        caret.selectNodeContents(paragraph);
        caret.collapse(true);
        selection.removeAllRanges();
        selection.addRange(caret);
        syncBoundaryCaret();
        return true;
    }

    function moveFromMediaSideClick(event, state) {
        if (
            event.button !== 0
            || event.altKey
            || event.ctrlKey
            || event.metaKey
            || event.shiftKey
            || event.target !== state.body
        ) {
            return false;
        }

        const media = Array.from(state.body.childNodes).find((node) => {
            if (!isMediaBoundaryElement(state.body, node)) {
                return false;
            }
            const bounds = node.getBoundingClientRect();
            return event.clientY >= bounds.top && event.clientY <= bounds.bottom;
        });
        if (!(media instanceof HTMLElement)) {
            return false;
        }

        // A click beside a block image has no meaningful horizontal text
        // position. Chromium-based browsers can nevertheless paint a native
        // caret at the image's top-right DOM boundary. Treat the surrounding
        // whitespace as the ordinary line after the image instead. This runs
        // on pointerdown so the browser cannot overwrite the corrected range
        // with its own boundary selection before the subsequent click.
        event.preventDefault();
        focusAfterMedia(state.body, media);
        return true;
    }

    function focusInlineMediaCaption(state, caption) {
        clearBoundaryCaret(state.body);
        clearSyntheticBoundaryCaret(state.body);
        state.body.querySelectorAll('.has-leading-boundary-caret').forEach(clearBoundaryCaret);
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
    }

    function startMediaUpload(state, file, kind, pending) {
        const token = state.form.elements.namedItem('inplace_token');
        const controller = new AbortController();
        const formData = new FormData();
        formData.append('inplace_action', 'media');
        formData.append('inplace_token', token instanceof HTMLInputElement ? token.value : '');
        state.mediaControllers.add(controller);

        const run = async () => {
            let completed = null;
            try {
                if (controller.signal.aborted) {
                    throw new DOMException('Media upload was cancelled.', 'AbortError');
                }
                let uploadFile = file;
                let uploadName = file.name;
                if (kind === 'image') {
                    const optimizingMessage = mediaMessage(
                        editorConfig().mediaOptimizing || 'Optimizing “%s”…',
                        file.name,
                    );
                    updateMediaUploadPending(pending, optimizingMessage, 'optimizing');
                    const optimizer = await loadImageOptimizer();
                    const optimized = await optimizer.optimizeImage(file, {
                        signal: controller.signal,
                    });
                    uploadFile = optimized.blob;
                    uploadName = `image${optimized.retina ? '@2x' : ''}.${optimized.extension}`;
                    formData.append('media_retina', optimized.retina ? '1' : '0');
                    formData.append('media_width', String(optimized.width));
                    formData.append('media_height', String(optimized.height));
                    formData.append('media_display_width', String(optimized.displayWidth));
                    formData.append('media_display_height', String(optimized.displayHeight));
                }

                const publishedAt = editorPublishedAt(state);
                if (!Number.isInteger(publishedAt) || publishedAt < 1 || publishedAt > 4102444799) {
                    throw new Error(editorConfig().invalidContent || 'Invalid post content.');
                }
                const uploadingMessage = mediaMessage(
                    editorConfig().mediaUploading || 'Uploading “%s”…',
                    file.name,
                );
                updateMediaUploadPending(pending, uploadingMessage, 'uploading');
                formData.append('published_at', String(publishedAt));
                formData.append('media', uploadFile, uploadName);

                const {response, data: payload} = await window.RegisterEditorRequest.requestJson(state.form.action, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: controller.signal,
                }, {timeoutMs: editorConfig().saveTimeoutMs, timeoutMessage: editorConfig().saveTimeout});

                if (
                    !response.ok
                    || !payload
                    || payload.success !== true
                    || payload.action !== 'media'
                    || payload.kind !== kind
                    || !Number.isInteger(payload.media_id)
                    || payload.media_id <= 0
                    || typeof payload.url !== 'string'
                    || payload.url === ''
                    || (kind === 'image' && (
                        !Number.isInteger(payload.width)
                        || payload.width <= 0
                        || !Number.isInteger(payload.height)
                        || payload.height <= 0
                    ))
                ) {
                    throw new Error(
                        payload?.message
                        || mediaMessage(editorConfig().mediaUploadFailed || 'Unable to upload “%s”.', file.name),
                    );
                }
                if (editorStates.get(state.card) !== state) {
                    // A response can win the race with abort after cancelling an
                    // editor. The server-created file still needs to be released.
                    state.uploadedMediaIds.add(payload.media_id);
                    releasePendingMedia(state);
                    releaseMediaUploadPreview(pending);
                    return;
                }

                state.uploadedMediaIds.add(payload.media_id);
                state.bodyDirty = true;
                clearStatus(state.card);

                if (kind === 'image') {
                    const image = applyImageMediaPayload(pending, payload);
                    if (aiAltEnabled()) {
                        updateMediaUploadPending(
                            pending,
                            editorConfig().aiAltWorking || 'AI is creating alt text…',
                            'alt',
                        );
                        await queueImageAlt(state, image, uploadFile, false, false);
                    }
                    if (editorStates.get(state.card) !== state) {
                        releaseMediaUploadPreview(pending);
                        return;
                    }
                    image.setAttribute('src', payload.url);
                    image.setAttribute('loading', 'lazy');
                    releaseMediaUploadPreview(pending);
                    await revealProcessedImage(pending);

                    const caption = document.createElement('div');
                    caption.className = 'post-caption';
                    pending.element.append(caption);
                    configureInlineMediaCaptionEntry(
                        caption,
                        editorConfig().mediaCaptionPlaceholder || 'Add a caption…',
                    );
                    completed = pending.element;
                } else {
                    const audio = createAudioMediaElement(payload, file);
                    pending.element.replaceWith(audio);
                    completed = audio;
                }
            } catch (error) {
                releaseMediaUploadPreview(pending);
                pending.element.remove();
                if (error instanceof DOMException && error.name === 'AbortError') {
                    return;
                }
                const failure = error instanceof Error
                    ? error
                    : new Error(mediaMessage(editorConfig().mediaUploadFailed || 'Unable to upload “%s”.', file.name));
                showError(state.form, failure.message);
                // Keep idle uploads handled, but let a waiting save detect failure.
                return failure;
            } finally {
                pending.completed = completed;
                pending.settled = true;
                state.history?.resolveUpload(pending, completed);
                state.mediaControllers.delete(controller);
            }
        };

        const upload = kind === 'image'
            ? state.imageUploadTail.catch(() => {}).then(run)
            : run();
        if (kind === 'image') {
            state.imageUploadTail = upload;
        }

        state.mediaUploads.add(upload);
        upload.finally(() => state.mediaUploads.delete(upload));
    }

    async function redatePendingMedia(state) {
        if (state.uploadedMediaIds.size === 0) {
            return;
        }

        const publishedAt = editorPublishedAt(state);
        if (!Number.isInteger(publishedAt) || publishedAt < 1 || publishedAt > 4102444799) {
            throw new Error(editorConfig().invalidContent || 'Invalid post content.');
        }
        const token = state.form.elements.namedItem('inplace_token');
        const data = new FormData();
        data.set('inplace_action', 'media_redate');
        data.set('inplace_token', token instanceof HTMLInputElement ? token.value : '');
        data.set('media_ids', Array.from(state.uploadedMediaIds).join(','));
        data.set('published_at', String(publishedAt));
        const {response, data: payload} = await window.RegisterEditorRequest.requestJson(state.form.action, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        }, {timeoutMs: editorConfig().saveTimeoutMs, timeoutMessage: editorConfig().saveTimeout});

        if (!response.ok || payload?.success !== true || !Array.isArray(payload.media)) {
            throw new Error(payload?.message || editorConfig().mediaUploadFailed || 'Unable to name the image.');
        }

        const mediaById = new Map(payload.media.map((media) => [Number(media.media_id), media]));
        updateMediaUrls(state.body, mediaById);
        state.history?.redateMedia(mediaById);
    }

    function updateMediaUrls(root, mediaById) {
        const selector = '[data-post-media-id][src]';
        const elements = Array.from(root.querySelectorAll(selector));
        if (root instanceof Element && root.matches(selector)) elements.unshift(root);
        elements.forEach((element) => {
            const media = mediaById.get(Number(element.dataset.postMediaId));
            if (!media || typeof media.url !== 'string' || media.url === '') {
                return;
            }

            element.setAttribute('src', media.url);
            if (
                element instanceof HTMLAudioElement
                && typeof media.name === 'string'
                && media.name !== ''
            ) {
                element.dataset.title = media.name;
            }
        });
    }

    function insertMediaFiles(state, files, initialRange) {
        const mediaFiles = files.map(file => ({file, kind: mediaKindForFile(file)}));
        const unsupported = mediaFiles.filter(({kind}) => kind === null).map(({file}) => file.name);
        const supported = mediaFiles.filter(({kind}) => kind !== null);
        const reportUnsupported = () => {
            if (unsupported.length > 0) {
                showError(
                    state.form,
                    mediaMessage(
                        editorConfig().mediaUnsupported || '“%s” is not supported. Drop an image or audio file.',
                        unsupported.join(', '),
                    ),
                );
            }
        };
        // A rejected file must not split a paragraph, commit a caption or move
        // the selection. Mixed drops still insert all supported attachments.
        if (supported.length === 0) {
            reportUnsupported();
            return;
        }

        finishInlineMediaCaptions(state);
        finishImageCaptionEditing(state, true, false);
        state.history?.before();
        clearError(state.form);
        clearStatus(state.card);
        const range = prepareMediaInsertionRange(state.body, bodyRange(state, initialRange));
        let lastMedia = null;

        supported.forEach(({file, kind}) => {
            const pending = createMediaUploadPending(state, file, kind);
            state.history?.trackUpload(pending);
            range.insertNode(pending.element);
            range.setStartAfter(pending.element);
            range.collapse(true);
            lastMedia = pending.element;
            startMediaUpload(state, file, kind, pending);
        });

        if (!focusAfterMedia(state.body, lastMedia)) {
            // Use the insertion range when no media caret target is available.
            state.body.focus({preventScroll: true});
            const selection = window.getSelection();
            if (selection) {
                selection.removeAllRanges();
                selection.addRange(range);
                syncBoundaryCaret();
            }
        }

        reportUnsupported();
        state.history?.record();
    }

    function transferHasFiles(transfer) {
        return transfer instanceof DataTransfer
            && (
                Array.from(transfer.types || []).includes('Files')
                || Array.from(transfer.items || []).some((item) => item.kind === 'file')
            );
    }

    function isEditorMediaDrag(transfer) {
        return activeMediaDrag !== null || Array.from(transfer?.types || []).includes(mediaDragType);
    }

    function clearMediaDrag() {
        activeMediaDrag?.state.body.classList.remove('is-media-dragover');
        activeMediaDrag = null;
    }

    function moveDraggedMedia(state, media, range) {
        if (!state.body.contains(media) || media.contains(range.startContainer)) return;
        const move = () => {
            range = prepareMediaInsertionRange(state.body, range);
            // Move the live block, retaining captions, links and pending upload
            // or description callbacks. Native image drops omit the outer block.
            media.remove();
            range.insertNode(media);
            prepareEditableMedia(state.body);
            markBodyChanged(state);
            if (!focusAfterMedia(state.body, media)) {
                range.setStartAfter(media);
                range.collapse(true);
                selectRange(state, range);
            }
        };
        if (state.history) state.history.transact(move);
        else move();
    }

    function deleteMediaClipboardRange(body, range) {
        const first = topLevelBodyChild(body, range.startContainer);
        const last = topLevelBodyChild(body, range.endContainer);
        const empty = fragment => fragment.textContent.trim() === ''
            && !fragment.querySelector('img, video, audio, iframe, table, hr, [data-post-history-upload]');
        // Native Select All often starts/ends *inside* the outer paragraphs.
        // Cutting several blocks must not leave those fully selected shells.
        if (first !== last && !range.collapsed) {
            if (first) {
                const prefix = document.createRange();
                prefix.selectNodeContents(first);
                prefix.setEnd(range.startContainer, range.startOffset);
                if (empty(prefix.cloneContents())) range.setStartBefore(first);
            }
            if (last) {
                const suffix = document.createRange();
                suffix.selectNodeContents(last);
                suffix.setStart(range.endContainer, range.endOffset);
                if (empty(suffix.cloneContents())) range.setEndAfter(last);
            }
        }
        range.deleteContents();
    }

    function copyPendingMedia(event) {
        if (event.defaultPrevented || !event.clipboardData) return;
        pendingMediaClipboard = null;
        const state = bodyDropState(event.target);
        const selection = window.getSelection();
        if (!state?.history || selection?.rangeCount !== 1) return;
        const range = selection.getRangeAt(0);
        if (range.collapsed || !rangeIsInside(state.body, range)) return;
        const container = document.createElement('div');
        container.append(range.cloneContents());
        const uploads = state.history.clipboardUploads(container);
        if (uploads.size === 0) return;
        removeTrailingEditorArtifacts(container);

        // Native clipboard HTML can omit blob images and noneditable audio
        // placeholders. Keep references to the requests until this clipboard is
        // replaced or the editing session ends, including pastes after completion.
        const token = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`;
        const references = new Map();
        container.querySelectorAll('[data-post-history-upload]').forEach(node => {
            const pending = uploads.get(node.dataset.postHistoryUpload);
            if (!pending) return;
            const id = `${token}-${node.dataset.postHistoryUpload}`;
            const placeholder = document.createElement(node.tagName.toLowerCase());
            placeholder.dataset.postClipboardUpload = id;
            placeholder.textContent = node.textContent;
            node.replaceWith(placeholder);
            references.set(id, pending);
        });
        const html = container.innerHTML;
        event.clipboardData.setData('text/html', html);
        event.clipboardData.setData('text/plain', range.toString());
        event.preventDefault();
        pendingMediaClipboard = {state, html, root: container, uploads: references};
        if (event.type === 'cut') {
            state.history.transact(() => {
                deleteMediaClipboardRange(state.body, range);
                selectRange(state, range);
                markBodyChanged(state);
            });
        }
    }

    function pastePendingMedia(event) {
        if (event.defaultPrevented) return;
        const state = bodyDropState(event.target);
        const html = event.clipboardData?.getData('text/html') || '';
        if (!state || !/data-post-(?:clipboard|history)-upload/u.test(html)) return;
        const template = document.createElement('template');
        template.innerHTML = html;
        if (!template.content.querySelector('[data-post-clipboard-upload], [data-post-history-upload]')) return;
        event.preventDefault();
        const clipboard = pendingMediaClipboard;
        if (!clipboard || clipboard.state !== state || clipboard.html !== html
            || Array.from(clipboard.uploads.values()).some(pending => pending.settled && !pending.completed)) {
            showEditorStatus(state, editorConfig().mediaClipboardUnavailable
                || 'Copy the attachment again after it finishes uploading.', true);
            return;
        }
        // Only insert the HTML that this session put on the clipboard. Other
        // tabs and closed sessions cannot supply a live request for these slots.
        const completedImages = [];
        state.history.transact(() => {
            protectSelectedMediaBoundary(state.body);
            const selection = window.getSelection();
            let range = selection?.rangeCount === 1 ? selection.getRangeAt(0) : null;
            if (!range || !rangeIsInside(state.body, range)) return;
            // Native insertHTML can retain a selected noneditable audio slot or
            // manufacture nested paragraphs around upload placeholders. Insert
            // our own cached DOM at a block boundary instead of reparsing it.
            deleteMediaClipboardRange(state.body, range);
            range = prepareMediaInsertionRange(state.body, range);
            const fragment = document.createDocumentFragment();
            fragment.append(...clipboard.root.cloneNode(true).childNodes);
            let last = fragment.lastChild;
            range.insertNode(fragment);
            state.body.querySelectorAll('[data-post-clipboard-upload]').forEach(slot => {
                const pending = clipboard.uploads.get(slot.dataset.postClipboardUpload);
                if (!pending) return;
                let media;
                if (pending.completed) {
                    media = pending.completed.cloneNode(true);
                    completedImages.push(...media.querySelectorAll('img'));
                } else {
                    media = state.body.contains(pending.element)
                        ? state.history.cloneUpload(pending) : pending.element;
                }
                slot.replaceWith(media);
                if (slot === last) last = media;
            });
            prepareEditableMedia(state.body);
            markBodyChanged(state);
            if (!focusAfterMedia(state.body, last)) {
                if (last) range.setStartAfter(last);
                range.collapse(true);
                selectRange(state, range);
            }
        });
        // A cut image can finish uploading while absent from the body, where
        // automatic description generation correctly skips it until this paste.
        completedImages.forEach(image => queueImageAlt(state, image));
    }

    function clipboardMediaFiles(transfer) {
        const files = Array.from(transfer?.files || []);
        if (files.length === 0) {
            Array.from(transfer?.items || []).forEach((item) => {
                const file = item.kind === 'file' ? item.getAsFile() : null;
                if (file) {
                    files.push(file);
                }
            });
        }
        // Screenshots and copied images may have a MIME type but no filename.
        const extensions = {
            'image/avif': 'avif',
            'image/bmp': 'bmp',
            'image/gif': 'gif',
            'image/jpeg': 'jpg',
            'image/png': 'png',
            'image/webp': 'webp',
            'image/x-icon': 'ico',
            'image/vnd.microsoft.icon': 'ico',
        };
        return files.map((file, index) => {
            const extension = extensions[file.type.toLowerCase()];
            if (!extension || mediaKindForFile(file) === 'image') {
                return file;
            }
            return new File([file], `clipboard-image-${index + 1}.${extension}`, {
                type: file.type,
                lastModified: file.lastModified,
            });
        });
    }

    function pasteMediaFiles(event) {
        if (event.defaultPrevented) {
            return;
        }
        const state = bodyDropState(event.target);
        if (!state) {
            return;
        }
        const files = clipboardMediaFiles(event.clipboardData);
        if (files.length === 0) {
            return;
        }
        // Prevent the browser from inserting a second, unprocessed data: image.
        event.preventDefault();
        const selection = window.getSelection();
        const selectedRange = selection?.rangeCount === 1 ? selection.getRangeAt(0) : null;
        const range = selectedRange && rangeIsInside(state.body, selectedRange)
            ? selectedRange.cloneRange()
            : null;
        const insert = () => {
            if (range && files.some((file) => mediaKindForFile(file) !== null)) {
                range.deleteContents();
            }
            insertMediaFiles(state, files, range);
        };
        if (state.history) {
            state.history.transact(insert);
        } else {
            insert();
        }
    }

    function pasteMultilineText(event) {
        if (event.defaultPrevented) {
            return;
        }
        const state = bodyDropState(event.target);
        if (!state || typeof event.clipboardData?.getData !== 'function') {
            return;
        }
        const text = event.clipboardData.getData('text/plain');
        if (!/[\r\n]/u.test(text)) {
            return;
        }
        const selection = window.getSelection();
        const range = selection?.rangeCount === 1 ? selection.getRangeAt(0) : null;
        if (!range || !rangeIsInside(state.body, range)) {
            return;
        }
        const isCode = contextBlockStyle(state.body, range) === 'code';
        if (!isCode && event.clipboardData.getData('text/html').trim() !== '') {
            return;
        }

        // A blank line in prose separates paragraphs. Saving it as <br><br> keeps
        // the whole article in one giant paragraph; later block-media insertion
        // then creates invalid nested HTML. Keep single newlines as explicit soft
        // breaks, but turn blank-line-separated prose into semantic paragraphs.
        event.preventDefault();
        const normalized = text.replace(/\r\n?/gu, '\n');
        if (isCode) {
            runFormattingCommand(state, 'insertText', normalized);
            return;
        }
        const escape = (value) => value
            .replace(/&/gu, '&amp;')
            .replace(/</gu, '&lt;')
            .replace(/>/gu, '&gt;');
        const chunks = normalized.split(/\n(?:[\t ]*\n)+/u);
        const html = chunks.length === 1
            ? escape(normalized).replace(/\n/gu, '<br>')
            : chunks.map((chunk) => (
                `<p>${escape(chunk).replace(/\n/gu, '<br>') || '<br>'}</p>`
            )).join('');
        runFormattingCommand(state, 'insertHTML', html);
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

    function updateScheduledPreview(card, time, payload) {
        card.classList.toggle('is-scheduled-preview', payload.scheduled);
        let notice = card.querySelector(':scope > .post-scheduled-notice');
        if (!payload.scheduled) {
            notice?.remove();
            return;
        }

        if (!(notice instanceof HTMLElement)) {
            notice = document.createElement('p');
            notice.className = 'post-scheduled-notice';
            notice.setAttribute('role', 'status');
        }
        const firstPostElement = card.querySelector(':scope > .post.author, :scope > .post.head');
        if (firstPostElement) {
            firstPostElement.before(notice);
        } else {
            card.prepend(notice);
        }
        notice.textContent = payload.schedule_message;
    }

    function updateEditedCard(card, form, payload, forceLiveRefresh = false) {
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
            || typeof payload.scheduled !== 'boolean'
            || (payload.scheduled && typeof payload.schedule_message !== 'string')
            || !Array.isArray(payload.tags)
            || payload.tags.some((tag) => !tag || typeof tag.name !== 'string' || typeof tag.url !== 'string')
        ) {
            throw new Error(editorConfig().applyError || 'Unable to apply the updated post.');
        }

        if (state) {
            state.recovery?.stop(true);
            stopEditing(state);
        }
        title.textContent = payload.title;
        time.dateTime = payload.datetime;
        time.textContent = payload.time;
        delete time.dataset.localTimeReady;
        window.RegisterLocalTime?.enhance(time);
        dateInput.value = localDateTimeValue(payload.published_at);
        updateScheduledPreview(card, time, payload);

        const tagFragment = document.createDocumentFragment();
        payload.tags.forEach((tag) => {
            const link = document.createElement('a');
            link.className = 'post-tag-link';
            link.href = tag.url;
            link.textContent = tag.name;
            tagFragment.append(link);
        });
        tagValues.replaceChildren(tagFragment);
        tagsHost.classList.toggle('is-empty', payload.tags.length === 0);

        const confirmation = card.querySelector(':scope > .post-delete-confirmation');
        const warningTemplate = editorConfig().deleteWarning;
        if (confirmation && typeof warningTemplate === 'string') {
            const warning = warningTemplate.replace('%s', payload.title);
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
        const slugField = form.elements.namedItem('slug');
        if (slugField instanceof HTMLInputElement && typeof payload.slug === 'string') {
            slugField.value = payload.slug;
            slugField.defaultValue = payload.slug;
        }
        const permalink = title.closest('a');
        if (permalink && typeof payload.url === 'string') permalink.href = payload.url;
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
        // Editing an existing card is fully represented by the local DOM update,
        // so the matching live patch would only replace the same card again. A
        // creation also changes the feed itself (ordering, page size and
        // pagination), which the temporary editor card cannot reproduce. Let the
        // authoritative feed patch run after a create instead of acknowledging it
        // as already applied.
        const acknowledged = !forceLiveRefresh && acknowledgeLiveMutation(payload);
        unlock();
        if (!acknowledged) {
            refresh();
        }
        postToolsFocusTarget(card, '.post-edit-start')?.focus();
        if (payload.url_changed === true && typeof payload.url === 'string') {
            window.location.assign(payload.url);
        }
    }

    function updateCreatedCard(card, form, payload) {
        if (
            !Number.isInteger(payload.id)
            || payload.id <= 0
            || typeof payload.url !== 'string'
            || typeof payload.action_url !== 'string'
            || typeof payload.token !== 'string'
        ) {
            throw new Error(editorConfig().applyError || 'Unable to apply the created post.');
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
        updateEditedCard(card, form, payload, true);
        const titleLink = card.querySelector(':scope > .post.head > a');
        if (titleLink instanceof HTMLAnchorElement) {
            titleLink.href = payload.url;
        }
    }

    function removeDeletedCard(card, payload) {
        const acknowledged = acknowledgeLiveMutation(payload);
        const recoveryStore = postRecoveryStore();
        recoveryStore?.list(card.dataset.postId).forEach(record => recoveryStore.remove(record));
        closeConfirmation(card, false);
        destroyWidgets(card);
        const feed = card.closest('.live-post-feed');
        if (feed) {
            const focusTarget = postToolsFocusTarget(card.nextElementSibling, '.post-edit-start')
                || postToolsFocusTarget(card.previousElementSibling, '.post-edit-start')
                || feed;
            card.remove();
            if (!acknowledged) {
                refresh();
            }
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
            : (editorConfig().deletedMessage || 'Post deleted');
        if (typeof payload.redirect === 'string' && payload.redirect !== '') {
            const link = document.createElement('a');
            link.href = payload.redirect;
            link.textContent = editorConfig().listLabel || 'Back to posts';
            notice.append(' — ', link);
        }
        card.replaceWith(notice);
        document.querySelectorAll('.comments-section, .comment-form-block').forEach((section) => section.remove());
        if (!acknowledged) {
            refresh();
        }
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
            finishImageCaptionEditing(state, true, false);
            finishInlineMediaCaptions(state);
            closeContextMenu(state, false);
        }

        const focusBeforeSave = document.activeElement;
        const buttons = card.querySelectorAll('.post-inplace-tools button, .post-delete-confirmation button');
        let lockedFields = null;
        const lockEditing = (locked) => {
            if (!state || locked === (lockedFields !== null)) return;
            if (locked) {
                // Firefox can retain an editing host after its ancestor becomes
                // inert. Explicitly end editing on each surface as well.
                const editable = Array.from(card.querySelectorAll('[contenteditable="true"]'));
                const inputs = [state.dateInput, ...state.tags.querySelectorAll('input, button')];
                lockedFields = {editable, inputs: inputs.map(input => [input, input.disabled])};
                editable.forEach(element => element.setAttribute('contenteditable', 'false'));
                inputs.forEach(input => { input.disabled = true; });
            } else {
                lockedFields.editable.forEach(element => element.setAttribute('contenteditable', 'true'));
                lockedFields.inputs.forEach(([input, disabled]) => { input.disabled = disabled; });
                lockedFields = null;
            }
            card.inert = locked;
        };
        const setBusy = (busy) => {
            if (busy) card.setAttribute('aria-busy', 'true');
            else card.removeAttribute('aria-busy');
            // Lock every editing surface, including nested captions and tag/date
            // controls, without excluding their values from FormData.
            lockEditing(busy);
            buttons.forEach((button) => {
                button.disabled = busy;
            });
        };

        try {
            // Keep the draft durable even if processing an attachment never
            // reaches its upload request or a save's preparation times out.
            state?.recovery?.persist();
            setBusy(true);
            if (state) {
                const preparationOptions = {timeoutMs: editorConfig().saveTimeoutMs, timeoutMessage: editorConfig().saveTimeout};
                if (state.mediaUploads.size > 0) {
                    const uploads = await window.RegisterEditorRequest.withDeadline(Promise.all(Array.from(state.mediaUploads)), preparationOptions);
                    const failure = uploads.find((result) => result instanceof Error);
                    if (failure) throw failure;
                }
                if (state.aiAltTasks.size > 0) {
                    await window.RegisterEditorRequest.withDeadline(Promise.all(Array.from(state.aiAltTasks)), preparationOptions);
                }
                await redatePendingMedia(state);
                // Validation may focus an invalid field. This synchronous section
                // relocks the card before yielding to the save request.
                lockEditing(false);
                if (editorStates.get(card) !== state || !syncEditor(state)) {
                    return;
                }
                const slugChanged = state.slugField && state.slugField.value !== state.originalSlug;
                if (!state.creating && !state.titleDirty && !state.bodyDirty && !state.tagsDirty && !state.dateDirty && !slugChanged) {
                    closeEditor(card, true);
                    return;
                }
                lockEditing(true);
            }

            clearError(form);
            state?.recovery?.persist();
            const {response, data: payload} = await window.RegisterEditorRequest.requestJson(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }, {timeoutMs: editorConfig().saveTimeoutMs,
                retries: state ? 1 : 0, timeoutMessage: editorConfig().saveTimeout});
            if (!response.ok || !payload || payload.success !== true) {
                throw new Error(payload?.message || editorConfig().editError || 'Unable to change the post.');
            }

            setBusy(false);
            if (payload.action === 'edit') {
                updateEditedCard(card, form, payload);
            } else if (payload.action === 'create') {
                const laterDraft = payload.replayed && payload.request_matched === false && state
                    ? recoverySnapshot(state) : null;
                updateCreatedCard(card, form, payload);
                if (laterDraft) {
                    beginEdit(card.querySelector('.post-edit-start'));
                    const next = editorStates.get(card);
                    if (next) {
                        restorePostRecovery(next, {snapshot: laterDraft});
                        showEditorStatus(next, editorConfig().creationRecovered || 'The post was already created. Review and save your later changes.');
                    }
                }
            } else if (payload.action === 'delete') {
                removeDeletedCard(card, payload);
            } else {
                throw new Error('Unable to process the server response.');
            }
        } catch (error) {
            showError(
                form,
                error instanceof Error ? error.message : (editorConfig().editError || 'Unable to change the post.'),
            );
        } finally {
            if (state) {
                state.submitting = false;
            }
            if (card.isConnected) {
                setBusy(false);
                if (editorStates.get(card) === state && document.activeElement === document.body
                    && focusBeforeSave instanceof HTMLElement && focusBeforeSave.isConnected) {
                    focusBeforeSave.focus({preventScroll: true});
                }
            }
        }
    }

    // Native input and DOM-based tools must share ONE undo timeline. Native undo
    // cannot see wrapping a <tt>, unlinking a node, or finishing an async upload.
    // Keep DOM clones (not reparsed HTML) and both selection endpoints; reparsing
    // browser-generated editing HTML can change its structure and caret offsets.
    function selectionIsInside(element) {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            return false;
        }
        const range = selection.getRangeAt(0);
        return element.contains(range.commonAncestorContainer);
    }

    function runFormattingCommand(state, command, value = null) {
        if (state.history && (command === 'undo' || command === 'redo')) {
            return state.history[command]();
        }
        if (!selectionIsInside(state.body)) {
            return false;
        }
        const execute = () => {
            // execCommand (including the context menu's Cut) need not emit
            // beforeinput, so protect its selection here as well.
            protectSelectedMediaBoundary(state.body);
            const bodyBefore = state.body.innerHTML;
            state.body.focus();
            if (command === 'formatBlock' && value === 'p') {
                if (paragraphsFromSelectedLists(state)) {
                    state.bodyDirty = true;
                    return true;
                }
            }
            const changed = document.execCommand(command, false, value);
            if (command === 'removeFormat' && selectionIsInside(state.body)) {
                // Firefox's native removeFormat does not remove our <tt> marks.
                const range = window.getSelection().getRangeAt(0);
                const codes = Array.from(new Set(textPortionsInRange(state.body, range)
                    .flatMap(({node}) => inlineCodeAncestors(state.body, node))));
                if (codes.length > 0) removeInlineCode(state, range, codes);
            }
            if (state.body.innerHTML !== bodyBefore) state.bodyDirty = true;
            return changed;
        };
        return state.history ? state.history.transact(execute) : execute();
    }

    function paragraphsFromSelectedLists(state) {
        const range = window.getSelection().getRangeAt(0);
        const items = new Set();
        const include = (node) => {
            const element = node instanceof Element ? node : node.parentElement;
            const item = element?.closest('li');
            if (item && state.body.contains(item)) items.add(item);
        };
        if (range.collapsed) {
            include(range.startContainer);
        } else {
            textPortionsInRange(state.body, range).forEach(({node}) => include(node));
            state.body.querySelectorAll('li:empty, li img, li audio, li video, li br').forEach((node) => {
                if (range.intersectsNode(node)) include(node);
            });
        }
        if (items.size === 0) return false;

        // Mark both endpoints before moving nodes. This also preserves a caret in
        // a partially selected item and backward selections through list splitting.
        const selection = window.getSelection();
        const backwards = selection.anchorNode === range.endContainer && selection.anchorOffset === range.endOffset;
        const start = document.createElement('span');
        const end = document.createElement('span');
        const endRange = range.cloneRange();
        endRange.collapse(false);
        endRange.insertNode(end);
        const startRange = range.cloneRange();
        startRange.collapse(true);
        startRange.insertNode(start);
        const blocks = 'P,DIV,H1,H2,H3,H4,H5,H6,OL,UL,BLOCKQUOTE,PRE,TABLE,HR'.split(',');
        const isBlock = node => node instanceof Element && blocks.includes(node.tagName);
        function splitParagraphs(source, target) {
            let paragraph = null;
            Array.from(source.childNodes).forEach((node) => {
                if (isBlock(node)) {
                    paragraph = null;
                    target.append(node);
                } else {
                    if (!paragraph) {
                        paragraph = document.createElement('p');
                        target.append(paragraph);
                    }
                    paragraph.append(node);
                }
            });
        }
        try {
            // Inner lists first: converting one nested item must not unwrap its
            // parent item or reset numbering on unselected siblings.
            Array.from(state.body.querySelectorAll('ol, ul')).reverse().forEach((list) => {
                if (!Array.from(list.children).some(item => items.has(item))) return;
                const replacement = document.createDocumentFragment();
                let group = null;
                const step = list.hasAttribute('reversed') ? -1 : 1;
                let number = Number(list.getAttribute('start') ?? (step < 0 ? list.children.length : 1));
                Array.from(list.children).forEach((item) => {
                    if (item.hasAttribute('value')) number = Number(item.getAttribute('value'));
                    if (items.has(item)) {
                        group = null;
                        if (!item.hasChildNodes()) item.append(document.createElement('br'));
                        splitParagraphs(item, replacement);
                    } else {
                        if (!group) {
                            group = list.cloneNode(false);
                            if (list.tagName === 'OL') group.setAttribute('start', String(number));
                            replacement.append(group);
                        }
                        group.append(item);
                    }
                    number += step;
                });
                list.replaceWith(replacement);
            });
            // A selection may contain a heading outside or inside a list too.
            // Convert those blocks without native formatBlock merging neighbours.
            const markedRange = document.createRange();
            markedRange.setStartAfter(start);
            markedRange.setEndBefore(end);
            Array.from(state.body.querySelectorAll('h1, h2, h3, h4, h5, h6, pre, blockquote')).reverse().forEach((block) => {
                if (!markedRange.intersectsNode(block)) return;
                const paragraph = document.createElement('p');
                Array.from(block.attributes).forEach(({name, value}) => paragraph.setAttribute(name, value));
                paragraph.append(...block.childNodes);
                block.replaceWith(paragraph);
            });
            // Chromium can create <p><ol>…</ol></p> while editing. Do not carry
            // that invalid wrapper into the resulting paragraphs or saved HTML.
            Array.from(state.body.querySelectorAll('p')).reverse().forEach((paragraph) => {
                if (!Array.from(paragraph.childNodes).some(isBlock)) return;
                const replacement = document.createDocumentFragment();
                splitParagraphs(paragraph, replacement);
                paragraph.replaceWith(replacement);
            });
            const selected = document.createRange();
            selected.setStartAfter(start);
            selected.setEndBefore(end);
            start.remove();
            end.remove();
            selectRange(state, selected);
            if (backwards && !selected.collapsed) {
                selection.setBaseAndExtent(selected.endContainer, selected.endOffset, selected.startContainer, selected.startOffset);
            }
        } finally {
            start.remove();
            end.remove();
        }
        return true;
    }

    function rangeIsInside(element, range) {
        return element.contains(range.startContainer)
            && element.contains(range.endContainer)
            && element.contains(range.commonAncestorContainer);
    }

    function contextBoundaryNode(container, offset, backwards) {
        if (!(container instanceof Element) || container.childNodes.length === 0) {
            return container;
        }
        let node;
        if (backwards) {
            node = container.childNodes[Math.max(0, Math.min(offset - 1, container.childNodes.length - 1))];
            while (node instanceof Element && node.lastChild) {
                node = node.lastChild;
            }
        } else {
            node = container.childNodes[Math.min(offset, container.childNodes.length - 1)];
            while (node instanceof Element && node.firstChild) {
                node = node.firstChild;
            }
        }
        return node;
    }

    function contextBlockElement(root, node) {
        let element = node instanceof Element ? node : node.parentElement;
        let fallback = null;
        while (element && element !== root) {
            if (['BLOCKQUOTE', 'PRE', 'OL', 'UL'].includes(element.tagName)) {
                return element;
            }
            if (fallback === null && ['P', 'H2', 'H3', 'H4'].includes(element.tagName)) {
                fallback = element;
            }
            element = element.parentElement;
        }
        return fallback;
    }

    function contextBlockStyle(root, range) {
        const startNode = contextBoundaryNode(range.startContainer, range.startOffset, range.collapsed);
        const endNode = range.collapsed
            ? startNode
            : contextBoundaryNode(range.endContainer, range.endOffset, true);
        const start = contextBlockElement(root, startNode);
        const end = contextBlockElement(root, endNode);
        if (!(start instanceof Element) || !(end instanceof Element) || start.tagName !== end.tagName) {
            return null;
        }
        return {
            'P': 'paragraph',
            'H2': 'h2',
            'H3': 'h3',
            'H4': 'h4',
            'BLOCKQUOTE': 'quote',
            'PRE': 'code',
            'OL': 'ordered-list',
            'UL': 'unordered-list',
        }[start.tagName] || null;
    }

    function styledBlockAtCaretEnd(root, selection) {
        if (!selection || selection.rangeCount === 0) {
            return null;
        }
        const range = selection.getRangeAt(0);
        if (!range.collapsed || !rangeIsInside(root, range)) {
            return null;
        }
        const caretNode = contextBoundaryNode(range.startContainer, range.startOffset, true);
        const block = contextBlockElement(root, caretNode);
        if (!(block instanceof HTMLElement) || !['BLOCKQUOTE', 'PRE'].includes(block.tagName)) {
            return null;
        }
        const tail = range.cloneRange();
        tail.setEnd(block, block.childNodes.length);
        const remaining = tail.cloneContents();
        if (
            (block.tagName === 'PRE' ? remaining.textContent !== '' : remaining.textContent.trim() !== '')
            || remaining.querySelector('img, audio, video, figure, hr')
        ) {
            return null;
        }
        return block;
    }

    function quoteAtCaret(root, selection) {
        const block = styledBlockAtCaretEnd(root, selection);
        return block?.tagName === 'BLOCKQUOTE' ? block : null;
    }

    function inlineCodeAtCaretEnd(root, selection) {
        if (!selection || selection.rangeCount !== 1) {
            return null;
        }
        const range = selection.getRangeAt(0);
        if (!range.collapsed || !rangeIsInside(root, range)) {
            return null;
        }

        const ancestors = inlineCodeAncestors(root, range.startContainer);
        const inlineCode = ancestors.at(-1) || (
            range.startContainer instanceof HTMLElement
            && range.startContainer.tagName === 'TT'
                ? range.startContainer
                : null
        );
        if (!(inlineCode instanceof HTMLElement)) {
            return null;
        }

        const tail = range.cloneRange();
        tail.setEnd(inlineCode, inlineCode.childNodes.length);
        return tail.cloneContents().textContent === '' ? inlineCode : null;
    }

    function moveAfterInlineCode(event, state) {
        if (
            event.key !== 'ArrowRight'
            || event.isComposing
            || event.shiftKey
            || event.altKey
            || event.ctrlKey
            || event.metaKey
        ) {
            return false;
        }
        const inlineCode = inlineCodeAtCaretEnd(state.body, window.getSelection());
        if (!inlineCode) {
            return false;
        }

        event.preventDefault();
        event.stopPropagation();
        const parent = inlineCode.parentNode;
        if (!parent) {
            return false;
        }
        const next = inlineCode.nextSibling;
        if (next?.nodeType === Node.TEXT_NODE && next.data !== '') {
            const range = document.createRange();
            range.setStart(next, 0);
            range.collapse(true);
            selectRange(state, range);
            return true;
        }
        const marker = next instanceof HTMLElement
            && next.hasAttribute('data-post-inline-code-exit')
            ? next
            : document.createElement('span');
        if (!marker.hasAttribute('data-post-inline-code-exit')) {
            marker.setAttribute('data-post-inline-code-exit', '');
            marker.setAttribute('contenteditable', 'false');
            marker.setAttribute('aria-hidden', 'true');
            parent.insertBefore(marker, inlineCode.nextSibling);
        }
        const range = document.createRange();
        range.setStartAfter(marker);
        range.collapse(true);
        selectRange(state, range);
        return true;
    }

    function exitStyledBlockOnEnter(event, state) {
        if (
            event.key !== 'Enter'
            || event.isComposing
            || event.shiftKey
            || event.altKey
            || event.ctrlKey
            || event.metaKey
        ) {
            return false;
        }
        const block = styledBlockAtCaretEnd(state.body, window.getSelection());
        if (!block) {
            return false;
        }
        event.preventDefault();
        const empty = (block.tagName === 'PRE' ? block.textContent === '' : block.textContent.trim() === '')
            && !block.querySelector('img, audio, video, figure, hr');
        if (empty) {
            runFormattingCommand(state, 'formatBlock', 'p');
        } else {
            const range = document.createRange();
            range.setStartAfter(block);
            range.collapse(true);
            selectRange(state, range);
            // Use the browser's edit transaction so Undo also removes this paragraph.
            runFormattingCommand(state, 'insertHTML', '<p><br></p>');
        }
        markBodyChanged(state);
        return true;
    }

    function exitQuoteOnEnter(event, state) {
        const block = styledBlockAtCaretEnd(state.body, window.getSelection());
        return block?.tagName === 'BLOCKQUOTE' && exitStyledBlockOnEnter(event, state);
    }

    function selectRange(state, range) {
        if (!rangeIsInside(state.body, range)) {
            return false;
        }
        state.body.focus({preventScroll: true});
        const selection = window.getSelection();
        if (!selection) {
            return false;
        }
        selection.removeAllRanges();
        selection.addRange(range);
        syncBoundaryCaret();
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
            targetImage: context.targetImage,
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

    function createContextMenuAnchor() {
        const namespace = 'http://www.w3.org/2000/svg';
        const anchor = document.createElementNS(namespace, 'svg');
        anchor.classList.add('post-editor-context-anchor');
        anchor.setAttribute('focusable', 'false');
        anchor.setAttribute('role', 'presentation');
        const viewport = document.createElementNS(namespace, 'foreignObject');
        anchor.append(viewport);
        // Keep menu controls out of the editable HTML and its live selection.
        // SVG geometry positions the overlay without CSP-blocked inline CSS.
        document.body.append(anchor);
        return {anchor, viewport};
    }

    function contextMenuPoint(state, range, event, targetImage) {
        if (Number.isFinite(event?.clientX) && Number.isFinite(event?.clientY)) {
            return {x: event.clientX, y: event.clientY};
        }
        if (targetImage) {
            const rect = targetImage.getBoundingClientRect();
            return {x: rect.left, y: rect.top};
        }
        const visible = Array.from(range.getClientRects()).filter((rect) => {
            return rect.height > 0 && rect.bottom > 12 && rect.top < window.innerHeight - 12;
        });
        let rect = visible.at(-1) || range.getBoundingClientRect();
        if (rect.height === 0) {
            rect = state.body.getBoundingClientRect();
        }
        return {x: rect.left, y: rect.bottom};
    }

    function contextMenuPosition(point, size, viewport) {
        const margin = 12;
        const gap = 4;
        const x = point.x + gap + size.width <= viewport.width - margin
            ? point.x + gap
            : point.x - gap - size.width;
        const y = point.y + gap + size.height <= viewport.height - margin
            ? point.y + gap
            : point.y - gap - size.height;
        return {
            x: Math.max(margin, Math.min(x, viewport.width - size.width - margin)),
            y: Math.max(margin, Math.min(y, viewport.height - size.height - margin)),
        };
    }

    function positionContextMenu(context) {
        const {menu, viewport, point} = context;
        viewport.setAttribute('x', '0');
        viewport.setAttribute('y', '0');
        viewport.setAttribute('width', String(window.innerWidth));
        viewport.setAttribute('height', String(window.innerHeight));
        // On narrow screens the existing bottom sheet occupies the viewport.
        if (getComputedStyle(menu).position === 'fixed') {
            return;
        }
        const rect = menu.getBoundingClientRect();
        const position = contextMenuPosition(point, rect, {
            width: window.innerWidth,
            height: window.innerHeight,
        });
        viewport.setAttribute('x', String(position.x));
        viewport.setAttribute('y', String(position.y));
        viewport.setAttribute('width', String(rect.width));
        viewport.setAttribute('height', String(rect.height));
    }

    function visibleContextButtons(menu) {
        return Array.from(menu.querySelectorAll('button:not(:disabled)')).filter((button) => {
            return button.closest('[hidden]') === null;
        });
    }

    function applyContextBlockState(menu, blockStyle) {
        ['paragraph', 'h2', 'h3', 'h4', 'quote', 'code', 'ordered-list', 'unordered-list'].forEach((action) => {
            const button = menu.querySelector(`[data-context-action="${action}"]`);
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }
            const active = action === blockStyle;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', String(active));
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
            context.imagePanel.querySelector('button, input')?.focus({preventScroll: true});
        } else {
            visibleContextButtons(context.menu)[0]?.focus({preventScroll: true});
        }
        positionContextMenu(context);
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
        context.linkInput.focus({preventScroll: true});
        context.linkInput.select();
        positionContextMenu(context);
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
        clearAiChangeMarks(state.body);
        state.bodyDirty = true;
        clearError(state.form);
        clearStatus(state.card);
        state.history?.schedule();
    }

    function imageNeedsGeneratedAlt(image) {
        return !image.hasAttribute('alt') || (image.getAttribute('alt') || '').trim() === '';
    }

    function aiAltEnabled() {
        return editorConfig().aiAltEnabled === true;
    }

    function aiAltContext(state) {
        return textFromHtml(editableBodyHtml(state))
            .replace(/\s+/gu, ' ')
            .trim()
            .slice(0, 50000);
    }

    function hasFailedMissingImageAlt(state) {
        return Array.from(state.body.querySelectorAll('img')).some((image) => (
            imageNeedsGeneratedAlt(image) && state.aiAltFailures.has(image)
        ));
    }

    function updateAiAltStatus(state, applied = false) {
        if (editorStates.get(state.card) !== state) {
            return;
        }
        if (state.aiAltStatusPending > 0) {
            showEditorStatus(
                state,
                editorConfig().aiAltWorking || 'AI is creating alt text…',
            );
            return;
        }
        if (hasFailedMissingImageAlt(state)) {
            showEditorStatus(
                state,
                editorConfig().aiAltFailed || 'Unable to create alt text. Add it manually or try again.',
                true,
            );
            return;
        }
        if (applied) {
            showEditorStatus(
                state,
                editorConfig().aiAltApplied || 'AI added alt text.',
            );
        }
    }

    function cancelImageAlt(state, image) {
        const record = state.aiAltImages.get(image);
        if (record) {
            record.cancelled = true;
            record.controller?.abort();
        }
        state.aiAltFailures.delete(image);
    }

    async function requestImageAlt(state, image, source, record) {
        if (
            record.cancelled
            || editorStates.get(state.card) !== state
            || !state.body.contains(image)
        ) {
            return 'cancelled';
        }

        const controller = new AbortController();
        record.controller = controller;
        record.status = 'generating';
        state.aiAltControllers.add(controller);
        const expectedAlt = image.getAttribute('alt') || '';
        const expectedSrc = image.getAttribute('src') || '';
        try {
            const preview = await prepareAiAltPreview(source || image, controller.signal);
            throwIfAiAltAborted(controller.signal);

            const token = state.form.elements.namedItem('inplace_token');
            const data = new FormData();
            data.set('inplace_action', 'ai_alt');
            data.set('inplace_token', token instanceof HTMLInputElement ? token.value : '');
            data.set('title', state.title.textContent || '');
            data.set('text', aiAltContext(state));
            data.set(
                'image_alt_source',
                preview.blob,
                `image-alt.${preview.extension}`,
            );

            const {response, data: payload} = await window.RegisterEditorRequest.requestJson(state.form.action, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller.signal,
            }, {timeoutMs: editorConfig().saveTimeoutMs, timeoutMessage: editorConfig().saveTimeout});

            if (
                !response.ok
                || !payload
                || payload.success !== true
                || payload.action !== 'ai_alt'
                || typeof payload.result !== 'string'
                || payload.result.trim() === ''
            ) {
                throw new Error(payload?.message || editorConfig().aiAltFailed || 'Unable to create alt text.');
            }

            if (
                record.cancelled
                || editorStates.get(state.card) !== state
                || !state.body.contains(image)
                || image.getAttribute('src') !== expectedSrc
                || (image.getAttribute('alt') || '') !== expectedAlt
            ) {
                return 'cancelled';
            }

            image.setAttribute('alt', payload.result.trim());
            state.aiAltFailures.delete(image);
            markBodyChanged(state);
            return 'applied';
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return 'cancelled';
            }
            state.aiAltFailures.add(image);
            return 'failed';
        } finally {
            state.aiAltControllers.delete(controller);
            record.controller = null;
        }
    }

    function queueImageAlt(state, image, source = null, force = false, showStatus = true) {
        if (
            !aiAltEnabled()
            || !(image instanceof HTMLImageElement)
            || !state.body.contains(image)
            || (!force && !imageNeedsGeneratedAlt(image))
        ) {
            return Promise.resolve('skipped');
        }

        const current = state.aiAltImages.get(image);
        if (current && !force && !current.cancelled) {
            return current.promise;
        }
        if (current) {
            current.cancelled = true;
            current.controller?.abort();
        }

        const record = {
            status: 'queued',
            cancelled: false,
            controller: null,
            promise: null,
        };
        ++state.aiAltPending;
        if (showStatus) {
            ++state.aiAltStatusPending;
            updateAiAltStatus(state);
        }
        const task = state.aiAltTail
            .catch(() => 'cancelled')
            .then(() => requestImageAlt(state, image, source, record));
        record.promise = task;
        state.aiAltImages.set(image, record);
        state.aiAltTasks.add(task);
        state.aiAltTail = task;
        task.then((outcome) => {
            --state.aiAltPending;
            if (showStatus) {
                --state.aiAltStatusPending;
            }
            if (state.aiAltImages.get(image) === record) {
                state.aiAltImages.delete(image);
            }
            updateAiAltStatus(state, showStatus && outcome === 'applied');
        }).finally(() => {
            state.aiAltTasks.delete(task);
        });
        return task;
    }

    function generateMissingImageAlts(state) {
        if (!aiAltEnabled()) {
            return;
        }
        state.body.querySelectorAll('img').forEach((image) => {
            if (imageNeedsGeneratedAlt(image)) {
                queueImageAlt(state, image);
            }
        });
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
        const legacyHost = image.closest('.post-picture, figure');
        if (legacyHost instanceof HTMLElement && state.body.contains(legacyHost)) {
            const legacyCaption = legacyHost.matches('.post-picture')
                ? legacyHost.querySelector(':scope > .post-caption.post-media-overlay-caption')
                : legacyHost.querySelector(':scope > figcaption.post-media-overlay-caption');
            if (legacyCaption instanceof HTMLElement) {
                legacyCaption.classList.remove('post-media-overlay-caption', 'is-editing-caption');
                legacyCaption.removeAttribute('data-caption-font');
                legacyCaption.removeAttribute('data-caption-background');
                legacyCaption.removeAttribute('contenteditable');
                legacyCaption.removeAttribute('role');
                legacyCaption.removeAttribute('aria-label');
                legacyCaption.removeAttribute('aria-multiline');
                legacyCaption.removeAttribute('spellcheck');
                legacyCaption.removeAttribute('data-placeholder');
                legacyCaption.removeAttribute('tabindex');
                legacyHost.classList.remove('post-media-overlay');
                state.bodyDirty = true;
            }
        }

        const generated = image.closest('[data-post-media-overlay]');
        if (generated instanceof HTMLElement && state.body.contains(generated)) {
            return {
                wrapper: generated,
                caption: generated.querySelector(':scope > .post-media-overlay-caption'),
                generated: true,
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
            caption = document.createElement('span');
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
        // Read the rendered text while the caption is still editable. WebKit
        // can omit it from innerText once the overlay's clipping is restored.
        const text = commit ? imageCaptionEditorText(editor.caption) : editor.original;
        state.imageCaptionEditor = null;
        editor.controller.abort();
        editor.history?.destroy();
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

        const styleChanged = text !== '' && (
            editor.caption.dataset.captionFont !== editor.originalFont
            || editor.caption.dataset.captionBackground !== editor.originalBackground
        );
        applyImageCaption(state, editor.image, text);
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

        if (commit && (text !== editor.original || styleChanged)) {
            markBodyChanged(state);
        } else if (!commit) {
            // Cancelling the caption must retain unrelated changes (for example,
            // an AI image description that completed during this session).
            state.bodyDirty = editor.bodyDirtyBefore || editableBodyHtml(state) !== editor.bodyHtmlBefore;
        }
        state.history?.record();
        if (restoreFocus) {
            state.body.focus({preventScroll: true});
            window.getSelection()?.removeAllRanges();
        }
    }

    function beginImageCaptionEditing(state, image, placeholder) {
        finishInlineMediaCaptions(state);
        finishImageCaptionEditing(state, true, false);
        state.history?.before();
        const originalContext = imageCaptionContext(state, image);
        const original = (originalContext.caption?.textContent || '').replace(/\r\n?/gu, '\n').trim();
        const originalFontAttribute = originalContext.caption?.getAttribute('data-caption-font') ?? null;
        const originalBackgroundAttribute = originalContext.caption?.getAttribute('data-caption-background') ?? null;
        const bodyHtmlBefore = editableBodyHtml(state);
        const context = ensureImageCaption(state, image);
        const caption = context.caption;
        const toolbarTemplate = editorTemplate('.post-image-caption-toolbar-template');
        const toolbarFragment = toolbarTemplate instanceof HTMLTemplateElement
            ? toolbarTemplate.content.cloneNode(true)
            : null;
        const toolbar = toolbarFragment?.querySelector('.post-media-caption-toolbar');
        if (!(toolbar instanceof HTMLElement)) {
            applyImageCaption(state, image, original);
            return;
        }
        context.wrapper.append(toolbar);
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
            bodyHtmlBefore,
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
        state.imageCaptionEditor.history = createFieldHistory(state, caption, controller);
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
        state.history?.before();
        const url = normalizeLinkUrl(context.linkInput.value);
        if (url === null) {
            context.linkError.textContent = editorConfig().invalidLink || 'Enter a safe link address.';
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
        state.history?.before();
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

    function textPortionsInRange(root, range) {
        const portions = [];
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        let node;
        while ((node = walker.nextNode()) !== null) {
            if (!range.intersectsNode(node)) {
                continue;
            }
            const start = node === range.startContainer ? range.startOffset : 0;
            const end = node === range.endContainer ? range.endOffset : node.data.length;
            if (start < end) {
                portions.push({node, start, end});
            }
        }
        return portions;
    }

    function inlineCodeAncestors(root, node) {
        const result = [];
        let element = node.parentElement;
        while (element && element !== root) {
            if (element instanceof HTMLElement && element.tagName === 'TT') {
                result.push(element);
            }
            element = element.parentElement;
        }
        return result;
    }

    function addInlineCode(state, portions) {
        const selectedPortions = new Array(portions.length);
        for (let index = portions.length - 1; index >= 0; index--) {
            const portion = portions[index];
            if (inlineCodeAncestors(state.body, portion.node).length > 0) {
                selectedPortions[index] = portion;
                continue;
            }

            let selectedText = portion.node;
            if (portion.end < selectedText.data.length) {
                selectedText.splitText(portion.end);
            }
            if (portion.start > 0) {
                selectedText = selectedText.splitText(portion.start);
            }
            const inlineCode = document.createElement('tt');
            selectedText.replaceWith(inlineCode);
            inlineCode.append(selectedText);
            selectedPortions[index] = {
                node: selectedText,
                start: 0,
                end: selectedText.data.length,
            };
        }

        mergeAdjacentInlineCode(state.body);
        markBodyChanged(state);
        const selectedRange = document.createRange();
        const first = selectedPortions[0];
        const last = selectedPortions[selectedPortions.length - 1];
        selectedRange.setStart(first.node, first.start);
        selectedRange.setEnd(last.node, last.end);
        selectRange(state, selectedRange);
    }

    function mergeAdjacentInlineCode(root) {
        root.querySelectorAll('tt').forEach((inlineCode) => {
            if (!inlineCode.parentNode) {
                return;
            }

            let next = inlineCode.nextSibling;
            while (next) {
                if (next.nodeType === Node.TEXT_NODE && next.data === '') {
                    const following = next.nextSibling;
                    next.remove();
                    next = following;
                    continue;
                }
                if (!(next instanceof HTMLElement) || next.tagName !== 'TT') {
                    break;
                }

                const following = next.nextSibling;
                while (next.firstChild) {
                    inlineCode.append(next.firstChild);
                }
                next.remove();
                next = following;
            }
        });
    }

    function inlineCodeBoundary(state, token, edge) {
        return state.body.querySelector(
            `[data-post-inline-code-boundary="${token}-${edge}"]`,
        );
    }

    function inlineFragmentHasContent(fragment) {
        return fragment.textContent !== ''
            || fragment.querySelector('br, img, audio, video, svg, math') !== null;
    }

    function splitInlineCodeAtSelection(state, inlineCode, token) {
        const startMarker = inlineCodeBoundary(state, token, 'start');
        const endMarker = inlineCodeBoundary(state, token, 'end');
        if (!startMarker || !endMarker || !inlineCode.isConnected) {
            return;
        }

        const selectionRange = document.createRange();
        selectionRange.setStartAfter(startMarker);
        selectionRange.setEndBefore(endMarker);
        if (!selectionRange.intersectsNode(inlineCode)) {
            return;
        }

        const startInside = inlineCode.contains(startMarker);
        const endInside = inlineCode.contains(endMarker);
        const replacement = document.createDocumentFragment();

        if (startInside) {
            const beforeRange = document.createRange();
            beforeRange.setStart(inlineCode, 0);
            beforeRange.setEndBefore(startMarker);
            const before = beforeRange.cloneContents();
            if (inlineFragmentHasContent(before)) {
                const beforeCode = inlineCode.cloneNode(false);
                beforeCode.append(before);
                replacement.append(beforeCode);
            }
        }

        const selectedCodeRange = document.createRange();
        selectedCodeRange.selectNodeContents(inlineCode);
        if (startInside) {
            selectedCodeRange.setStartBefore(startMarker);
        }
        if (endInside) {
            selectedCodeRange.setEndAfter(endMarker);
        }
        replacement.append(selectedCodeRange.cloneContents());

        if (endInside) {
            const afterRange = document.createRange();
            afterRange.setStartAfter(endMarker);
            afterRange.setEnd(inlineCode, inlineCode.childNodes.length);
            const after = afterRange.cloneContents();
            if (inlineFragmentHasContent(after)) {
                const afterCode = inlineCode.cloneNode(false);
                afterCode.append(after);
                replacement.append(afterCode);
            }
        }

        inlineCode.replaceWith(replacement);
    }

    function removeInlineCode(state, range, inlineCodes) {
        const token = String(++inlineCodeBoundarySequence);
        const startMarker = document.createElement('span');
        const endMarker = document.createElement('span');
        startMarker.dataset.postInlineCodeBoundary = `${token}-start`;
        endMarker.dataset.postInlineCodeBoundary = `${token}-end`;

        const startRange = range.cloneRange();
        const endRange = range.cloneRange();
        startRange.collapse(true);
        endRange.collapse(false);
        endRange.insertNode(endMarker);
        startRange.insertNode(startMarker);

        const elementDepth = (element) => {
            let depth = 0;
            for (let parent = element.parentElement; parent; parent = parent.parentElement) {
                depth++;
            }
            return depth;
        };
        inlineCodes.sort((left, right) => elementDepth(right) - elementDepth(left));

        try {
            inlineCodes.forEach((inlineCode) => {
                splitInlineCodeAtSelection(state, inlineCode, token);
            });

            const currentStart = inlineCodeBoundary(state, token, 'start');
            const currentEnd = inlineCodeBoundary(state, token, 'end');
            if (!currentStart || !currentEnd) {
                return;
            }
            const selectedRange = document.createRange();
            selectedRange.setStartAfter(currentStart);
            selectedRange.setEndBefore(currentEnd);
            currentStart.remove();
            currentEnd.remove();
            markBodyChanged(state);
            selectRange(state, selectedRange);
        } finally {
            state.body.querySelectorAll(
                `[data-post-inline-code-boundary^="${token}-"]`,
            ).forEach((marker) => marker.remove());
        }
    }

    function contextInlineCode(state) {
        const context = detachContextMenu(state);
        if (
            !context
            || context.range.collapsed
            || !selectRange(state, context.range)
        ) {
            return;
        }
        state.history?.before();

        const portions = textPortionsInRange(state.body, context.range);
        if (portions.length === 0) {
            return;
        }
        const ancestors = portions.map((portion) => (
            inlineCodeAncestors(state.body, portion.node)
        ));
        if (ancestors.every((items) => items.length > 0)) {
            removeInlineCode(
                state,
                context.range,
                Array.from(new Set(ancestors.flat())),
            );
            return;
        }
        addInlineCode(state, portions);
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
        removeInlineCodeExitMarkers(container);
        clearAiChangeMarks(container);
        container.querySelectorAll('[data-register-audio-native]').forEach((audio) => {
            audio.removeAttribute('data-register-audio-native');
        });
        container.querySelectorAll('.post-editor-context-anchor').forEach((anchor) => anchor.remove());
        container.querySelectorAll('.post-media-caption-toolbar').forEach((toolbar) => toolbar.remove());
        return container.innerHTML;
    }

    function replaceRangeHtml(state, range, html) {
        if (!rangeIsInside(state.body, range)) {
            return null;
        }
        const fragment = range.createContextualFragment(html);
        const insertedNodes = Array.from(fragment.childNodes);
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
        return insertedNodes;
    }

    async function runContextAi(state, action) {
        const context = state.contextMenu;
        if (!context) {
            return;
        }

        const usesSelection = context.selected && !['tags', 'title'].includes(action);
        const sourceRange = usesSelection ? context.range.cloneRange() : null;
        // Upload placeholders belong to live requests. Sending their HTML to AI
        // and then replacing it would detach those requests from the editor.
        // A selection outside the uploads can still be processed independently.
        if (
            state.mediaUploads.size > 0
            && Array.from(state.body.querySelectorAll('[data-post-history-upload]'))
                .some((pending) => !sourceRange || sourceRange.intersectsNode(pending))
        ) {
            closeContextMenu(state, false);
            showEditorStatus(state, editorConfig().aiMediaPending || 'Wait for images and audio to finish uploading.', true);
            return;
        }
        const source = sourceRange ? htmlForRange(sourceRange) : editableBodyHtml(state);
        const sourceText = textFromHtml(source);
        const wholeSource = editableBodyHtml(state);
        const targetValue = action === 'title' ? state.title.textContent
            : action === 'tags' ? state.tagEditor.snapshot() : null;
        closeContextMenu(state, false);
        if (source.trim() === '') {
            showEditorStatus(state, editorConfig().aiFailed || 'Unable to get a response from AI.', true);
            return;
        }

        state.aiController?.abort();
        const controller = new AbortController();
        state.aiController = controller;
        state.card.classList.add('is-ai-working');
        showEditorStatus(state, editorConfig().aiWorking || 'AI is processing the text…');

        const token = state.form.elements.namedItem('inplace_token');
        const data = new FormData();
        data.set('inplace_action', 'ai');
        data.set('inplace_token', token instanceof HTMLInputElement ? token.value : '');
        data.set('ai_action', action);
        data.set('title', state.title.textContent || '');
        data.set('text', source);

        try {
            const {response, data: payload} = await window.RegisterEditorRequest.requestJson(state.form.action, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller.signal,
            }, {timeoutMs: editorConfig().saveTimeoutMs, timeoutMessage: editorConfig().saveTimeout});

            if (
                !response.ok
                || !payload
                || payload.success !== true
                || payload.action !== 'ai'
                || payload.ai_action !== action
                || typeof payload.result !== 'string'
            ) {
                throw new Error(payload?.message || editorConfig().aiFailed || 'Unable to get a response from AI.');
            }
            if (editorStates.get(state.card) !== state || state.aiController !== controller) {
                return;
            }

            if ((action === 'title' && state.title.textContent !== targetValue)
                || (action === 'tags' && state.tagEditor.snapshot() !== targetValue)) {
                showEditorStatus(state, editorConfig().aiSourceChanged || 'The source text has changed.', true);
                return;
            }

            if (sourceRange) {
                if (!rangeIsInside(state.body, sourceRange) || htmlForRange(sourceRange) !== source) {
                    showEditorStatus(state, editorConfig().aiSourceChanged || 'The source text has changed.', true);
                    return;
                }
            } else if (editableBodyHtml(state) !== wholeSource) {
                showEditorStatus(state, editorConfig().aiSourceChanged || 'The source text has changed.', true);
                return;
            }

            if (payload.result === source && !['tags', 'title'].includes(action)) {
                showEditorStatus(
                    state,
                    action === 'proofread'
                        ? (editorConfig().aiProofreadClean || 'No errors found.')
                        : (editorConfig().aiUnchanged || 'AI did not change the text.'),
                );
                return;
            }

            if (!['tags', 'title'].includes(action)) {
                // Removing a focused caption does not fire blur in every browser.
                // Restore body editing and flush caption history before replacing
                // any nodes, including when the reply only replaces a selection.
                finishImageCaptionEditing(state, true, false);
                finishInlineMediaCaptions(state);
                if (sourceRange && (!rangeIsInside(state.body, sourceRange) || htmlForRange(sourceRange) !== source)) {
                    showEditorStatus(state, editorConfig().aiSourceChanged || 'The source text has changed.', true);
                    return;
                }
                state.history?.before();
            }

            if (action === 'title') {
                const title = payload.result.replace(/\s+/gu, ' ').trim();
                if (title === '' || Array.from(title).length > 255) {
                    throw new Error(editorConfig().invalidContent || 'Invalid post content.');
                }
                state.titleHistory?.before();
                state.title.textContent = title;
                state.titleDirty = true;
                focusEdge(state.title, true);
                state.titleHistory?.record();
            } else if (action === 'tags') {
                if (!state.tagEditor.replace(payload.result)) {
                    throw new Error(editorConfig().invalidTags || 'Invalid post tags.');
                }
                state.tagsDirty = true;
                state.tagEditor.focus();
            } else if (sourceRange) {
                const insertedNodes = replaceRangeHtml(state, sourceRange, payload.result);
                if (insertedNodes === null) {
                    throw new Error(editorConfig().aiSourceChanged || 'The source text has changed.');
                }
                markAiChanges(insertedNodes, sourceText);
            } else {
                state.body.innerHTML = payload.result;
                prepareEditableMedia(state.body);
                markBodyChanged(state);
                markAiChanges(Array.from(state.body.childNodes), sourceText);
                focusEdge(state.body, false);
            }

            clearError(state.form);
            showEditorStatus(state, editorConfig().aiApplied || 'AI changes applied.');
            generateMissingImageAlts(state);
        } catch (error) {
            if (!(error instanceof DOMException && error.name === 'AbortError')) {
                showEditorStatus(
                    state,
                    error instanceof Error
                        ? error.message
                        : (editorConfig().aiFailed || 'Unable to get a response from AI.'),
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
            const format = action === 'quote' && state.contextMenu?.blockStyle === 'quote'
                ? ['formatBlock', 'p']
                : formats[action];
            contextFormat(state, ...format);
            return;
        }
        if (action === 'inline-code') {
            contextInlineCode(state);
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
            return;
        }
        if (action === 'generate-image-alt') {
            const context = detachContextMenu(state);
            if (context?.targetImage instanceof HTMLImageElement) {
                queueImageAlt(state, context.targetImage, null, true);
            }
        }
    }

    function openContextMenu(state, event = null, targetOverride = null) {
        const template = editorTemplate('.post-editor-context-menu-template');
        if (!(template instanceof HTMLTemplateElement)) {
            return false;
        }
        const selection = window.getSelection();
        let range = selection && selection.rangeCount > 0 ? selection.getRangeAt(0).cloneRange() : null;
        const selected = range instanceof Range
            && rangeIsInside(state.body, range)
            && !range.collapsed
            && range.toString().trim() !== '';
        const target = targetOverride instanceof Element
            ? targetOverride
            : (event?.target instanceof Element ? event.target : null);
        const image = target?.matches('img')
            ? target
            : target?.closest('[data-post-media-overlay], .post-picture, figure')?.querySelector('img');
        // A live text selection is the user's current editing target. Some
        // Chromium touch/drag paths still report the preceding image as the
        // contextmenu event target after selecting the paragraph below it.
        // Prefer the selection; a plain image click collapses it first.
        const targetImage = !selected
            && image instanceof HTMLImageElement
            && state.body.contains(image)
            ? image
            : null;
        finishImageCaptionEditing(state, true, false);
        closeContextMenu(state, false);

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

        const point = contextMenuPoint(state, range, event, targetImage);
        const fragment = template.content.cloneNode(true);
        const menu = fragment.querySelector('.post-editor-context-menu');
        if (!(menu instanceof HTMLElement)) {
            return false;
        }
        const {anchor, viewport} = createContextMenuAnchor();
        menu.classList.toggle('is-image-menu', targetImage !== null);
        viewport.append(fragment);
        menu.querySelectorAll('[data-context-selection-only]').forEach((element) => {
            element.hidden = targetImage !== null || !selected;
        });
        menu.querySelectorAll('[data-context-caret-only]').forEach((element) => {
            element.hidden = targetImage !== null || selected;
        });
        menu.querySelectorAll('[data-context-image-only]').forEach((element) => {
            element.hidden = targetImage === null;
        });
        const blockStyle = targetImage === null ? contextBlockStyle(state.body, range) : null;
        applyContextBlockState(menu, blockStyle);
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
        const imageAiAltButton = imagePanel?.querySelector('[data-context-action="generate-image-alt"]');
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
            if (imageAiAltButton instanceof HTMLButtonElement) {
                const aiAltState = state.aiAltImages.get(targetImage);
                imageAiAltButton.disabled = Boolean(
                    aiAltState && !aiAltState.cancelled,
                );
            }
        }
        state.contextMenu = {
            anchor,
            viewport,
            point,
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
            imageAiAltButton,
            range,
            selected,
            blockStyle,
            targetImage,
            targetLink: targetLink instanceof HTMLAnchorElement && state.body.contains(targetLink)
                ? targetLink
                : null,
        };

        if (targetImage) {
            imageAltInput.addEventListener('input', () => {
                cancelImageAlt(state, targetImage);
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
            if (handleEditingSaveShortcut(keyEvent, state)) {
                keyEvent.stopPropagation();
                return;
            }
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
            if (keyEvent.target instanceof HTMLInputElement || keyEvent.target instanceof HTMLTextAreaElement) {
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

        positionContextMenu(state.contextMenu);
        if (targetImage) {
            imageLinkButton.focus({preventScroll: true});
        } else {
            visibleContextButtons(menu)[0]?.focus({preventScroll: true});
        }
        return true;
    }

    function handleEditingSaveShortcut(event, state) {
        const modifier = event.ctrlKey || event.metaKey;
        const key = String(event.key || '').toLowerCase();
        if (!modifier || event.altKey || (event.code !== 'KeyS' && key !== 's')) {
            return false;
        }
        event.preventDefault();
        submit(state.form);
        return true;
    }

    function handleEditingShortcut(event, state) {
        if (handleEditingSaveShortcut(event, state)) {
            return true;
        }
        // Caption sessions handle their own typing history until commit adds
        // a single operation to the post's history.
        if (event.target instanceof Element && event.target.closest('.is-editing-caption, .is-editing-inline-caption')) {
            return false;
        }
        if (event.isComposing) {
            return false;
        }

        const modifier = event.ctrlKey || event.metaKey;
        const matchesKey = (code, key) => event.code === code || event.key.toLowerCase() === key;

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
            requestCloseEditor(state.card, true);
            return true;
        }

        if (state.title.contains(event.target) && event.key === 'Enter') {
            event.preventDefault();
            focusEdge(state.body, false);
            return true;
        }

        if (state.body.contains(event.target) && moveAfterInlineCode(event, state)) {
            return true;
        }

        if (state.body.contains(event.target) && exitStyledBlockOnEnter(event, state)) {
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
            const selection = window.getSelection();
            const range = selection && selection.rangeCount > 0 ? selection.getRangeAt(0) : null;
            value = range && contextBlockStyle(state.body, range) === 'quote' ? 'p' : 'blockquote';
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

    document.addEventListener('dragstart', (event) => {
        clearMediaDrag();
        const state = bodyDropState(event.target);
        if (!state || !(event.target instanceof HTMLImageElement) || !event.dataTransfer) return;
        if (state.submitting) {
            event.preventDefault();
            return;
        }
        finishInlineMediaCaptions(state);
        finishImageCaptionEditing(state, true, false);
        let media = event.target;
        for (let parent = media.parentElement; parent && parent !== state.body; parent = parent.parentElement) {
            if (parent.matches('a, picture, figure, .post-picture, .post-media-picture, .post-media-overlay, .post-proportional-wrapper')) {
                media = parent;
            }
        }
        activeMediaDrag = {state, media};
        event.dataTransfer.setData(mediaDragType, 'move');
        event.dataTransfer.effectAllowed = 'move';
    }, false);

    document.addEventListener('dragend', clearMediaDrag, false);

    document.addEventListener('dragenter', (event) => {
        const state = bodyDropState(event.target);
        if (state && (isEditorMediaDrag(event.dataTransfer)
            ? activeMediaDrag?.state === state : transferHasFiles(event.dataTransfer))) {
            state.body.classList.add('is-media-dragover');
        }
    }, false);

    document.addEventListener('dragover', (event) => {
        const state = bodyDropState(event.target);
        if (isEditorMediaDrag(event.dataTransfer)) {
            event.preventDefault();
            event.stopPropagation();
            const allowed = state && activeMediaDrag?.state === state && !state.submitting;
            event.dataTransfer.dropEffect = allowed ? 'move' : 'none';
            if (allowed) state.body.classList.add('is-media-dragover');
            return;
        }
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
        if (isEditorMediaDrag(event.dataTransfer)) {
            event.preventDefault();
            event.stopPropagation();
            const drag = activeMediaDrag;
            clearMediaDrag();
            if (state && drag?.state === state && !state.submitting) {
                moveDraggedMedia(state, drag.media, bodyRangeFromPoint(state, event.clientX, event.clientY));
            }
            return;
        }
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
        // Plain-text captions keep the native clipboard menu and their own
        // editing session. Focusing the post menu would commit the caption and
        // invalidate its selection before Copy or Cut can use it.
        if (target?.closest('.post-editor-context-menu, .is-editing-caption, .is-editing-inline-caption')) {
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
        const clickedCard = cardFor(target);
        const clickedEditorState = clickedCard ? editorStates.get(clickedCard) : null;
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
        if (clickedEditorState) {
            moveFromMediaSideClick(event, clickedEditorState);
        }
    }, true);

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const create = target?.closest('.post-create-start');
        if (!create) {
            return;
        }
        event.preventDefault();
        beginCreate(create);
    }, true);

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const card = cardFor(target);
        if (target instanceof HTMLDialogElement && target.matches('.post-delete-confirmation')) {
            const bounds = target.getBoundingClientRect();
            const clickedBackdrop = event.clientX < bounds.left
                || event.clientX > bounds.right
                || event.clientY < bounds.top
                || event.clientY > bounds.bottom;
            if (clickedBackdrop && card) {
                event.preventDefault();
                closeConfirmation(card, true);
            }
            return;
        }
        const inlineCaption = target?.closest('.is-inline-caption-entry');
        const inlineCaptionState = card ? editorStates.get(card) : null;
        if (
            inlineCaption instanceof HTMLElement
            && inlineCaptionState
            && inlineCaptionState.body.contains(inlineCaption)
        ) {
            event.preventDefault();
            beginInlineMediaCaption(inlineCaptionState, inlineCaption);
            focusInlineMediaCaption(inlineCaptionState, inlineCaption);
            return;
        }
        const toolsToggle = target?.closest('.post-tools-menu-toggle');
        if (toolsToggle) {
            const tools = toolsToggle.closest('.post-inplace-tools');
            if (tools instanceof HTMLElement) {
                const opening = !tools.classList.contains('is-menu-open');
                closeOtherPostToolsMenus(tools);
                tools.classList.toggle('is-menu-open', opening);
                toolsToggle.setAttribute('aria-expanded', String(opening));
                event.preventDefault();
            }
            return;
        }
        document.querySelectorAll('.post-inplace-tools.is-menu-open').forEach((tools) => {
            if (!(target instanceof Node) || !tools.contains(target)) {
                closePostToolsMenu(tools, false);
            }
        });
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

        const edit = target?.closest('.post-edit-start');
        if (edit && beginEdit(edit)) {
            event.preventDefault();
            return;
        }

        const dateButton = target?.closest('.post-inplace-date-button');
        if (dateButton) {
            const dateCard = cardFor(dateButton);
            const state = dateCard ? editorStates.get(dateCard) : null;
            if (state) {
                event.preventDefault();
                try {
                    state.dateInput.showPicker();
                } catch (_error) {
                    state.dateInput.focus({preventScroll: true});
                    state.dateInput.click();
                }
            }
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
                requestCloseEditor(editCard, true);
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
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if (!form.matches('.post-inplace-edit-form, .post-inplace-delete-form')) {
            return;
        }
        event.preventDefault();
        submit(form);
    }, false);

    document.addEventListener('cancel', (event) => {
        const confirmation = event.target;
        if (!(confirmation instanceof HTMLDialogElement) || !confirmation.matches('.post-delete-confirmation')) {
            return;
        }
        const card = cardFor(confirmation);
        if (!card) {
            return;
        }
        event.preventDefault();
        closeConfirmation(card, true);
    }, true);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            const tools = document.querySelector('.post-inplace-tools.is-menu-open');
            if (tools instanceof HTMLElement) {
                event.preventDefault();
                closePostToolsMenu(tools, true);
                return;
            }
        }
        const createModifier = editorPlatform === 'macos'
            ? event.metaKey && !event.ctrlKey
            : event.ctrlKey && !event.metaKey;
        if (
            !event.isComposing
            && !event.repeat
            && createModifier
            && event.altKey
            && !event.shiftKey
            && event.code === 'KeyN'
        ) {
            const editingCard = document.querySelector('.post-card.is-editing');
            const createButton = Array.from(document.querySelectorAll('.post-create-start'))
                .find((button) => button.getClientRects().length > 0);
            if (!editingCard && createButton instanceof HTMLButtonElement) {
                event.preventDefault();
                beginCreate(createButton);
                return;
            }
            if (editingCard?.hasAttribute('data-post-creating')) {
                event.preventDefault();
                editorStates.get(editingCard)?.title.focus();
                return;
            }
        }

        const card = cardFor(event.target);
        const state = card ? editorStates.get(card) : null;
        const inlineCaption = event.target instanceof Element
            ? event.target.closest('.is-inline-caption-entry')
            : null;
        if (
            state
            && inlineCaption instanceof HTMLElement
            && (event.key === 'Enter' || event.key === ' ')
        ) {
            event.preventDefault();
            beginInlineMediaCaption(state, inlineCaption);
            focusInlineMediaCaption(state, inlineCaption);
            return;
        }
        if (state && keepCaretInTrailingEmptyParagraph(event, state)) {
            return;
        }
        if (state && moveFromBodyMediaBoundary(event, state)) {
            return;
        }
        if (state && handleEditingShortcut(event, state)) {
            return;
        }

        if (event.key !== 'Escape' || !card?.classList.contains('is-confirming')) {
            return;
        }
        event.preventDefault();
        closeConfirmation(card, true);
    }, false);

    document.addEventListener('copy', copyPendingMedia, false);
    document.addEventListener('cut', copyPendingMedia, false);
    document.addEventListener('paste', pastePendingMedia, false);
    document.addEventListener('paste', pasteMediaFiles, false);

    document.addEventListener('paste', pasteMultilineText, false);

    document.addEventListener('paste', (event) => {
        if (event.defaultPrevented) {
            return;
        }
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

    document.addEventListener('beforeinput', (event) => {
        const state = editorStates.get(cardFor(event.target));
        if (!state?.body.contains(event.target) || state.imageCaptionEditor || state.mediaCaptionEditors.size > 0) return;
        if (event.inputType === 'historyUndo' || event.inputType === 'historyRedo') {
            if (!state.history) return;
            event.preventDefault();
            state.history[event.inputType === 'historyUndo' ? 'undo' : 'redo']();
        } else {
            if (/^(insert|delete)/u.test(event.inputType)) protectSelectedMediaBoundary(state.body);
            state.history?.before(event.inputType);
        }
    }, false);

    document.addEventListener('beforeinput', moveInsertionBeforeMediaBoundary, false);

    document.addEventListener('beforeinput', collapseEmptyParagraphBesideMedia, false);

    document.addEventListener('beforeinput', expandCollapsedBoundaryParagraph, false);

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
            clearAiChangeMarks(state.body);
            normalizeMediaBodyStructure(state.body);
            state.body.querySelectorAll('p.post-editor-collapsed-boundary-paragraph').forEach((paragraph) => {
                if (!editorBoundaryParagraphIsEmpty(paragraph)) {
                    paragraph.classList.remove('post-editor-collapsed-boundary-paragraph');
                }
            });
            collapseEmptyLeadingParagraphAfterDelete(event, state.body);
            state.history?.record(event.inputType);
        }
        if (event.target === state.dateInput) {
            state.dateDirty = true;
            updateDatePreview(state);
        }
        clearError(card.querySelector(':scope > .post-inplace-edit-form'));
        clearStatus(card);
        syncBoundaryCaret();
    }, false);

    document.addEventListener('selectionchange', syncBoundaryCaret, false);
    document.addEventListener('focusin', (event) => {
        document.querySelectorAll('.post-inplace-tools.is-menu-open').forEach((tools) => {
            if (!(event.target instanceof Node) || !tools.contains(event.target)) {
                closePostToolsMenu(tools, false);
            }
        });
    }, false);
    document.addEventListener('focusin', syncBoundaryCaret, false);
    document.addEventListener('focusout', () => window.setTimeout(syncBoundaryCaret, 0), false);

    window.addEventListener('resize', () => {
        document.querySelectorAll('.post-card.is-editing').forEach((card) => {
            const state = editorStates.get(card);
            if (state?.contextMenu) {
                closeContextMenu(state, false);
            }
        });
        syncBoundaryCaret();
    }, false);

    window.addEventListener('beforeunload', (event) => {
        document.querySelectorAll('.post-card.is-editing').forEach((card) => {
            const state = editorStates.get(card);
            if (state && editorHasUnsavedChanges(state) && !state.recovery?.persist()) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    });

    window.addEventListener('pagehide', (event) => {
        recoverySessions.forEach(state => state.recovery?.persist());
        if (event.persisted) {
            return;
        }
        document.querySelectorAll('.post-card.is-editing').forEach((card) => {
            const state = editorStates.get(card);
            if (state) {
                if (!state.recovery?.hasStored()) releasePendingMedia(state);
            }
        });
    }, false);

    document.addEventListener('register:fragment-updated', (event) => {
        applyShortcutHints(event.detail?.root || document);
        openEditorFromUrl();
        refreshPostRecoveryOffers();
    }, false);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') recoverySessions.forEach(state => state.recovery?.persist());
    });
    document.addEventListener('register:navigation-will-update', () => {
        recoverySessions.forEach(state => {
            if (state.recovery?.stop()) state.uploadedMediaIds.clear();
        });
    });
    window.addEventListener('storage', () => refreshPostRecoveryOffers());

    function openEditorFromUrl() {
        if (!window.location?.href) {
            return;
        }
        const url = new URL(window.location.href);
        const action = url.searchParams.get('editor');
        if (action !== 'new' && action !== 'edit') {
            return;
        }
        const button = document.querySelector(action === 'new'
            ? '.post-create-start'
            : '.post-card.is-manageable .post-edit-start');
        if (!button || button.disabled || button.hidden) {
            return;
        }
        // Consume the one-shot request before opening the existing editor.
        // Reload and history navigation must never start a second creation.
        // Keep the original query spelling: some installations route posts
        // through a prefix such as index.php?/post, not through rewrite rules.
        url.search = url.search.slice(1).split('&').filter((part) => {
            try {
                return decodeURIComponent(part.split('=', 1)[0].replace(/\+/gu, ' ')) !== 'editor';
            } catch (_error) {
                return true;
            }
        }).join('&');
        window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
        button.click();
    }

    applyShortcutHints(document);
    openEditorFromUrl();
    refreshPostRecoveryOffers();
})();
