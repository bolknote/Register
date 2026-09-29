/**
 * CodeMirror initialization and helper functions.
 *
 * @copyright 2025-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */
import {smartParagraphs} from './text/paragraphs.js';
import {htmlTags, htmlAttribute, paragraphBlocks, formatParagraph, formatParagraphOpeningTag} from './text/html.js';
import {htmlToPlainText, normalizePlainText} from './text/plain.js';
import {editorDeps} from './deps.js';
import {escapeHtml} from './utils/escape.js';

let codeMirrorInitialized = false;

function getCodeMirror() {
    return editorDeps.CodeMirror;
}

function accessibleTextareaLabel(textarea) {
    const explicitLabel = textarea.getAttribute('aria-label');
    if (explicitLabel !== null && explicitLabel.trim() !== '') {
        return explicitLabel.trim();
    }

    const label = textarea.labels?.[0]?.textContent;
    if (label !== undefined && label.trim() !== '') {
        return label.trim();
    }

    return textarea.name || 'Text';
}

const register_codemirror = (function () {
    let instance, scrollTop = null;
    let aiChangeMarkers = [];
    let applyingAiChanges = false;
    const imageTargets = new Set();

    function clearImageTargets() {
        imageTargets.forEach(target => target.marker.clear());
        imageTargets.clear();
    }

    function clearAiChangeMarkers() {
        aiChangeMarkers.forEach(function (marker) {
            marker.clear();
        });
        aiChangeMarkers = [];
    }

    function decodeHtmlAttribute(value) {
        const textarea = document.createElement('textarea');
        textarea.innerHTML = value;
        return textarea.value;
    }

    function readImageAttribute(tag, name) {
        const attribute = htmlAttribute(tag, name);
        return attribute ? decodeHtmlAttribute(attribute.value) : '';
    }

    function imageTags() {
        if (!instance) {
            return [];
        }

        // Neutral text markers follow each occurrence through surrounding edits.
        // Neither its URL nor its original offset uniquely identifies an image.
        const doc = instance.getDoc();
        const targetsByRange = new Map();
        imageTargets.forEach(target => {
            const range = target.marker.find();
            if (range) {
                targetsByRange.set(`${doc.indexFromPos(range.from)}:${doc.indexFromPos(range.to)}`, target);
            }
        });
        const content = instance.getValue();
        const images = [];
        const retained = new Set();
        for (const tag of htmlTags(content)) {
            if (tag.name !== 'img' || tag.closing) continue;
            const target = targetsByRange.get(`${tag.start}:${tag.end}`) || {
                marker: doc.markText(doc.posFromIndex(tag.start), doc.posFromIndex(tag.end))
            };
            imageTargets.add(target);
            retained.add(target);
            images.push({
                src: readImageAttribute(tag.text, 'src'),
                alt: readImageAttribute(tag.text, 'alt'),
                tag: tag.text,
                start: tag.start,
                end: tag.end,
                line: doc.posFromIndex(tag.start).line,
                target
            });
        }

        imageTargets.forEach(target => {
            if (!retained.has(target)) {
                target.marker.clear();
                imageTargets.delete(target);
            }
        });

        return images;
    }

    function findImageBySource(src, expectedAlt) {
        if (!instance) return null;
        src = decodeHtmlAttribute(src);
        const images = imageTags();
        const cursorIndex = instance.getDoc().indexFromPos(instance.getCursor());
        const selected = images.find(image => image.start <= cursorIndex && cursorIndex <= image.end
            && image.src === src && (expectedAlt === undefined || image.alt === expectedAlt));
        if (selected) return selected;
        for (let i = images.length - 1; i >= 0; i--) {
            if (images[i].src === src && (expectedAlt === undefined || images[i].alt === expectedAlt)) {
                return images[i];
            }
        }
        return null;
    }

    function primarySelectionIndex(editor, ranges) {
        const cursor = editor.getCursor();
        return ranges.findIndex(({head}) => head.line === cursor.line && head.ch === cursor.ch);
    }

    /** Duplicate each cursor's line once, preserving its exact whitespace. */
    function cmDuplicateLine(cm) {
        const ranges = cm.listSelections();
        const primary = primarySelectionIndex(cm, ranges);
        const lines = [...new Set(ranges.map(({head}) => head.line))].sort((a, b) => a - b);
        cm.operation(function () {
            for (const line of lines.slice().reverse()) {
                const content = cm.getLine(line);
                cm.replaceRange('\n' + content, {line, ch: content.length}, null, 'editor-duplicate-line');
            }
            cm.setSelections(ranges.map(({head}) => {
                const cursor = {line: head.line + lines.filter(line => line <= head.line).length, ch: head.ch};
                return {anchor: cursor, head: cursor};
            }), primary);
        });
    }

    function ensureCodeMirror() {
        const CodeMirror = getCodeMirror();
        if (!CodeMirror) {
            return null;
        }
        if (!codeMirrorInitialized) {
            // from https://gist.github.com/Boorj/eb020e14487329431bdabc9141ee7ca1
            CodeMirror.keyMap.pcDefault["Ctrl-D"] = cmDuplicateLine;
            codeMirrorInitialized = true;
        }
        return CodeMirror;
    }

//    CodeMirror.keyMap.pcDefault["Ctrl-Y"] = CodeMirror.commands.findPersistent;

    const api = {
        get_instance: function (eTextarea) {
            const CodeMirror = ensureCodeMirror();
            if (!CodeMirror) {
                return null;
            }
            clearImageTargets();
            scrollTop = eTextarea.scrollTop;

            instance = CodeMirror.fromTextArea(eTextarea, {
                extraKeys: {
                    "Ctrl-F": "findPersistent",
                    "F3": "findNext",
                    "Shift-F3": "findPrev",
                    "Ctrl-H": "replace",
                    "Ctrl-Z": "undo",
                    "Cmd-Z": "undo",
                    "Ctrl-Y": "redo",
                    "Shift-Ctrl-Z": "redo",
                    "Shift-Cmd-Z": "redo"
                },
                mode: "text/html",
                smartIndent: false,
                indentUnit: 4,
                indentWithTabs: true,
                lineWrapping: true,
                spellcheck: true,
                screenReaderLabel: accessibleTextareaLabel(eTextarea),
                inputStyle: "contenteditable",
                // Render all lines to keep accurate height mapping for sync scroll.
                viewportMargin: Infinity,
                foldGutter: true,
                gutters: ["CodeMirror-linenumbers", "CodeMirror-foldgutter"],
                selectionPointer: true
            });
            instance.on('change', function () {
                if (!applyingAiChanges && aiChangeMarkers.length > 0) {
                    clearAiChangeMarkers();
                }
            });

            api.restore_scroll();

            return instance;
        },

        close: function () {
            if (instance) {
                clearAiChangeMarkers();
                clearImageTargets();
                api.store_scroll();

                var eText = instance.getTextArea();
                instance.toTextArea();
                instance = null;
                if (scrollTop)
                    eText.scrollTop = scrollTop;
            }
        },

        store_scroll: function () {
            if (!instance)
                return;

            var eScroll = instance.getScrollerElement();
            if (typeof eScroll.scrollTop != 'undefined')
                scrollTop = eScroll.scrollTop;
        },

        restore_scroll: function () {
            if (instance && scrollTop)
                instance.getScrollerElement().scrollTop = scrollTop;
        },

        flip: function () {
            if (instance)
                instance.save();
        },
        isReady: function () {
            return !!instance;
        },
        lockEditing: function () {
            const editor = instance;
            if (!editor) return () => {};
            const readOnly = editor.getOption('readOnly');
            editor.setOption('readOnly', 'nocursor');
            return () => {
                if (instance === editor) editor.setOption('readOnly', readOnly);
            };
        },
        onChange: function (handler) {
            if (!instance || typeof handler !== 'function') {
                return;
            }
            instance.on('change', function () {
                handler();
            });
        },
        onCursorActivity: function (handler) {
            if (!instance || typeof handler !== 'function') {
                return;
            }
            instance.on('cursorActivity', function () {
                handler();
            });
        },
        onPaste: function (handler) {
            if (!instance || typeof handler !== 'function') {
                return;
            }
            instance.on('paste', function (cmInstance, event) {
                handler(event);
            });
        },
        onDrop: function (handler) {
            if (!instance || typeof handler !== 'function') {
                return;
            }
            instance.on('drop', function (cmInstance, event) {
                handler(event);
            });
        },
        setSelectionFromCoords: function (x, y) {
            if (!instance) {
                return;
            }
            instance.setSelection(instance.coordsChar({
                left: x,
                top: y
            }));
        },
        replaceAllText: function (searchText, replacementText) {
            if (!instance || !instance.getSearchCursor) {
                return;
            }
            instance.operation(function () {
                const cursor = instance.getSearchCursor(searchText, {line: 0, ch: 0});
                while (cursor.findNext()) {
                    cursor.replace(replacementText);
                }
            });
        },
        getValue: function () {
            if (!instance) {
                return '';
            }
            return instance.getValue();
        },
        setValue: function (value, clearHistory) {
            if (!instance) {
                return false;
            }
            clearAiChangeMarkers();
            instance.setValue(value);
            if (clearHistory) {
                instance.clearHistory();
            }
            instance.save();
            return true;
        },
        getSelectionSnapshot: function () {
            if (!instance) {
                return {text: '', start: 0, end: 0, hasSelection: false};
            }
            const doc = instance.getDoc();
            const hasSelection = instance.somethingSelected();
            if (!hasSelection) {
                const text = instance.getValue();
                return {text: text, start: 0, end: text.length, hasSelection: false};
            }

            const from = doc.getCursor('from');
            const to = doc.getCursor('to');
            return {
                text: doc.getRange(from, to),
                start: doc.indexFromPos(from),
                end: doc.indexFromPos(to),
                hasSelection: true
            };
        },
        trackRange: function (startIndex, endIndex) {
            const editor = instance;
            if (!editor) return null;
            const doc = editor.getDoc();
            const marker = doc.markText(doc.posFromIndex(startIndex), doc.posFromIndex(endIndex));
            let invalid = false;
            function beforeChange(_editor, change) {
                const range = marker.find();
                if (!range || change.origin === 'setValue') {
                    invalid = true;
                    return;
                }
                const start = doc.indexFromPos(range.from);
                const end = doc.indexFromPos(range.to);
                const from = doc.indexFromPos(change.from);
                const to = doc.indexFromPos(change.to);
                // Surrounding edits move the marker. Any edit inside it retires
                // this target, even if identical text later occupies its range.
                if (from === to ? start < from && from < end : from < end && to > start) {
                    invalid = true;
                }
                if (change.origin === '+input') {
                    // Contenteditable's DOM diff can locate an insertion on the
                    // other side of identical text. If that ambiguity crosses
                    // our source, its marker no longer identifies the occurrence.
                    const inputFrom = doc.indexFromPos(doc.getCursor('from'));
                    const inputTo = doc.indexFromPos(doc.getCursor('to'));
                    if ((from !== inputFrom || to !== inputTo)
                        && Math.min(from, inputFrom) < end && Math.max(to, inputTo) > start) {
                        invalid = true;
                    }
                }
            }
            editor.on('beforeChange', beforeChange);
            return {
                find() {
                    const range = marker.find();
                    return !invalid && instance === editor && range ? {
                        start: doc.indexFromPos(range.from),
                        end: doc.indexFromPos(range.to)
                    } : null;
                },
                clear() {
                    invalid = true;
                    editor.off('beforeChange', beforeChange);
                    marker.clear();
                }
            };
        },
        replaceRangeByIndex: function (text, startIndex, endIndex) {
            if (!instance) {
                return;
            }
            const doc = instance.getDoc();
            doc.replaceRange(text, doc.posFromIndex(startIndex), doc.posFromIndex(endIndex));
            instance.focus();
        },
        getImageBySrc: function (src, expectedAlt) {
            return findImageBySource(src, expectedAlt);
        },
        getTrackedImage: function (image, expectedAlt) {
            return imageTags().find(current => current.target === image.target && current.src === image.src
                && (expectedAlt === undefined || current.alt === expectedAlt)) || null;
        },
        getCursorImage: function () {
            if (!instance) {
                return null;
            }

            const doc = instance.getDoc();
            const cursor = doc.getCursor();
            const cursorIndex = doc.indexFromPos(cursor);
            const lineStart = doc.indexFromPos({line: cursor.line, ch: 0});
            const lineEnd = lineStart + instance.getLine(cursor.line).length;
            const candidates = imageTags().filter(function (image) {
                return image.start <= lineEnd && image.end >= lineStart;
            });
            if (candidates.length === 0) {
                return null;
            }

            return candidates.find(function (image) {
                return image.start <= cursorIndex && image.end >= cursorIndex;
            }) || candidates.filter(function (image) {
                return image.start <= cursorIndex;
            }).pop() || candidates[0];
        },
        replaceImageAlt: function (originalImage, expectedAlt, nextAlt) {
            if (!instance) {
                return false;
            }

            const image = api.getTrackedImage(originalImage, expectedAlt);
            if (!image) {
                return false;
            }

            const escapedAlt = escapeHtml(nextAlt);
            const alt = htmlAttribute(image.tag, 'alt');
            let updatedTag;
            if (alt) {
                updatedTag = image.tag.slice(0, alt.start) + 'alt=' + alt.quote + escapedAlt + alt.quote
                    + image.tag.slice(alt.end);
            } else {
                updatedTag = image.tag.replace(/\s*\/?>(?=\s*$)/, function (ending) {
                    return ' alt="' + escapedAlt + '"' + (ending.includes('/') ? ' />' : '>');
                });
            }

            const doc = instance.getDoc();
            instance.operation(function () {
                image.target.marker.clear();
                doc.replaceRange(
                    updatedTag,
                    doc.posFromIndex(image.start),
                    doc.posFromIndex(image.end),
                    'ai-image-alt'
                );
                // Keep the same occurrence identity after replacing its whole tag.
                image.target.marker = doc.markText(
                    doc.posFromIndex(image.start),
                    doc.posFromIndex(image.start + updatedTag.length)
                );
            });
            instance.save();
            return true;
        },
        addLineWidget: function (line, node) {
            if (!instance || !node || !instance.addLineWidget) {
                return null;
            }
            return instance.addLineWidget(line, node, {
                above: false,
                coverGutter: false,
                noHScroll: false
            });
        },
        replaceRangeWithHighlights: function (text, startIndex, endIndex, ranges) {
            if (!instance) {
                return;
            }

            const doc = instance.getDoc();
            applyingAiChanges = true;
            try {
                instance.operation(function () {
                    clearAiChangeMarkers();
                    doc.replaceRange(
                        text,
                        doc.posFromIndex(startIndex),
                        doc.posFromIndex(endIndex),
                        'ai-proofread'
                    );

                    ranges.forEach(function (range) {
                        const start = Math.max(0, Math.min(text.length, range.start));
                        const end = Math.max(start, Math.min(text.length, range.end));
                        if (start === end) {
                            return;
                        }
                        aiChangeMarkers.push(doc.markText(
                            doc.posFromIndex(startIndex + start),
                            doc.posFromIndex(startIndex + end),
                            {className: 'ai-editor-change'}
                        ));
                    });
                });
            } finally {
                applyingAiChanges = false;
            }
            instance.focus();
        },
        undo: function () {
            if (!instance) {
                return false;
            }
            instance.undo();
            instance.focus();
            return true;
        },
        redo: function () {
            if (!instance) {
                return false;
            }
            instance.redo();
            instance.focus();
            return true;
        },
        getLineCount: function () {
            return instance ? instance.lineCount() : 0;
        },
        getCursorLine: function () {
            if (!instance) {
                return 0;
            }
            const cursor = instance.getCursor();
            return cursor ? cursor.line : 0;
        },
        getLineTop: function (line) {
            if (!instance) {
                return 0;
            }
            if (instance.heightAtLine) {
                return instance.heightAtLine(line, 'local');
            }
            return instance.charCoords({line: line, ch: 0}, 'local').top;
        },
        getScrollerElement: function () {
            return instance ? instance.getScrollerElement() : null;
        },
        getScrollTop: function () {
            if (!instance) {
                return 0;
            }
            return instance.getScrollInfo().top;
        },
        setScrollTop: function (y) {
            if (!instance) {
                return;
            }
            instance.scrollTo(null, y);
        },

        addTag: function (sOpenTag, sCloseTag, selectionAsAttribute = false) {
            if (!instance) {
                return false;
            }

            const replacements = instance.getSelections().map(text => (
                !selectionAsAttribute && text.startsWith(sOpenTag) && text.endsWith(sCloseTag)
                    ? text.slice(sOpenTag.length, text.length - sCloseTag.length)
                    : sOpenTag + (selectionAsAttribute ? escapeHtml(normalizePlainText(htmlToPlainText(text))) : text) + sCloseTag
            ));
            // CodeMirror maps every range through the replacements, including
            // newlines, reversed selections and edits on preceding lines.
            instance.replaceSelections(replacements, 'around', 'editor-format');
            instance.focus();
            return true;
        },

        smart: function () {
            if (!instance)
                return false;

            instance.setValue(smartParagraphs(instance.getValue()));
            return true;
        },

        paragraph: function (sOpenTag, sCloseTag) {
            if (!instance)
                return false;

            if (instance.somethingSelected()) {
                const replacements = instance.getSelections().map(text => formatParagraph(text, sOpenTag, sCloseTag));
                instance.replaceSelections(replacements, 'around', 'editor-format');
            } else {
                const ranges = instance.listSelections();
                const primary = primarySelectionIndex(instance, ranges);
                const totalLineNum = instance.lineCount();
                const doc = instance.getDoc();
                const source = instance.getValue();
                const blocks = paragraphBlocks(source);
                const targets = new Map();
                const carets = ranges.map(({head: cursor}) => {
                    const position = doc.indexFromPos(cursor);
                    // Use the innermost enclosing element, even when adjacent
                    // blocks share a line or have no blank line between them.
                    let block = blocks.filter(block => block.start <= position && position <= block.end)
                        .sort((a, b) => b.start - a.start)[0];
                    if (!block && instance.getLine(cursor.line).trim() === '') {
                        if ((totalLineNum <= cursor.line + 1 || instance.getLine(cursor.line + 1).trim() === '') &&
                            (cursor.line <= 0 || instance.getLine(cursor.line - 1).trim() === '')) {
                            const start = doc.indexFromPos({line: cursor.line, ch: 0});
                            block = {start, end: start, contentStart: start, contentEnd: start};
                        } else {
                            return {position};
                        }
                    } else if (!block) {
                        let first = cursor.line;
                        let last = cursor.line;
                        while (first > 0 && instance.getLine(first - 1).trim() !== '') first--;
                        while (last + 1 < totalLineNum && instance.getLine(last + 1).trim() !== '') last++;
                        let start = doc.indexFromPos({line: first, ch: 0});
                        let end = doc.indexFromPos({line: last, ch: instance.getLine(last).length});
                        // Untagged text retains blank-line formatting without
                        // absorbing a neighbouring complete HTML block.
                        for (const neighbour of blocks) {
                            if (neighbour.end <= position) start = Math.max(start, neighbour.end);
                            if (neighbour.start >= position) end = Math.min(end, neighbour.start);
                        }
                        const text = source.slice(start, end);
                        // Leave separator lines outside the new block, but keep
                        // indentation and trailing spaces in preformatted text.
                        start += text.match(/^[ \t]*\n/)?.[0].length || 0;
                        end -= text.match(/\n[ \t]*$/)?.[0].length || 0;
                        block = {start, end, contentStart: start, contentEnd: end};
                    }
                    const key = block.start + ':' + block.end;
                    if (!targets.has(key)) targets.set(key, block);
                    return {position, block: targets.get(key)};
                });
                const changes = [];
                targets.forEach(block => {
                    block.open = {from: block.start, to: block.contentStart,
                        text: formatParagraphOpeningTag(source.slice(block.start, block.contentStart), sOpenTag)};
                    block.close = {from: block.contentEnd, to: block.end, text: sCloseTag};
                    changes.push(block.open, block.close);
                });
                // Change only the delimiters so nested targets and unmodified
                // content survive. Equal-position insertions keep open/close order.
                changes.sort((a, b) => a.from - b.from || a.to - b.to);
                let delta = 0;
                for (const change of changes) {
                    change.resultFrom = change.from + delta;
                    change.resultTo = change.resultFrom + change.text.length;
                    delta += change.text.length - (change.to - change.from);
                }
                const positions = carets.map(({position, block}) => {
                    if (block && position <= block.contentStart) return block.open.resultTo;
                    if (block && position >= block.contentEnd) return block.close.resultFrom;
                    return position + changes.reduce((shift, change) => shift + (change.to <= position
                        ? change.text.length - (change.to - change.from) : 0), 0);
                });
                instance.operation(function () {
                    for (const change of changes.slice().reverse()) {
                        instance.replaceRange(change.text, doc.posFromIndex(change.from), doc.posFromIndex(change.to), 'editor-format');
                    }
                    instance.setSelections(positions.map(position => {
                        const cursor = doc.posFromIndex(position);
                        return {anchor: cursor, head: cursor};
                    }), primary);
                });
            }

            instance.focus();

            return true;
        }
    };

    return api;
}());

document.addEventListener('check_changes_start.register', register_codemirror.flip);
document.addEventListener('save_article_start.register', register_codemirror.flip);
document.addEventListener('changes_present.register', register_codemirror.flip);

export {register_codemirror};
