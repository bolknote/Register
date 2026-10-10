/* Public editor boundaries: dependencies are supplied by the entry module. */
(() => {
    'use strict';

    function create({rangeIsInside, editorStates}) {
        function clearBoundaryCaret(element) {
            element.classList.remove('has-leading-boundary-caret');
        }

        function clearSyntheticBoundaryCaret(element) {
            element.classList.remove('uses-synthetic-boundary-caret');
        }

        function boundaryNodeIsEmpty(node) {
            return node instanceof HTMLBRElement
                || (node.nodeType === Node.TEXT_NODE && String(node.textContent || '').trim() === '');
        }

        function isMediaBoundaryElement(body, element) {
            return element instanceof HTMLElement
                && element.matches('.post-picture, .post-media-picture, figure')
                && body.contains(element)
                && Boolean(element.querySelector('img, video, audio'));
        }

        function editorBoundaryParagraphIsEmpty(element) {
            return element instanceof HTMLElement
                && element.tagName === 'P'
                && Array.from(element.childNodes).every(boundaryNodeIsEmpty);
        }

        function topLevelBodyChild(body, node) {
            if (!(body instanceof HTMLElement) || !(node instanceof Node) || node === body) {
                return null;
            }

            let child = node instanceof HTMLElement ? node : node.parentElement;
            while (child instanceof HTMLElement && child.parentElement !== body) {
                if (child === body || !body.contains(child)) {
                    return null;
                }
                child = child.parentElement;
            }
            return child instanceof HTMLElement && child.parentElement === body ? child : null;
        }

        function hoistMediaFromParagraph(body, media) {
            const paragraph = media.parentElement;
            if (
                !(paragraph instanceof HTMLElement)
                || paragraph.tagName !== 'P'
                || paragraph.parentElement !== body
            ) {
                return;
            }

            const trailing = document.createElement('p');
            while (media.nextSibling) {
                trailing.append(media.nextSibling);
            }
            body.insertBefore(media, paragraph.nextSibling);
            if (!editorBoundaryParagraphIsEmpty(trailing)) {
                body.insertBefore(trailing, media.nextSibling);
            }
            if (editorBoundaryParagraphIsEmpty(paragraph)) {
                paragraph.remove();
            }
        }

        function normalizeLeadingNestedMedia(body, expectedMedia = null) {
            if (expectedMedia instanceof HTMLElement) {
                hoistMediaFromParagraph(body, expectedMedia);
                return;
            }

            for (const child of Array.from(body.childNodes)) {
                if (boundaryNodeIsEmpty(child)) {
                    continue;
                }
                if (!(child instanceof HTMLElement) || child.tagName !== 'P') {
                    return;
                }
                const nestedMedia = Array.from(child.childNodes).find((nested, index, siblings) => (
                    isMediaBoundaryElement(body, nested)
                    && siblings.slice(0, index).every(boundaryNodeIsEmpty)
                ));
                if (nestedMedia instanceof HTMLElement) {
                    hoistMediaFromParagraph(body, nestedMedia);
                }
                return;
            }
        }

        function leadingMediaIndex(body, expectedMedia) {
            normalizeLeadingNestedMedia(body, expectedMedia);
            if (!(expectedMedia instanceof HTMLElement) || expectedMedia.parentElement !== body) {
                return -1;
            }

            const children = Array.from(body.childNodes);
            const index = children.indexOf(expectedMedia);
            return index >= 0 && children.slice(0, index).every(node => boundaryNodeIsEmpty(node)
                || (editorBoundaryParagraphIsEmpty(node)
                    && node.classList.contains('post-editor-collapsed-boundary-paragraph')))
                ? index
                : -1;
        }

        function prepareMediaInsertionRange(body, range) {
            if (!range.collapsed) {
                return range;
            }

            const boundary = topLevelBodyChild(body, range.startContainer);
            if (isMediaBoundaryElement(body, boundary)) {
                range.setStartAfter(boundary);
                range.collapse(true);
                return range;
            }

            if (editorBoundaryParagraphIsEmpty(boundary)) {
                const boundaryIndex = Array.from(body.childNodes).indexOf(boundary);
                boundary.remove();
                range.setStart(body, boundaryIndex);
                range.collapse(true);
                return range;
            }

            // Block media cannot remain inside a paragraph, heading or code block
            // (including their inline formatting). Split that text block, retaining
            // any surrounding quote/list, before inserting the attachment beside it.
            const caretElement = range.startContainer instanceof Element
                ? range.startContainer : range.startContainer.parentElement;
            const block = caretElement?.closest('p, h1, h2, h3, h4, h5, h6, pre');
            if (block instanceof HTMLElement && body.contains(block)) {
                const parent = block.parentNode;
                const suffixRange = range.cloneRange();
                suffixRange.setEnd(block, block.childNodes.length);

                const prefix = block;
                const suffix = block.cloneNode(false);
                // Move the original descendants on either side of the caret. Image
                // descriptions and upload callbacks retain references to those nodes.
                suffix.append(suffixRange.extractContents());
                const hasContent = (element) => {
                    const text = String(element.textContent || '');
                    return (element.tagName === 'PRE' ? text : text.trim()) !== ''
                        || Boolean(element.querySelector('img, video, audio, iframe, table, hr'));
                };
                const keepPrefix = hasContent(prefix);
                const keepSuffix = hasContent(suffix);
                // At the start of a block only the suffix survives; it must keep
                // the original anchor. A split with two halves must not duplicate it.
                if (keepPrefix) suffix.removeAttribute('id');
                const boundaryIndex = Array.from(parent.childNodes).indexOf(block);
                if (keepSuffix) {
                    block.after(suffix);
                }
                if (!keepPrefix) {
                    block.remove();
                }
                range.setStart(parent, boundaryIndex + (keepPrefix ? 1 : 0));
                range.collapse(true);
                return range;
            }

            let paragraph = range.startContainer instanceof HTMLElement
                ? range.startContainer
                : range.startContainer.parentNode;
            while (paragraph instanceof HTMLElement && paragraph.parentNode !== body) {
                paragraph = paragraph.parentNode;
            }
            if (!editorBoundaryParagraphIsEmpty(paragraph) || paragraph.parentNode !== body) {
                return range;
            }

            const index = Array.from(body.childNodes).indexOf(paragraph);
            paragraph.remove();
            range.setStart(body, index);
            range.collapse(true);
            return range;
        }

        function focusBeforeLeadingMedia(body, expectedMedia) {
            const index = leadingMediaIndex(body, expectedMedia);
            if (index < 0) {
                return null;
            }
            return focusBeforeMedia(body, expectedMedia);
        }

        function focusBeforeMedia(body, media) {
            const selection = window.getSelection();
            if (!isMediaBoundaryElement(body, media) || !selection) {
                return null;
            }

            body.focus({preventScroll: true});
            const range = document.createRange();
            let paragraph = media.previousSibling;
            if (media.getAttribute('contenteditable') === 'false' && !editorBoundaryParagraphIsEmpty(paragraph)) {
                // Before a noneditable upload Chromium can paint a root caret
                // but emit no text input at all. Give it a real editable block,
                // collapsed until input so navigation adds no content or gap.
                paragraph = document.createElement('p');
                paragraph.className = 'post-editor-body-paragraph post-editor-collapsed-boundary-paragraph';
                paragraph.append(document.createElement('br'));
                media.parentNode.insertBefore(paragraph, media);
            }
            if (editorBoundaryParagraphIsEmpty(paragraph)) {
                range.selectNodeContents(paragraph);
            } else {
                range.setStartBefore(media);
            }
            range.collapse(true);
            selection.removeAllRanges();
            selection.addRange(range);
            syncBoundaryCaret();
            return media;
        }

        function mediaBesideCaret(body, range, direction) {
            if (!range.collapsed || !body.contains(range.startContainer)) {
                return null;
            }
            const element = range.startContainer instanceof Element
                ? range.startContainer : range.startContainer.parentElement;
            const block = element?.closest('p, h1, h2, h3, h4, h5, h6, pre');
            if (!(block instanceof HTMLElement) || !body.contains(block)
                || element.closest('.post-caption, figcaption, .post-media-picture, .post-picture')) {
                return null;
            }
            let sibling = direction < 0 ? block.previousSibling : block.nextSibling;
            while (sibling && boundaryNodeIsEmpty(sibling)) {
                sibling = direction < 0 ? sibling.previousSibling : sibling.nextSibling;
            }
            if (!isMediaBoundaryElement(body, sibling)) {
                return null;
            }
            if (editorBoundaryParagraphIsEmpty(block)) {
                return sibling;
            }

            // Only cross the attachment from the adjoining visual line. An up
            // key in the middle of a wrapped paragraph must still move one line,
            // not jump past all of its text to the preceding picture.
            const caret = Array.from(range.getClientRects()).find(rect => rect.height > 0);
            const remaining = document.createRange();
            remaining.selectNodeContents(block);
            if (direction < 0) {
                remaining.setEnd(range.startContainer, range.startOffset);
            } else {
                remaining.setStart(range.startContainer, range.startOffset);
            }
            if (!caret) {
                return remaining.toString() === '' ? sibling : null;
            }
            const hasAnotherLine = Array.from(remaining.getClientRects()).some(rect => rect.height > 0 && (
                direction < 0 ? rect.bottom < caret.bottom - caret.height / 2
                    : rect.top > caret.top + caret.height / 2
            ));
            return hasAnotherLine ? null : sibling;
        }

        function revealBoundaryCaret(body, changedMedia = null) {
            if (document.activeElement !== body) {
                return;
            }
            const selection = window.getSelection();
            const range = selection?.rangeCount === 1 ? selection.getRangeAt(0) : null;
            if (changedMedia) {
                if (!range?.collapsed || !body.contains(changedMedia)) {
                    return;
                }
                const element = range.startContainer instanceof Element
                    ? range.startContainer : range.startContainer.parentElement;
                const paragraph = element?.closest('p');
                const atEmptyLine = editorBoundaryParagraphIsEmpty(paragraph)
                    && (paragraph.previousSibling === changedMedia || paragraph.nextSibling === changedMedia);
                if (!atEmptyLine && mediaBoundaryAtRange(body, range) !== changedMedia) {
                    // A background upload must not scroll the user away from a
                    // different paragraph, another field, or a text selection.
                    return;
                }
                syncBoundaryCaret();
            }
            const painted = Array.from(document.querySelectorAll('.has-leading-boundary-caret'))
                .find(element => body.contains(element));
            let rect;
            if (painted instanceof HTMLElement) {
                const box = painted.getBoundingClientRect();
                const style = getComputedStyle(painted, '::before');
                const top = box.top + parseFloat(style.top);
                rect = {top, bottom: top + parseFloat(style.height)};
            } else if (range?.collapsed && body.contains(range.startContainer)) {
                rect = Array.from(range.getClientRects()).find(rect => rect.height > 0);
            }
            if (!rect) {
                return;
            }
            // Prevented arrow defaults do not scroll a programmatic Selection.
            // Reveal the caret itself, not the entire (possibly very tall) image.
            const top = (window.visualViewport?.offsetTop || 0) + 16;
            const bottom = top + (window.visualViewport?.height || window.innerHeight) - 32;
            const distance = rect.top < top ? rect.top - top : rect.bottom > bottom ? rect.bottom - bottom : 0;
            if (distance) {
                window.scrollBy({top: distance, behavior: 'instant'});
            }
        }

        function focusAfterMedia(body, media) {
            if (!(media instanceof HTMLElement)) {
                return null;
            }
            const mediaBoundary = node => isMediaBoundaryElement(body, node)
                || (node instanceof HTMLElement && body.contains(node) && node.matches('audio, .post-media-upload'));

            const boundary = topLevelBodyChild(body, media);
            if (mediaBoundary(boundary)) {
                media = boundary;
            } else if (!mediaBoundary(media)) {
                return null;
            }

            let target = media.nextSibling;
            if (
                !editorBoundaryParagraphIsEmpty(target)
                && (
                    !(target instanceof Node)
                    || boundaryNodeIsEmpty(target)
                    || mediaBoundary(target)
                )
            ) {
                const paragraph = document.createElement('p');
                paragraph.className = 'post-editor-body-paragraph';
                paragraph.append(document.createElement('br'));
                // A picture in a quote or list item needs an editable trailing line
                // in that same container, even while its upload is noneditable.
                media.parentNode.insertBefore(paragraph, media.nextSibling);
                target = paragraph;
            }
            if (!(target instanceof Node)) {
                return null;
            }
            if (target instanceof HTMLElement && target.tagName === 'P') {
                target.classList.add('post-editor-body-paragraph');
                // Leaving a caption is an explicit request to start a visible body
                // line. A boundary collapsed by an earlier Backspace/Delete must
                // therefore become a normal paragraph again immediately.
                target.classList.remove('post-editor-collapsed-boundary-paragraph');
            }
            const needsVisibleEmptyCaret = editorBoundaryParagraphIsEmpty(target)
                ? target
                : null;

            const selection = window.getSelection();
            if (!selection) {
                return null;
            }
            body.focus({preventScroll: true});
            const range = document.createRange();
            if (target.nodeType === Node.TEXT_NODE) {
                range.setStart(target, 0);
            } else {
                range.selectNodeContents(target);
                range.collapse(true);
            }
            selection.removeAllRanges();
            selection.addRange(range);
            if (needsVisibleEmptyCaret instanceof HTMLElement) {
                needsVisibleEmptyCaret.classList.add('has-leading-boundary-caret');
            }
            syncBoundaryCaret();
            return target;
        }

        function mediaBoundaryAtRange(body, range) {
            if (!range.collapsed || !body.contains(range.startContainer)) {
                return null;
            }

            const boundary = range.startContainer;
            if (boundary instanceof HTMLElement && !boundary.closest('.post-picture, .post-media-picture, figure')) {
                for (let index = range.startOffset; index < boundary.childNodes.length; ++index) {
                    const child = boundary.childNodes[index];
                    if (boundaryNodeIsEmpty(child)) {
                        continue;
                    }
                    return isMediaBoundaryElement(body, child) ? child : null;
                }
                return null;
            }
            const element = boundary instanceof Element ? boundary : boundary.parentElement;
            let media = null;
            for (let parent = element; parent instanceof HTMLElement && parent !== body; parent = parent.parentElement) {
                if (isMediaBoundaryElement(body, parent)) media = parent;
            }
            // The outer media block owns its caption, even when its visual has
            // another media wrapper. Typing must stay outside that entire block.
            if (!isMediaBoundaryElement(body, media)) {
                return null;
            }
            if (boundary !== media && String(boundary.textContent || '').trim() !== '') {
                // Existing prose in an older malformed media block still needs
                // editing at its chosen text offset; normalization moves that
                // original text node safely after input.
                return null;
            }

            // The same visual boundary can be represented at the wrapper, at
            // an image/link/picture child, or in leading formatting whitespace.
            // Recognize all of them before native Enter can split the media
            // wrapper and separate the image from its caption.
            let node = boundary;
            let offset = range.startOffset;
            while (node instanceof Node) {
                const emptyPrefix = node.nodeType === Node.TEXT_NODE
                    ? String(node.textContent || '').slice(0, offset).trim() === ''
                    : Array.from(node.childNodes).slice(0, offset).every(boundaryNodeIsEmpty);
                if (!emptyPrefix) {
                    return null;
                }
                if (node === media) {
                    return media;
                }
                const parent = node.parentNode;
                offset = Array.from(parent.childNodes).indexOf(node);
                node = parent;
            }
            return null;
        }

        function inlineCodeBoundaryMarkerAtRange(body, range) {
            if (!range.collapsed || !body.contains(range.startContainer)) return null;
            const text = range.startContainer;
            if (text.nodeType === Node.TEXT_NODE) {
                const after = range.startOffset === 0 && text.previousSibling instanceof HTMLElement
                    && text.previousSibling.tagName === 'TT';
                const before = range.startOffset === text.data.length && text.nextSibling instanceof HTMLElement
                    && text.nextSibling.tagName === 'TT';
                const code = after ? text.previousSibling : before ? text.nextSibling : null;
                if (code && !code.closest('pre, [contenteditable="false"]')) {
                    const marker = document.createElement('span');
                    marker.setAttribute('data-post-inline-code-exit', '');
                    marker.setAttribute('contenteditable', 'false');
                    marker.setAttribute('aria-hidden', 'true');
                    code.parentNode.insertBefore(marker, after ? text : code);
                }
                const leading = range.startOffset === text.data.length ? text.nextSibling : null;
                if (leading instanceof HTMLElement && leading.hasAttribute('data-post-inline-code-exit')
                    && leading.nextSibling instanceof HTMLElement && leading.nextSibling.tagName === 'TT') return leading;
                const trailing = range.startOffset === 0 ? text.previousSibling : null;
                return trailing instanceof HTMLElement && trailing.hasAttribute('data-post-inline-code-exit')
                    && trailing.previousSibling instanceof HTMLElement && trailing.previousSibling.tagName === 'TT'
                    ? trailing : null;
            }
            if (!(range.startContainer instanceof HTMLElement)) return null;
            const next = range.startContainer.childNodes[range.startOffset];
            const previous = range.startContainer.childNodes[range.startOffset - 1];
            if (next instanceof HTMLElement && next.hasAttribute('data-post-inline-code-exit')
                && next.nextSibling instanceof HTMLElement && next.nextSibling.tagName === 'TT') return next;
            return previous instanceof HTMLElement && previous.hasAttribute('data-post-inline-code-exit')
                && previous.previousSibling instanceof HTMLElement && previous.previousSibling.tagName === 'TT'
                ? previous : null;
        }

        function syncBoundaryCaret() {
            const selection = window.getSelection();
            const active = document.activeElement;
            let nextElement = null;

            // Clamp paragraph selections before native input caches its target
            // range. WebKit can ignore corrections made only in beforeinput.
            if (active instanceof HTMLElement
                && active.matches('.post-card.is-editing > .post.body[data-post-inplace-body]')) {
                protectSelectedMediaBoundary(active);
            }

            if (
                active instanceof HTMLElement
                && active.matches('.post-card.is-editing > .post.body[data-post-inplace-body]')
                && active.hasChildNodes()
                && selection
                && selection.rangeCount === 1
            ) {
                const range = selection.getRangeAt(0);
                if (range.collapsed) {
                    const inlineCodeMarker = inlineCodeBoundaryMarkerAtRange(active, range);
                    if (inlineCodeMarker) {
                        nextElement = inlineCodeMarker;
                    } else if (range.startContainer === active && range.startOffset === 0) {
                        nextElement = active;
                    } else {
                        nextElement = mediaBoundaryAtRange(active, range);
                        // Chromium and Firefox can keep a valid selection in an
                        // empty <p><br></p> without painting its native caret.
                        // This also happens when Enter creates the trailing line
                        // after an ordinary last paragraph. Reuse the synthetic
                        // caret for every active empty body paragraph, including
                        // gapless boundaries and paragraphs in a quote/list.
                        if (!nextElement) {
                            let paragraph = range.startContainer instanceof HTMLElement
                                ? range.startContainer
                                : range.startContainer.parentElement;
                            while (paragraph instanceof HTMLElement && paragraph !== active && paragraph.tagName !== 'P') {
                                paragraph = paragraph.parentElement;
                            }
                            if (
                                paragraph instanceof HTMLElement
                                && editorBoundaryParagraphIsEmpty(paragraph)
                                && active.contains(paragraph)
                            ) {
                                nextElement = paragraph;
                            }
                        }
                    }
                }
            }

            document.querySelectorAll('.has-leading-boundary-caret').forEach((element) => {
                if (element instanceof HTMLElement && element !== nextElement) {
                    clearBoundaryCaret(element);
                }
            });
            document.querySelectorAll('.uses-synthetic-boundary-caret').forEach((element) => {
                if (element instanceof HTMLElement && element !== active) {
                    clearSyntheticBoundaryCaret(element);
                }
            });
            if (nextElement) {
                nextElement.classList.add('has-leading-boundary-caret');
                active.classList.add('uses-synthetic-boundary-caret');
            } else if (active instanceof HTMLElement) {
                clearSyntheticBoundaryCaret(active);
            }
        }

        function mediaBoundaryAfterRange(body, range) {
            if (!range.collapsed || range.startContainer === body || !body.contains(range.startContainer)) {
                return null;
            }
            const media = topLevelBodyChild(body, range.startContainer);
            if (!isMediaBoundaryElement(body, media)) {
                return null;
            }
            const element = range.startContainer instanceof Element
                ? range.startContainer : range.startContainer.parentElement;
            // Captions have their own editing host/history. Only redirect a
            // body caret that the browser has left inside the image wrapper.
            if (element?.closest('.post-caption, figcaption, .post-media-overlay-caption')) {
                return null;
            }
            if (range.startContainer !== media && String(range.startContainer.textContent || '').trim() !== '') {
                return null;
            }
            let node = range.startContainer;
            let offset = range.startOffset;
            while (node instanceof Node) {
                if (node.nodeType !== Node.TEXT_NODE && Array.from(node.childNodes).slice(0, offset).some(child => (
                    child instanceof HTMLElement
                    && (child.matches('img, video, audio') || child.querySelector('img, video, audio'))
                ))) {
                    return media;
                }
                if (node === media) {
                    break;
                }
                const parent = node.parentNode;
                offset = Array.from(parent.childNodes).indexOf(node);
                node = parent;
            }
            return null;
        }

        function moveInsertionBeforeMediaBoundary(event) {
            if (!event.inputType.startsWith('insert')) {
                return;
            }

            const target = event.target;
            const body = target instanceof HTMLElement
                ? target.closest('.post-card.is-editing > .post.body[data-post-inplace-body]')
                : null;
            const selection = window.getSelection();
            if (
                !(body instanceof HTMLElement)
                || !selection
                || selection.rangeCount !== 1
            ) {
                return;
            }

            const selectedRange = selection.getRangeAt(0);
            const before = mediaBoundaryAtRange(body, selectedRange);
            const after = before ? null : mediaBoundaryAfterRange(body, selectedRange);
            const boundary = before || after;
            if (!boundary) {
                return;
            }

            // Chromium applies insertParagraph after this listener returns. Moving
            // the selection into a paragraph and then allowing that default action
            // would split the new paragraph, so one Enter would leave two empty
            // blocks before the media. Handle paragraph insertion ourselves.
            const handlesParagraph = event.inputType === 'insertParagraph' && event.cancelable;
            // WebKit caches the original text target before beforeinput. Merely
            // moving Selection is too late there: the first character can still
            // enter the picture wrapper. Insert that text at the corrected range
            // through the same native command used by formatting and paste.
            const handlesText = event.inputType === 'insertText'
                && typeof event.data === 'string' && event.cancelable;
            if (handlesParagraph || handlesText) {
                event.preventDefault();
            }

            document.querySelectorAll('.has-leading-boundary-caret').forEach(clearBoundaryCaret);
            document.querySelectorAll('.uses-synthetic-boundary-caret').forEach(clearSyntheticBoundaryCaret);
            const paragraph = document.createElement('p');
            paragraph.className = 'post-editor-body-paragraph';
            paragraph.append(document.createElement('br'));
            if (before) {
                boundary.parentNode.insertBefore(paragraph, boundary);
            } else {
                // A body caret between the image and caption is an after-image
                // boundary, never permission to split their shared wrapper.
                boundary.parentNode.insertBefore(paragraph, boundary.nextSibling);
            }
            const range = document.createRange();
            range.setStart(paragraph, 0);
            range.collapse(true);
            selection.removeAllRanges();
            selection.addRange(range);

            if (handlesText) {
                document.execCommand('insertText', false, event.data);
                return;
            }

            // A cancelled native action emits no input event. Reuse the normal input
            // path so dirty state, recovery and the shared undo history all observe
            // the single paragraph insertion.
            if (handlesParagraph) {
                body.dispatchEvent(new InputEvent('input', {
                    bubbles: true,
                    inputType: event.inputType,
                }));
            }
        }

        function protectSelectedMediaBoundary(body) {
            const selection = window.getSelection();
            if (selection?.rangeCount !== 1) return;
            const range = selection.getRangeAt(0);
            if (range.collapsed || !rangeIsInside(body, range)) return;
            const end = range.cloneRange();
            end.collapse(false);
            const media = mediaBoundaryAtRange(body, end);
            if (!media) return;
            let previous = media.previousSibling;
            while (previous && boundaryNodeIsEmpty(previous)) previous = previous.previousSibling;
            if (!(previous instanceof HTMLElement) || previous.querySelector('img, video, audio')) return;

            // Chromium's paragraph selection includes the start of the next block.
            // Replacing it can pull an unselected image out of its caption wrapper.
            // End inside the preceding text block, excluding only the whitespace
            // between blocks, never any selected prose or media.
            const protectedRange = range.cloneRange();
            protectedRange.setEnd(previous, previous.childNodes.length);
            if (protectedRange.collapsed || protectedRange.toString().trimEnd() !== range.toString().trimEnd()) return;
            const backwards = selection.anchorNode === range.endContainer && selection.anchorOffset === range.endOffset;
            const start = [protectedRange.startContainer, protectedRange.startOffset];
            const finish = [protectedRange.endContainer, protectedRange.endOffset];
            selection.setBaseAndExtent(...(backwards ? finish : start), ...(backwards ? start : finish));
        }

        function collapseEmptyParagraphBesideMedia(event) {
            const direction = event.inputType.endsWith('Backward')
                ? -1
                : (event.inputType.endsWith('Forward') ? 1 : 0);
            if (direction === 0 || !event.cancelable) {
                return false;
            }

            const target = event.target;
            const body = target instanceof HTMLElement
                ? target.closest('.post-card.is-editing > .post.body[data-post-inplace-body]')
                : null;
            const selection = window.getSelection();
            if (
                !(body instanceof HTMLElement)
                || !selection
                || selection.rangeCount !== 1
            ) {
                return false;
            }

            const currentRange = selection.getRangeAt(0);
            if (!currentRange.collapsed) {
                return false;
            }
            let paragraph = currentRange.startContainer instanceof HTMLElement
                ? currentRange.startContainer
                : currentRange.startContainer.parentNode;
            while (paragraph instanceof HTMLElement && paragraph.parentNode !== body) {
                paragraph = paragraph.parentNode;
            }
            if (!editorBoundaryParagraphIsEmpty(paragraph) || paragraph.parentNode !== body) {
                return false;
            }

            const siblings = Array.from(body.childNodes);
            const paragraphIndex = siblings.indexOf(paragraph);
            let neighbour = null;
            for (
                let index = paragraphIndex + direction;
                index >= 0 && index < siblings.length;
                index += direction
            ) {
                if (boundaryNodeIsEmpty(siblings[index])) {
                    continue;
                }
                neighbour = siblings[index];
                break;
            }
            if (!isMediaBoundaryElement(body, neighbour)) {
                return false;
            }

            // Chromium merges an empty paragraph with the adjacent media wrapper and
            // can remove its caption as collateral. Collapse the editable placeholder
            // instead: visually the line is gone, while the next insertion still has
            // a safe text container on the same side of the media.
            event.preventDefault();
            if (paragraph.classList.contains('post-editor-collapsed-boundary-paragraph')) {
                return true;
            }
            paragraph.classList.add('post-editor-collapsed-boundary-paragraph');
            body.focus({preventScroll: true});
            const range = document.createRange();
            range.setStart(paragraph, 0);
            range.collapse(true);
            selection.removeAllRanges();
            selection.addRange(range);
            body.dispatchEvent(new InputEvent('input', {
                bubbles: true,
                inputType: event.inputType,
            }));
            return true;
        }

        function expandCollapsedBoundaryParagraph(event) {
            if (!String(event.inputType || '').startsWith('insert')) {
                return false;
            }

            const target = event.target;
            const body = target instanceof HTMLElement
                ? target.closest('.post-card.is-editing > .post.body[data-post-inplace-body]')
                : null;
            const selection = window.getSelection();
            if (
                !(body instanceof HTMLElement)
                || !selection
                || selection.rangeCount !== 1
            ) {
                return false;
            }

            const currentRange = selection.getRangeAt(0);
            if (!currentRange.collapsed) {
                return false;
            }
            let paragraph = currentRange.startContainer instanceof HTMLElement
                ? currentRange.startContainer
                : currentRange.startContainer.parentNode;
            while (paragraph instanceof HTMLElement && paragraph !== body && paragraph.tagName !== 'P') {
                paragraph = paragraph.parentNode;
            }
            if (
                !(paragraph instanceof HTMLElement)
                || !body.contains(paragraph)
                || !paragraph.classList.contains('post-editor-collapsed-boundary-paragraph')
            ) {
                return false;
            }

            const handlesEmptyLine = (
                event.inputType === 'insertParagraph'
                || event.inputType === 'insertLineBreak'
            ) && event.cancelable;
            if (handlesEmptyLine) {
                event.preventDefault();
            }
            paragraph.classList.remove('post-editor-collapsed-boundary-paragraph');
            if (handlesEmptyLine) {
                body.dispatchEvent(new InputEvent('input', {
                    bubbles: true,
                    inputType: event.inputType,
                }));
            }
            return true;
        }

        function collapseEmptyLeadingParagraphAfterDelete(event, body) {
            if (!String(event.inputType || '').startsWith('delete')) {
                return false;
            }

            const selection = window.getSelection();
            if (!selection || selection.rangeCount !== 1) {
                return false;
            }
            const currentRange = selection.getRangeAt(0);
            if (!currentRange.collapsed) {
                return false;
            }

            let paragraph = currentRange.startContainer instanceof HTMLElement
                ? currentRange.startContainer
                : currentRange.startContainer.parentNode;
            while (paragraph instanceof HTMLElement && paragraph.parentNode !== body) {
                paragraph = paragraph.parentNode;
            }
            if (!editorBoundaryParagraphIsEmpty(paragraph) || paragraph.parentNode !== body) {
                return false;
            }

            const siblings = Array.from(body.childNodes);
            const paragraphIndex = siblings.indexOf(paragraph);
            if (paragraphIndex < 0 || !siblings.slice(0, paragraphIndex).every(boundaryNodeIsEmpty)) {
                return false;
            }

            let media = null;
            for (let index = paragraphIndex + 1; index < siblings.length; ++index) {
                if (boundaryNodeIsEmpty(siblings[index])) {
                    continue;
                }
                media = isMediaBoundaryElement(body, siblings[index]) ? siblings[index] : null;
                break;
            }
            if (!(media instanceof HTMLElement)) {
                return false;
            }

            paragraph.remove();
            body.focus({preventScroll: true});
            const range = document.createRange();
            range.setStart(body, paragraphIndex);
            range.collapse(true);
            selection.removeAllRanges();
            selection.addRange(range);
            syncBoundaryCaret();
            return true;
        }

        function isMediaOwnedDirectChild(node) {
            if (!(node instanceof HTMLElement)) {
                return false;
            }
            if (node.matches(
                'img, picture, video, audio, source, track, '
                + '.post-picture, .post-media-picture, '
                + '.post-media-overlay, .post-media-processing-progress, '
                + '.post-caption, figcaption, .post-media-caption-toolbar',
            )) {
                return true;
            }
            return node.matches('a') && Boolean(node.querySelector('img, picture, video, audio'));
        }

        function ensureTrailingTableParagraph(root) {
            let last = root.lastChild;
            while (last && (boundaryNodeIsEmpty(last) || last.nodeType === Node.COMMENT_NODE)) {
                last = last.previousSibling;
            }
            if (!(last instanceof HTMLTableElement)) {
                return;
            }
            // A root/table boundary has no paintable native caret. Give the
            // editor an ordinary empty line, stripped by serialization until
            // the author actually types in it. Never modify the table's cells.
            const paragraph = document.createElement('p');
            paragraph.className = 'post-editor-body-paragraph post-editor-empty-paragraph';
            paragraph.append(document.createElement('br'));
            root.append(paragraph);
        }

        function normalizeMediaBodyStructure(root) {
            let changed = false;
            // CSS cannot distinguish <p><br></p> from <p>Text<br></p>:
            // :only-child counts elements, not text nodes. Mark only genuinely
            // empty lines, including native Enter clones and reopened content.
            root.querySelectorAll(':scope > p').forEach((paragraph) => {
                paragraph.classList.toggle('post-editor-empty-paragraph', editorBoundaryParagraphIsEmpty(paragraph));
            });
            const selection = window.getSelection();
            const preserveSelection = selection?.rangeCount > 0
                && root.contains(selection.anchorNode)
                && root.contains(selection.focusNode);
            const anchorNode = preserveSelection ? selection.anchorNode : null;
            const anchorOffset = preserveSelection ? selection.anchorOffset : 0;
            const focusNode = preserveSelection ? selection.focusNode : null;
            const focusOffset = preserveSelection ? selection.focusOffset : 0;
            const pictures = Array.from(root.querySelectorAll('.post-media-picture')).reverse();
            pictures.forEach((picture) => {
                if (!picture.isConnected && !root.contains(picture)) {
                    return;
                }
                const children = Array.from(picture.childNodes);
                const hasBodyContent = children.some((node) => (
                    node.nodeType === Node.TEXT_NODE
                        ? String(node.textContent || '').trim() !== ''
                        : !isMediaOwnedDirectChild(node)
                ));
                if (!hasBodyContent) {
                    return;
                }

                // A browser editing operation must never leave prose inside the
                // image wrapper. Besides inheriting caption typography, that tree
                // is reparsed differently by the page renderer and can lose text.
                // Move the original nodes (rather than cloning or serializing them)
                // so a live selection/caret inside the prose remains attached.
                const bodyNodes = children.filter((node) => !isMediaOwnedDirectChild(node));
                const fragment = document.createDocumentFragment();
                let paragraph = null;
                bodyNodes.forEach((node) => {
                    const isBlock = node instanceof HTMLElement && node.matches(
                        'p, div, h1, h2, h3, h4, h5, h6, blockquote, pre, ul, ol, table, hr, figure',
                    );
                    if (isBlock) {
                        paragraph = null;
                        fragment.append(node);
                        return;
                    }
                    if (!(paragraph instanceof HTMLElement)) {
                        paragraph = document.createElement('p');
                        paragraph.className = 'post-editor-body-paragraph';
                        fragment.append(paragraph);
                    }
                    paragraph.append(node);
                });
                picture.parentNode?.insertBefore(fragment, picture.nextSibling);
                changed = true;
            });
            ensureTrailingTableParagraph(root);
            if (changed && preserveSelection && root.contains(anchorNode) && root.contains(focusNode)) {
                selection.setBaseAndExtent(anchorNode, anchorOffset, focusNode, focusOffset);
            }
            return changed;
        }

        return {clearBoundaryCaret, clearSyntheticBoundaryCaret, boundaryNodeIsEmpty, isMediaBoundaryElement, editorBoundaryParagraphIsEmpty, topLevelBodyChild, hoistMediaFromParagraph, normalizeLeadingNestedMedia, leadingMediaIndex, prepareMediaInsertionRange, focusBeforeLeadingMedia, focusBeforeMedia, focusAfterMedia, mediaBesideCaret, revealBoundaryCaret, mediaBoundaryAtRange, inlineCodeBoundaryMarkerAtRange, syncBoundaryCaret, moveInsertionBeforeMediaBoundary, protectSelectedMediaBoundary, collapseEmptyParagraphBesideMedia, expandCollapsedBoundaryParagraph, collapseEmptyLeadingParagraphAfterDelete, isMediaOwnedDirectChild, normalizeMediaBodyStructure};
    }

    window.RegisterEditorBoundaries = Object.freeze({create});
})();
