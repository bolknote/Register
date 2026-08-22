(function (document, window) {
    'use strict';

    if (!document || !window || window.RegisterCommentEditor) {
        return;
    }

    const states = new WeakMap();
    const maximumFormulaLength = 10000;
    const aliases = new Map([
        ['B', 'strong'],
        ['DEL', 's'],
        ['DIV', 'p'],
        ['H1', 'p'],
        ['H2', 'p'],
        ['H3', 'p'],
        ['H4', 'p'],
        ['H5', 'p'],
        ['H6', 'p'],
        ['I', 'em'],
        ['STRIKE', 's'],
    ]);
    const allowedTags = new Set([
        'a', 'blockquote', 'br', 'code', 'em', 'li', 'ol', 'p', 'pre', 's', 'strong', 'ul',
    ]);
    const droppedTags = new Set([
        'AUDIO', 'BUTTON', 'CANVAS', 'EMBED', 'FORM', 'IFRAME', 'IMG', 'INPUT', 'MATH', 'OBJECT',
        'OPTION', 'SCRIPT', 'SELECT', 'SOURCE', 'STYLE', 'SVG', 'TEMPLATE', 'TEXTAREA', 'TRACK', 'VIDEO',
    ]);
    const toggleCommands = new Set([
        'bold', 'italic', 'strikeThrough', 'insertUnorderedList', 'insertOrderedList',
    ]);

    function normalizeLink(value) {
        const href = String(value || '').trim();
        if (
            href === ''
            || href.startsWith('//')
            || href.includes('\\')
            || /[\u0000-\u0020\u007f]/u.test(href)
        ) {
            return null;
        }

        const scheme = href.match(/^([a-z][a-z0-9+.-]*):/iu)?.[1]?.toLowerCase();
        if (scheme && !['http', 'https', 'mailto'].includes(scheme)) {
            return null;
        }

        return href;
    }

    function appendSanitizedNode(node, output) {
        if (node.nodeType === Node.TEXT_NODE) {
            output.appendChild(document.createTextNode(node.nodeValue || ''));
            return;
        }
        if (!(node instanceof Element) || droppedTags.has(node.tagName)) {
            return;
        }

        const tag = aliases.get(node.tagName) || node.tagName.toLowerCase();
        if (!allowedTags.has(tag)) {
            Array.from(node.childNodes).forEach((child) => appendSanitizedNode(child, output));
            return;
        }

        const clean = document.createElement(tag);
        if (tag === 'a') {
            const href = normalizeLink(node.getAttribute('href'));
            if (!href) {
                Array.from(node.childNodes).forEach((child) => appendSanitizedNode(child, output));
                return;
            }
            clean.setAttribute('href', href);
            clean.setAttribute('rel', 'nofollow ugc');
        }
        Array.from(node.childNodes).forEach((child) => appendSanitizedNode(child, clean));
        output.appendChild(clean);
    }

    function sanitizeHtml(html) {
        const input = document.createElement('template');
        input.innerHTML = String(html || '');
        const output = document.createElement('div');
        Array.from(input.content.childNodes).forEach((node) => appendSanitizedNode(node, output));

        return output.innerHTML;
    }

    function plainTextHtml(text) {
        const output = document.createElement('div');
        String(text || '').replace(/\r\n?/gu, '\n').split('\n').forEach((line, index) => {
            if (index > 0) {
                output.appendChild(document.createElement('br'));
            }
            output.appendChild(document.createTextNode(line));
        });

        return output.innerHTML;
    }

    function sourceHtml(value) {
        const source = String(value || '');
        if (/<\/?[a-z][^>]*>|&(?:amp|apos|gt|lt|nbsp|quot|#\d+|#x[\da-f]+);/iu.test(source)) {
            return sanitizeHtml(source);
        }

        return plainTextHtml(source);
    }

    function rangeInside(surface, range) {
        return surface.contains(range.startContainer)
            && surface.contains(range.endContainer)
            && surface.contains(range.commonAncestorContainer);
    }

    function currentRange(state) {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            return null;
        }
        const range = selection.getRangeAt(0);

        return rangeInside(state.surface, range) ? range : null;
    }

    function rememberSelection(state) {
        const range = currentRange(state);
        if (range) {
            state.range = range.cloneRange();
        }
    }

    function restoreSelection(state, range) {
        const target = range || state.range;
        if (!target || !rangeInside(state.surface, target)) {
            return false;
        }

        state.surface.focus({preventScroll: true});
        const selection = window.getSelection();
        if (!selection) {
            return false;
        }
        selection.removeAllRanges();
        selection.addRange(target);
        return true;
    }

    function setCaret(node, offset) {
        const range = document.createRange();
        range.setStart(node, Math.max(0, Math.min(offset, node.nodeValue?.length || 0)));
        range.collapse(true);
        const selection = window.getSelection();
        if (!selection) {
            return;
        }
        selection.removeAllRanges();
        selection.addRange(range);
    }

    function setCaretBeside(node, after) {
        const range = document.createRange();
        if (after) {
            range.setStartAfter(node);
        } else {
            range.setStartBefore(node);
        }
        range.collapse(true);
        const selection = window.getSelection();
        if (!selection) {
            return;
        }
        selection.removeAllRanges();
        selection.addRange(range);
    }

    function findDelimiter(text, offset) {
        let delimiter = text.indexOf('$$', offset);
        while (delimiter !== -1) {
            let slashCount = 0;
            for (let index = delimiter - 1; index >= 0 && text[index] === '\\'; index--) {
                slashCount++;
            }
            if (slashCount % 2 === 0) {
                return delimiter;
            }
            delimiter = text.indexOf('$$', delimiter + 2);
        }

        return -1;
    }

    function formulaParts(text) {
        const parts = [];
        let cursor = 0;
        let start = findDelimiter(text, cursor);
        while (start !== -1) {
            const closing = findDelimiter(text, start + 2);
            if (closing === -1) {
                break;
            }
            const end = closing + 2;
            if (start > cursor) {
                parts.push({type: 'text', start: cursor, end: start, value: text.slice(cursor, start)});
            }
            parts.push({
                type: 'formula',
                start: start,
                end: end,
                value: text.slice(start, end),
                formula: text.slice(start + 2, closing),
            });
            cursor = end;
            start = findDelimiter(text, cursor);
        }
        if (cursor < text.length) {
            parts.push({type: 'text', start: cursor, end: text.length, value: text.slice(cursor)});
        }

        return parts;
    }

    function formulaTextNode(node, surface) {
        if (!node.nodeValue?.includes('$$')) {
            return false;
        }
        const parent = node.parentElement;

        return parent instanceof Element
            && !parent.closest('code, pre, .comment-editor-formula')
            && surface.contains(parent);
    }

    function createFormula(part) {
        const formula = document.createElement('span');
        formula.className = 'comment-editor-formula register-math-source';
        formula.setAttribute('contenteditable', 'false');
        formula.setAttribute('data-comment-formula-source', part.value);
        formula.setAttribute('data-register-math', part.formula);
        formula.textContent = part.value;

        return formula;
    }

    function renderCompleteFormulas(state) {
        const selection = window.getSelection();
        const activeRange = selection?.rangeCount === 1 && selection.getRangeAt(0).collapsed
            ? selection.getRangeAt(0)
            : null;
        const walker = document.createTreeWalker(state.surface, NodeFilter.SHOW_TEXT);
        const nodes = [];
        let node;
        while ((node = walker.nextNode())) {
            if (formulaTextNode(node, state.surface)) {
                nodes.push(node);
            }
        }

        let rendered = false;
        nodes.forEach((textNode) => {
            const text = textNode.nodeValue || '';
            const parts = formulaParts(text);
            if (!parts.some((part) => part.type === 'formula')) {
                return;
            }

            const caretOffset = activeRange?.startContainer === textNode ? activeRange.startOffset : null;
            const fragment = document.createDocumentFragment();
            let caretText = null;
            let caretTextOffset = 0;
            let caretFormula = null;
            let caretAfterFormula = false;
            let transformed = false;

            parts.forEach((part) => {
                const editingThisFormula = part.type === 'formula'
                    && caretOffset !== null
                    && caretOffset > part.start
                    && caretOffset < part.end;
                const renderable = part.type === 'formula'
                    && !editingThisFormula
                    && part.formula.trim() !== ''
                    && part.formula.length <= maximumFormulaLength;

                if (!renderable) {
                    const plain = document.createTextNode(part.value);
                    fragment.appendChild(plain);
                    if (caretOffset !== null && caretOffset >= part.start && caretOffset <= part.end) {
                        caretText = plain;
                        caretTextOffset = caretOffset - part.start;
                    }
                    return;
                }

                const formula = createFormula(part);
                fragment.appendChild(formula);
                transformed = true;
                if (caretOffset === part.start) {
                    caretFormula = formula;
                    caretAfterFormula = false;
                } else if (caretOffset === part.end) {
                    caretFormula = formula;
                    caretAfterFormula = true;
                }
            });

            if (!transformed || !textNode.parentNode) {
                return;
            }
            textNode.parentNode.replaceChild(fragment, textNode);
            rendered = true;
            if (caretText) {
                setCaret(caretText, caretTextOffset);
            } else if (caretFormula) {
                setCaretBeside(caretFormula, caretAfterFormula);
            }
        });

        if (rendered && typeof window.RegisterMath?.render === 'function') {
            window.RegisterMath.render(state.surface).catch(() => {});
        }
    }

    function syncSource(state) {
        const clone = state.surface.cloneNode(true);
        clone.querySelectorAll('[data-comment-formula-source]').forEach((formula) => {
            formula.replaceWith(document.createTextNode(formula.getAttribute('data-comment-formula-source') || ''));
        });
        const text = (clone.textContent || '').replace(/\u00a0/gu, ' ').trim();
        state.source.value = text === '' ? '' : sanitizeHtml(clone.innerHTML);
    }

    function deepestNode(node, backwards) {
        let current = node;
        while (current?.childNodes?.length) {
            current = backwards ? current.lastChild : current.firstChild;
        }

        return current;
    }

    function adjacentLeaf(container, offset, backwards, root) {
        let current = container;
        if (current.nodeType === Node.TEXT_NODE) {
            if ((backwards && offset > 0) || (!backwards && offset < (current.nodeValue?.length || 0))) {
                return null;
            }
        } else if (current.nodeType === Node.ELEMENT_NODE) {
            const index = backwards ? offset - 1 : offset;
            if (index >= 0 && index < current.childNodes.length) {
                return deepestNode(current.childNodes[index], backwards);
            }
        }

        while (current && current !== root) {
            const sibling = backwards ? current.previousSibling : current.nextSibling;
            if (sibling) {
                return deepestNode(sibling, backwards);
            }
            current = current.parentNode;
        }

        return null;
    }

    function adjacentFormula(state, backwards) {
        const range = currentRange(state);
        if (!range?.collapsed) {
            return null;
        }
        const leaf = adjacentLeaf(range.startContainer, range.startOffset, backwards, state.surface);
        const element = leaf instanceof Element ? leaf : leaf?.parentElement;
        const formula = element?.closest('.comment-editor-formula');

        return formula instanceof HTMLElement && state.surface.contains(formula) ? formula : null;
    }

    function revealFormula(state, formula, mode, caretAtEnd) {
        const original = formula.getAttribute('data-comment-formula-source') || '';
        let source = original;
        if (mode === 'backspace') {
            source = source.slice(0, -1);
            caretAtEnd = true;
        } else if (mode === 'delete') {
            source = source.slice(1);
            caretAtEnd = false;
        }
        const text = document.createTextNode(source);
        formula.replaceWith(text);
        setCaret(text, caretAtEnd ? source.length : 0);
        state.range = window.getSelection()?.rangeCount ? window.getSelection().getRangeAt(0).cloneRange() : null;
        syncSource(state);
    }

    function activeLink(state) {
        const range = state.range;
        if (!range || !rangeInside(state.surface, range)) {
            return null;
        }
        const node = range.commonAncestorContainer;
        const element = node instanceof Element ? node : node.parentElement;
        const link = element?.closest('a');

        return link instanceof HTMLAnchorElement && state.surface.contains(link) ? link : null;
    }

    function closeLinkPanel(state, restore) {
        state.linkPanel.hidden = true;
        state.linkPanel.classList.remove('is-invalid');
        if (restore) {
            restoreSelection(state);
        }
    }

    function openLinkPanel(state) {
        rememberSelection(state);
        if (!state.range) {
            state.surface.focus();
            rememberSelection(state);
        }
        const link = activeLink(state);
        state.linkInput.value = link?.getAttribute('href') || '';
        state.linkRemove.hidden = !link;
        state.linkPanel.hidden = false;
        state.linkPanel.classList.remove('is-invalid');
        state.linkInput.focus();
        state.linkInput.select();
    }

    function applyLink(state) {
        const href = normalizeLink(state.linkInput.value);
        if (!href) {
            state.linkPanel.classList.add('is-invalid');
            state.linkInput.focus();
            return;
        }

        const link = activeLink(state);
        if (link) {
            link.setAttribute('href', href);
            link.setAttribute('rel', 'nofollow ugc');
        } else if (restoreSelection(state)) {
            const range = currentRange(state);
            if (range?.collapsed) {
                const anchor = document.createElement('a');
                anchor.setAttribute('href', href);
                anchor.setAttribute('rel', 'nofollow ugc');
                anchor.textContent = href;
                range.insertNode(anchor);
                setCaretBeside(anchor, true);
            } else {
                document.execCommand('createLink', false, href);
                const created = activeLink(state);
                created?.setAttribute('rel', 'nofollow ugc');
            }
        }
        closeLinkPanel(state, false);
        rememberSelection(state);
        syncSource(state);
    }

    function removeLink(state) {
        const link = activeLink(state);
        if (!link) {
            closeLinkPanel(state, true);
            return;
        }
        const range = document.createRange();
        range.selectNodeContents(link);
        state.range = range;
        if (restoreSelection(state, range)) {
            document.execCommand('unlink', false);
        }
        closeLinkPanel(state, false);
        rememberSelection(state);
        syncSource(state);
    }

    function runCommand(state, button) {
        const command = button.getAttribute('data-comment-command') || '';
        if (command === 'link') {
            openLinkPanel(state);
            return;
        }
        if (!restoreSelection(state)) {
            state.surface.focus();
        }
        let value = button.getAttribute('data-comment-command-value');
        if (command === 'formatBlock' && String(document.queryCommandValue('formatBlock')).toLowerCase() === value) {
            value = 'p';
        }
        document.execCommand(command, false, value);
        renderCompleteFormulas(state);
        syncSource(state);
        rememberSelection(state);
        updateToolbar(state);
    }

    function updateToolbar(state) {
        state.toolbar.querySelectorAll('[data-comment-command]').forEach((button) => {
            const command = button.getAttribute('data-comment-command') || '';
            if (!toggleCommands.has(command)) {
                return;
            }
            let active = false;
            try {
                active = document.queryCommandState(command);
            } catch (_error) {
                active = false;
            }
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }

    function updateShortcutTitles(state) {
        const modifier = /Mac|iPhone|iPad|iPod/u.test(window.navigator.platform) ? '⌘' : 'Ctrl+';
        const shortcuts = {
            bold: modifier + 'B',
            italic: modifier + 'I',
            strikeThrough: modifier + 'Shift+X',
            link: modifier + 'K',
        };
        Object.entries(shortcuts).forEach(([command, shortcut]) => {
            const button = state.toolbar.querySelector('[data-comment-command="' + command + '"]');
            if (!button) {
                return;
            }
            const title = button.getAttribute('title') || '';
            button.setAttribute('title', title + ' — ' + shortcut);
        });
    }

    function insertPastedContent(state, event) {
        const html = event.clipboardData?.getData('text/html') || '';
        const text = event.clipboardData?.getData('text/plain') || '';
        event.preventDefault();
        if (html !== '') {
            const clean = sanitizeHtml(html);
            if (clean !== '') {
                document.execCommand('insertHTML', false, clean);
            } else {
                document.execCommand('insertText', false, text);
            }
        } else {
            document.execCommand('insertText', false, text);
        }
        renderCompleteFormulas(state);
        syncSource(state);
    }

    function handleKeydown(state, event) {
        const modifier = event.ctrlKey || event.metaKey;
        if (modifier && !event.altKey) {
            const key = event.key.toLowerCase();
            const shortcut = key === 'b' ? 'bold'
                : key === 'i' ? 'italic'
                    : key === 'k' ? 'link'
                        : key === 'x' && event.shiftKey ? 'strikeThrough'
                            : null;
            if (shortcut) {
                event.preventDefault();
                const button = state.toolbar.querySelector('[data-comment-command="' + shortcut + '"]');
                if (button) {
                    rememberSelection(state);
                    runCommand(state, button);
                }
                return;
            }
            if (event.key === 'Enter') {
                event.preventDefault();
                syncSource(state);
                state.form?.requestSubmit();
                return;
            }
        }

        const backwards = event.key === 'Backspace' || event.key === 'ArrowLeft';
        const forwards = event.key === 'Delete' || event.key === 'ArrowRight';
        if (!backwards && !forwards) {
            return;
        }
        const formula = adjacentFormula(state, backwards);
        if (!formula) {
            return;
        }
        event.preventDefault();
        revealFormula(
            state,
            formula,
            event.key === 'Backspace' ? 'backspace' : event.key === 'Delete' ? 'delete' : 'reveal',
            backwards,
        );
    }

    function enhanceEditor(root) {
        if (!(root instanceof HTMLElement) || root.dataset.commentEditorReady === '1') {
            return;
        }
        const source = root.querySelector(':scope > .comment-editor-source');
        const surface = root.querySelector(':scope > .comment-editor-surface');
        const toolbar = root.querySelector(':scope > .comment-editor-toolbar');
        const linkPanel = root.querySelector(':scope > .comment-editor-link-panel');
        const linkInput = linkPanel?.querySelector('[data-comment-link-input]');
        const linkRemove = linkPanel?.querySelector('[data-comment-link-remove]');
        if (
            !(source instanceof HTMLTextAreaElement)
            || !(surface instanceof HTMLElement)
            || !(toolbar instanceof HTMLElement)
            || !(linkPanel instanceof HTMLElement)
            || !(linkInput instanceof HTMLInputElement)
            || !(linkRemove instanceof HTMLButtonElement)
        ) {
            return;
        }

        const controller = new AbortController();
        const state = {
            root,
            source,
            surface,
            toolbar,
            linkPanel,
            linkInput,
            linkRemove,
            form: root.closest('form'),
            range: null,
            controller,
        };
        states.set(root, state);
        root.dataset.commentEditorReady = '1';
        surface.innerHTML = sourceHtml(source.value);
        source.hidden = true;
        toolbar.hidden = false;
        surface.hidden = false;
        document.execCommand('defaultParagraphSeparator', false, 'p');
        updateShortcutTitles(state);
        renderCompleteFormulas(state);
        syncSource(state);

        toolbar.addEventListener('pointerdown', (event) => {
            if (event.target.closest('button')) {
                event.preventDefault();
            }
        }, {signal: controller.signal});
        toolbar.addEventListener('click', (event) => {
            const button = event.target.closest('[data-comment-command]');
            if (button instanceof HTMLButtonElement) {
                runCommand(state, button);
            }
        }, {signal: controller.signal});
        surface.addEventListener('focus', () => rememberSelection(state), {signal: controller.signal});
        surface.addEventListener('input', () => {
            renderCompleteFormulas(state);
            syncSource(state);
            rememberSelection(state);
            updateToolbar(state);
        }, {signal: controller.signal});
        surface.addEventListener('keydown', (event) => handleKeydown(state, event), {signal: controller.signal});
        surface.addEventListener('paste', (event) => insertPastedContent(state, event), {signal: controller.signal});
        surface.addEventListener('drop', (event) => {
            event.preventDefault();
            const text = event.dataTransfer?.getData('text/plain') || '';
            if (text !== '') {
                document.execCommand('insertText', false, text);
            }
        }, {signal: controller.signal});
        surface.addEventListener('pointerdown', (event) => {
            const formula = event.target.closest('.comment-editor-formula');
            if (!(formula instanceof HTMLElement)) {
                return;
            }
            event.preventDefault();
            const rectangle = formula.getBoundingClientRect();
            revealFormula(state, formula, 'reveal', event.clientX >= rectangle.left + rectangle.width / 2);
        }, {signal: controller.signal});

        linkPanel.querySelector('[data-comment-link-apply]')?.addEventListener('click', () => applyLink(state), {signal: controller.signal});
        linkRemove.addEventListener('click', () => removeLink(state), {signal: controller.signal});
        linkPanel.querySelector('[data-comment-link-cancel]')?.addEventListener('click', () => closeLinkPanel(state, true), {signal: controller.signal});
        linkInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                applyLink(state);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                closeLinkPanel(state, true);
            }
        }, {signal: controller.signal});
        state.form?.addEventListener('submit', () => syncSource(state), {signal: controller.signal});
        document.addEventListener('selectionchange', () => {
            if (currentRange(state)) {
                rememberSelection(state);
                updateToolbar(state);
            }
        }, {signal: controller.signal});
        document.addEventListener('pointerdown', (event) => {
            if (!linkPanel.hidden && !root.contains(event.target)) {
                closeLinkPanel(state, false);
            }
        }, {signal: controller.signal});
    }

    function enhance(root) {
        const scope = root && typeof root.querySelectorAll === 'function' ? root : document;
        if (scope instanceof HTMLElement && scope.matches('[data-comment-editor]')) {
            enhanceEditor(scope);
        }
        scope.querySelectorAll('[data-comment-editor]').forEach(enhanceEditor);
    }

    function destroy(root) {
        const scope = root && typeof root.querySelectorAll === 'function' ? root : document;
        const editors = [];
        if (scope instanceof HTMLElement && scope.matches('[data-comment-editor]')) {
            editors.push(scope);
        }
        scope.querySelectorAll('[data-comment-editor]').forEach((editor) => editors.push(editor));
        editors.forEach((editor) => {
            const state = states.get(editor);
            if (!state) {
                return;
            }
            syncSource(state);
            state.controller.abort();
            states.delete(editor);
            delete editor.dataset.commentEditorReady;
        });
    }

    function focus(target) {
        const root = target?.matches?.('[data-comment-editor]')
            ? target
            : target?.querySelector?.('[data-comment-editor]');
        const state = root ? states.get(root) : null;
        if (!state) {
            return false;
        }
        state.surface.focus();
        const range = document.createRange();
        range.selectNodeContents(state.surface);
        range.collapse(false);
        const selection = window.getSelection();
        selection?.removeAllRanges();
        selection?.addRange(range);
        rememberSelection(state);
        return true;
    }

    window.RegisterCommentEditor = {enhance, destroy, focus};

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => enhance(document), {once: true});
    } else {
        enhance(document);
    }
    document.addEventListener('register:fragment-updated', (event) => {
        enhance(event.detail?.root || document);
    });
})(document, window);
