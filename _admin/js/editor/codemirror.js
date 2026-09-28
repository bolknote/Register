/**
 * CodeMirror initialization and helper functions.
 *
 * @copyright 2025-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */
import {smartParagraphs} from './text/paragraphs.js';
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
        const match = tag.match(new RegExp('\\b' + name + '\\s*=\\s*(["\\\'])([\\s\\S]*?)\\1', 'i'));
        return match ? decodeHtmlAttribute(match[2]) : '';
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
        const pattern = /<img\b[^>]*>/gi;
        let match;
        while ((match = pattern.exec(content)) !== null) {
            const end = match.index + match[0].length;
            const target = targetsByRange.get(`${match.index}:${end}`) || {
                marker: doc.markText(doc.posFromIndex(match.index), doc.posFromIndex(end))
            };
            imageTargets.add(target);
            retained.add(target);
            images.push({
                src: readImageAttribute(match[0], 'src'),
                alt: readImageAttribute(match[0], 'alt'),
                tag: match[0],
                start: match.index,
                end,
                line: doc.posFromIndex(match.index).line,
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

    /** Duplicate a current line in CodeMirror doc */
    function cmDuplicateLine(cm) {
        const CodeMirror = getCodeMirror();
        if (!CodeMirror) {
            return;
        }
        // get a position of a current cursor in a current cell
        const currentCursor = cm.doc.getCursor();

        // read a content from a line where is the current cursor
        const lineContent = cm.doc.getLine(currentCursor.line);

        // go to the end the current line
        CodeMirror.commands.goLineEnd(cm);

        // make a break for a new line
        CodeMirror.commands.newlineAndIndent(cm);

        // move caret to the left position
        CodeMirror.commands.goLineStart(cm);

        // filled a content of the new line content with line above it
        cm.doc.replaceSelection(lineContent);

        // restore position cursor on the new line
        cm.doc.setCursor(currentCursor.line + 1, currentCursor.ch);
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
            const altPattern = /\balt\s*=\s*(["'])([\s\S]*?)\1/i;
            let updatedTag;
            if (altPattern.test(image.tag)) {
                updatedTag = image.tag.replace(altPattern, function (attribute, quote) {
                    return 'alt=' + quote + escapedAlt + quote;
                });
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

        addTag: function (sOpenTag, sCloseTag) {
            if (!instance) {
                return false;
            }

            const replacements = instance.getSelections().map(text => (
                text.startsWith(sOpenTag) && text.endsWith(sCloseTag)
                    ? text.slice(sOpenTag.length, text.length - sCloseTag.length)
                    : sOpenTag + text + sCloseTag
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
                const replacements = instance.getSelections().map(text => text.replace(
                    /^(?:[ ]*<(?:p|blockquote|h[2-4])[^>]*>)?([\s\S]*?)(?:<\/(?:p|blockquote|h[2-4])>)?[ ]*$/,
                    sOpenTag + '$1' + sCloseTag
                ));
                instance.replaceSelections(replacements, 'around', 'editor-format');
            } else {
                var cursor = instance.getCursor(),
                    totalLineNum = instance.lineCount(),
                    currentLine = instance.getLine(cursor.line);

                if (currentLine.replace(/^\s+|\s+$/g, '') === '') {
                    // Empty line
                    if ((totalLineNum <= cursor.line + 1 || instance.getLine(cursor.line + 1).replace(/^\s+|\s+$/g, '') === '') &&
                        (cursor.line <= 0 || instance.getLine(cursor.line - 1).replace(/^\s+|\s+$/g, '') === '')) {
                        // surrounded by empty lines
                        instance.replaceRange(
                            sOpenTag + sCloseTag,
                            {line: cursor.line, ch: 0},
                            {line: cursor.line, ch: 0}
                        );
                        instance.setCursor(cursor.line, sOpenTag.length);
                    }
                } else {
                    // Cursor is on a non-empty line.
                    // Find non-empty lines before this line.
                    for (var i = cursor.line; i--;) {
                        if (instance.getLine(i).trim() === '') {
                            break;
                        }
                    }
                    i++;

                    var newLinesBuffer = [],
                        firstLine = instance.getLine(i),
                        startLineIndex = i,
                        firstLineOldLength = firstLine.length;

                    // Process first line and add to buffer
                    firstLine = sOpenTag + firstLine.replace(/^[ ]*<(?:p|blockquote|h[2-4])[^>]*>/, '');
                    newLinesBuffer.push(firstLine);

                    // Find all non-empty lines after.
                    for (i++; i < totalLineNum; i++) {
                        var line = instance.getLine(i);
                        if (line.trim() === '') {
                            break;
                        }
                        // Add middle lines to buffer as is
                        newLinesBuffer.push(line);
                    }
                    i--;

                    var lastLine = newLinesBuffer[newLinesBuffer.length - 1],
                        lastLineLength = (i === startLineIndex ? firstLineOldLength : lastLine.length);

                    // Process last line and replace in stored buffer
                    lastLine = lastLine.replace(/(?:<\/(?:p|blockquote|h[2-4])>)?[ ]*$/, '') + sCloseTag;
                    newLinesBuffer[newLinesBuffer.length - 1] = lastLine;

                    // We know the positions of old text and the new text
                    instance.replaceRange(
                        newLinesBuffer.join("\n"),
                        {line: startLineIndex, ch: 0},
                        {line: i, ch: lastLineLength},
                        '*replaceparagraph'
                    );

                    // Restore position of cursor inside shifted text
                    if (cursor.line === startLineIndex) {
                        cursor.ch += firstLine.length - firstLineOldLength;
                        if (cursor.ch < sOpenTag.length) {
                            cursor.ch = sOpenTag.length;
                        }
                        instance.setCursor(cursor, '*replaceparagraph');
                    } else if (cursor.line === i) {
                        if (cursor.ch > lastLine.length - sCloseTag.length) {
                            cursor.ch = lastLine.length - sCloseTag.length;
                        }
                        instance.setCursor(cursor, '*replaceparagraph');
                    }
                }
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
