import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const editorSource = await readFile(
    new URL('../../_assets/register/post-inplace.js', import.meta.url),
    'utf8'
);
const testableEditorSource = editorSource.replace(
    '    applyShortcutHints(document);\n})();',
    [
        '    window.__postInplaceTest = {',
        '        applyContextBlockState,',
        '        collapseEmptyLeadingParagraphAfterDelete,',
        '        contextBlockStyle,',
        '        exitQuoteOnEnter,',
        '        exitStyledBlockOnEnter,',
        '        focusAfterMedia,',
        '        focusBeforeLeadingMedia,',
        '        inlineCodeAtCaretEnd,',
        '        mergeAdjacentInlineCode,',
        '        moveAfterInlineCode,',
        '        moveFromLeadingMediaCaption,',
        '        moveFromBodyMediaBoundary,',
        '        moveFromInlineMediaCaption,',
        '        prepareMediaInsertionRange,',
        '        quoteAtCaret,',
        '        removeInlineCodeExitMarkers,',
        '        removeTrailingEditorArtifacts,',
        '        styledBlockAtCaretEnd,',
        '    };',
        '    applyShortcutHints(document);',
        '})();',
    ].join('\n')
);
assert.notEqual(testableEditorSource, editorSource);

class FakeClassList {
    constructor(...names) {
        this.names = new Set(names);
    }

    add(...names) {
        names.forEach((name) => this.names.add(name));
    }

    remove(...names) {
        names.forEach((name) => this.names.delete(name));
    }

    toggle(name, force) {
        if (force === undefined ? !this.names.has(name) : force) {
            this.names.add(name);
            return true;
        }
        this.names.delete(name);
        return false;
    }

    contains(name) {
        return this.names.has(name);
    }
}

class FakeNode {
    static TEXT_NODE = 3;

    constructor(parentNode = null) {
        this.parentNode = parentNode;
        this.nodeType = 1;
    }

    get nextSibling() {
        if (!this.parentNode?.childNodes) {
            return null;
        }
        const index = this.parentNode.childNodes.indexOf(this);
        return index >= 0 ? (this.parentNode.childNodes[index + 1] || null) : null;
    }

    get parentElement() {
        return this.parentNode instanceof FakeHTMLElement ? this.parentNode : null;
    }

    remove() {
        if (!this.parentNode?.childNodes) {
            this.parentNode = null;
            return;
        }
        const index = this.parentNode.childNodes.indexOf(this);
        if (index >= 0) {
            this.parentNode.childNodes.splice(index, 1);
        }
        this.parentNode = null;
    }
}

class FakeTextNode extends FakeNode {
    constructor(parentNode, textContent) {
        super(parentNode);
        this.nodeType = FakeNode.TEXT_NODE;
        this.data = textContent;
        this.textContent = textContent;
    }
}

class FakeHTMLElement extends FakeNode {
    constructor({
        parentNode = null,
        classes = [],
        media = false,
        rect = {left: 0, top: 0},
        tagName = 'DIV',
    } = {}) {
        super(parentNode);
        this.childNodes = [];
        this.classList = new FakeClassList(...classes);
        this.dataset = {};
        this.media = media;
        this.rect = rect;
        this.tagName = tagName;
        this.attributes = new Map();
    }

    get firstChild() {
        return this.childNodes[0] || null;
    }

    get lastChild() {
        return this.childNodes[this.childNodes.length - 1] || null;
    }

    get lastElementChild() {
        return this.childNodes.findLast((node) => node instanceof FakeHTMLElement) || null;
    }

    get textContent() {
        return this.childNodes.map((node) => node.textContent).join('');
    }

    set textContent(value) {
        [...this.childNodes].forEach((node) => node.remove());
        if (value !== '') {
            this.append(new FakeTextNode(null, value));
        }
    }

    append(node) {
        node.remove();
        node.parentNode = this;
        this.childNodes.push(node);
    }

    addEventListener() {}

    blur() {}

    focus() {}

    getAttribute(name) {
        return this.attributes.has(name) ? this.attributes.get(name) : null;
    }

    hasAttribute(name) {
        return this.attributes.has(name);
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }

    insertBefore(node, boundary) {
        node.remove();
        node.parentNode = this;
        const index = this.childNodes.indexOf(boundary);
        this.childNodes.splice(index < 0 ? this.childNodes.length : index, 0, node);
    }

    contains(node) {
        for (let current = node; current; current = current.parentNode) {
            if (current === this) {
                return true;
            }
        }
        return false;
    }

    closest(selector) {
        for (let current = this; current instanceof FakeHTMLElement; current = current.parentNode) {
            if (current.matches(selector)) {
                return current;
            }
        }
        return null;
    }

    getBoundingClientRect() {
        return this.rect;
    }

    hasChildNodes() {
        return this.childNodes.length > 0;
    }

    matches(selector) {
        if (selector === '.post-card.is-editing > .post.body[data-post-inplace-body]') {
            return this.isEditingBody === true;
        }
        if (selector === '.post-picture, .post-media-picture, figure') {
            return this.isMediaWrapper === true;
        }
        if (selector === '.post-media-picture') {
            return this.isMediaWrapper === true;
        }
        return false;
    }

    querySelector(selector) {
        return selector === 'img, video, audio' && this.media ? {} : null;
    }

    querySelectorAll(selector) {
        if (selector === 'tt') {
            const result = [];
            const visit = (element) => {
                element.childNodes.forEach((child) => {
                    if (child instanceof FakeHTMLElement) {
                        if (child.tagName === 'TT') {
                            result.push(child);
                        }
                        visit(child);
                    }
                });
            };
            visit(this);
            return result;
        }
        if (selector === '[data-post-editor-leading-boundary]') {
            const result = [];
            const visit = (element) => {
                element.childNodes.forEach((child) => {
                    if (child instanceof FakeHTMLElement) {
                        if (child.hasAttribute('data-post-editor-leading-boundary')) {
                            result.push(child);
                        }
                        visit(child);
                    }
                });
            };
            visit(this);
            return result;
        }
        if (selector === '[data-post-inline-code-exit]') {
            const result = [];
            const visit = (element) => {
                element.childNodes.forEach((child) => {
                    if (child instanceof FakeHTMLElement) {
                        if (child.hasAttribute('data-post-inline-code-exit')) {
                            result.push(child);
                        }
                        visit(child);
                    }
                });
            };
            visit(this);
            return result;
        }
        if (selector === '.post-media-picture') {
            const result = [];
            const visit = (element) => {
                element.childNodes.forEach((child) => {
                    if (child instanceof FakeHTMLElement) {
                        if (child.isMediaWrapper) {
                            result.push(child);
                        }
                        visit(child);
                    }
                });
            };
            visit(this);
            return result;
        }
        return [];
    }
}

class FakeHTMLBRElement extends FakeHTMLElement {}

function createHarness() {
    const listeners = new Map();
    const elements = [];
    const commands = [];
    let selection = null;
    const document = {
        activeElement: null,
        currentScript: {src: 'https://example.test/_assets/register/post-inplace.js?v=1'},
        documentElement: {lang: 'en'},
        getElementById() { return null; },
        addEventListener: function (type, listener) {
            const registered = listeners.get(type) || [];
            registered.push(listener);
            listeners.set(type, registered);
        },
        createElement: function (tagName) {
            const normalizedTag = String(tagName).toUpperCase();
            const element = normalizedTag === 'BR'
                ? new FakeHTMLBRElement({tagName: normalizedTag})
                : new FakeHTMLElement({tagName: normalizedTag});
            elements.push(element);
            return element;
        },
        createTextNode: function (text) {
            return new FakeTextNode(null, text);
        },
        createRange: function () {
            return {
                collapsed: false,
                startBefore: null,
                collapse: function (toStart) {
                    this.collapsed = true;
                    if (this.selectedNode && toStart) {
                        this.startContainer = this.selectedNode;
                        this.startOffset = 0;
                    }
                    this.endContainer = this.startContainer;
                    this.endOffset = this.startOffset;
                    this.commonAncestorContainer = this.startContainer;
                },
                selectNodeContents: function (node) {
                    this.selectedNode = node;
                    this.startContainer = node;
                    this.startOffset = 0;
                    this.endContainer = node;
                    this.endOffset = node.childNodes?.length || 0;
                    this.commonAncestorContainer = node;
                },
                setStart: function (node, offset) {
                    this.startContainer = node;
                    this.startOffset = offset;
                },
                setStartBefore: function (node) {
                    this.startBefore = node;
                    this.startContainer = node.parentNode;
                    this.startOffset = node.parentNode.childNodes.indexOf(node);
                },
                setStartAfter: function (node) {
                    this.startContainer = node.parentNode;
                    this.startOffset = node.parentNode.childNodes.indexOf(node) + 1;
                }
            };
        },
        execCommand(command, ui, value) {
            commands.push({command, value, range: selection.getRangeAt(0)});
            return true;
        },
        querySelectorAll: function (selector) {
            if (selector === '.has-leading-boundary-caret') {
                return elements.filter((element) => (
                    element.classList.contains('has-leading-boundary-caret')
                ));
            }
            if (selector === '.uses-synthetic-boundary-caret') {
                return elements.filter((element) => (
                    element.classList.contains('uses-synthetic-boundary-caret')
                ));
            }
            return [];
        }
    };
    const window = {
        location: {href: 'https://example.test/post'},
        addEventListener: function () {},
        getSelection: function () { return selection; },
        requestAnimationFrame: function (callback) { callback(); },
        setTimeout: setTimeout
    };
    const context = vm.createContext({
        AbortController,
        DOMException,
        Element: FakeHTMLElement,
        HTMLBRElement: FakeHTMLBRElement,
        HTMLButtonElement: FakeHTMLElement,
        HTMLFormElement: class extends FakeHTMLElement {},
        HTMLElement: FakeHTMLElement,
        Node: FakeNode,
        URL,
        console,
        document,
        navigator: {platform: 'Linux'},
        setTimeout,
        window
    });

    new vm.Script(testableEditorSource, {filename: 'post-inplace.js'}).runInContext(context);

    return {
        document,
        elements,
        commands,
        helpers: context.window.__postInplaceTest,
        currentRange() {
            return selection?.rangeCount === 1 ? selection.getRangeAt(0) : null;
        },
        beforeInput(target, inputType = 'insertText') {
            const [listener] = listeners.get('beforeinput') || [];
            assert.equal(typeof listener, 'function');
            listener({inputType, target});
            return selection.getRangeAt(0);
        },
        select(startContainer, startOffset = 0) {
            let range = {
                collapsed: true,
                startContainer,
                startOffset,
                endContainer: startContainer,
                endOffset: startOffset,
            };
            selection = {
                get rangeCount() { return range ? 1 : 0; },
                addRange: function (nextRange) { range = nextRange; },
                getRangeAt: function () {
                    return range;
                },
                removeAllRanges: function () { range = null; }
            };
        },
        sync() {
            const [listener] = listeners.get('selectionchange') || [];
            assert.equal(typeof listener, 'function');
            listener();
        }
    };
}

test('block style detection recognizes a quote for its text and trailing caret', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    const paragraph = new FakeHTMLElement({tagName: 'P'});
    const quote = new FakeHTMLElement({tagName: 'BLOCKQUOTE'});
    const trailing = new FakeHTMLElement({tagName: 'P'});
    const paragraphText = new FakeTextNode(null, 'Before');
    const quoteText = new FakeTextNode(null, 'Quoted');
    const trailingText = new FakeTextNode(null, 'After');
    paragraph.append(paragraphText);
    quote.append(quoteText);
    trailing.append(trailingText);
    body.append(paragraph);
    body.append(quote);
    body.append(trailing);

    assert.equal(harness.helpers.contextBlockStyle(body, {
        collapsed: false,
        startContainer: quoteText,
        startOffset: 0,
        endContainer: quoteText,
        endOffset: quoteText.data.length,
    }), 'quote');
    assert.equal(harness.helpers.contextBlockStyle(body, {
        collapsed: true,
        startContainer: body,
        startOffset: 2,
        endContainer: body,
        endOffset: 2,
    }), 'quote');
    assert.equal(harness.helpers.contextBlockStyle(body, {
        collapsed: false,
        startContainer: paragraphText,
        startOffset: 0,
        endContainer: quoteText,
        endOffset: quoteText.data.length,
    }), null);

    const innerParagraph = new FakeHTMLElement({tagName: 'P'});
    innerParagraph.append(quoteText);
    quote.append(innerParagraph);
    assert.equal(harness.helpers.contextBlockStyle(body, {
        collapsed: true,
        startContainer: quoteText,
        startOffset: 2,
        endContainer: quoteText,
        endOffset: 2,
    }), 'quote');
});

test('a quote at the caret is detected only at the visual end of its content', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    const quote = new FakeHTMLElement({tagName: 'BLOCKQUOTE'});
    const quoteText = new FakeTextNode(null, 'Quoted');
    quote.append(quoteText);
    body.append(quote);
    const selection = (remainingText) => ({
        rangeCount: 1,
        getRangeAt: () => ({
            collapsed: true,
            startContainer: quoteText,
            startOffset: quoteText.data.length,
            endContainer: quoteText,
            endOffset: quoteText.data.length,
            commonAncestorContainer: quoteText,
            cloneRange: () => ({
                setEnd() {},
                cloneContents: () => ({
                    textContent: remainingText,
                    querySelector: () => null,
                }),
            }),
        }),
    });

    assert.equal(harness.helpers.quoteAtCaret(body, selection('')), quote);
    assert.equal(harness.helpers.quoteAtCaret(body, selection('more')), null);
});

test('context menu marks the current quote control as pressed', function () {
    const harness = createHarness();
    const actions = ['paragraph', 'h2', 'h3', 'h4', 'quote', 'code'];
    const buttons = Object.fromEntries(actions.map((action) => [
        action,
        new FakeHTMLElement({tagName: 'BUTTON'}),
    ]));
    const menu = {
        querySelector(selector) {
            const match = selector.match(/data-context-action="([^"]+)"/u);
            return match ? buttons[match[1]] : null;
        },
    };

    harness.helpers.applyContextBlockState(menu, 'quote');
    assert.equal(buttons.quote.classList.contains('is-active'), true);
    assert.equal(buttons.quote.getAttribute('aria-pressed'), 'true');
    assert.equal(buttons.paragraph.classList.contains('is-active'), false);
    assert.equal(buttons.paragraph.getAttribute('aria-pressed'), 'false');

    harness.helpers.applyContextBlockState(menu, 'paragraph');
    assert.equal(buttons.quote.classList.contains('is-active'), false);
    assert.equal(buttons.quote.getAttribute('aria-pressed'), 'false');
    assert.equal(buttons.paragraph.getAttribute('aria-pressed'), 'true');
});

test('Enter after a quote inserts a paragraph outside it through a native undo transaction', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    const quote = new FakeHTMLElement({tagName: 'BLOCKQUOTE'});
    const quoteText = new FakeTextNode(null, 'Quoted');
    quote.append(quoteText);
    body.append(quote);
    const next = new FakeHTMLElement({tagName: 'P'});
    body.append(next);
    harness.select(quoteText, quoteText.data.length);
    Object.assign(harness.currentRange(), {
        commonAncestorContainer: quoteText,
        cloneRange: () => ({
            setEnd() {},
            cloneContents: () => ({textContent: '', querySelector: () => null}),
        }),
    });
    const state = {body, form: new FakeHTMLElement(), card: new FakeHTMLElement()};
    let prevented = false;
    const event = {key: 'Enter', preventDefault() { prevented = true; }};

    assert.equal(harness.helpers.exitQuoteOnEnter({...event, shiftKey: true}, state), false);
    assert.equal(harness.helpers.exitQuoteOnEnter({...event, isComposing: true}, state), false);
    assert.equal(harness.helpers.exitQuoteOnEnter({...event, key: 'ArrowDown'}, state), false);
    assert.equal(harness.commands.length, 0);
    assert.equal(prevented, false);

    assert.equal(harness.helpers.exitQuoteOnEnter(event, state), true);
    assert.equal(prevented, true);
    assert.equal(state.bodyDirty, true);
    assert.equal(harness.commands.length, 1);
    assert.equal(harness.commands[0].command, 'insertHTML');
    assert.equal(harness.commands[0].value, '<p><br></p>');
    assert.equal(harness.commands[0].range.startContainer, body);
    assert.equal(harness.commands[0].range.startOffset, 1);
    assert.equal(harness.commands[0].range.collapsed, true);
    assert.deepEqual(body.childNodes, [quote, next]);
});

test('Enter in an empty quote changes it into a paragraph without adding another block', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    const quote = new FakeHTMLElement({tagName: 'BLOCKQUOTE'});
    body.append(quote);
    harness.select(quote);
    Object.assign(harness.currentRange(), {
        commonAncestorContainer: quote,
        cloneRange: () => ({
            setEnd() {},
            cloneContents: () => ({textContent: '', querySelector: () => null}),
        }),
    });
    const state = {body, form: new FakeHTMLElement(), card: new FakeHTMLElement()};

    assert.equal(harness.helpers.exitQuoteOnEnter({key: 'Enter', preventDefault() {}}, state), true);
    assert.equal(harness.commands.length, 1);
    assert.equal(harness.commands[0].command, 'formatBlock');
    assert.equal(harness.commands[0].value, 'p');
    assert.equal(harness.commands[0].range.startContainer, quote);
});

test('Enter at the end of block code starts a normal paragraph and Shift+Enter stays in code', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    const code = new FakeHTMLElement({tagName: 'PRE'});
    const codeText = new FakeTextNode(null, "first\nsecond");
    code.append(codeText);
    body.append(code);
    harness.select(codeText, codeText.data.length);
    Object.assign(harness.currentRange(), {
        commonAncestorContainer: codeText,
        cloneRange: () => ({
            setEnd() {},
            cloneContents: () => ({textContent: '', querySelector: () => null}),
        }),
    });
    const state = {body, form: new FakeHTMLElement(), card: new FakeHTMLElement()};
    let prevented = false;
    const event = {key: 'Enter', preventDefault() { prevented = true; }};

    assert.equal(harness.helpers.exitStyledBlockOnEnter({...event, shiftKey: true}, state), false);
    assert.equal(harness.commands.length, 0);

    assert.equal(harness.helpers.exitStyledBlockOnEnter(event, state), true);
    assert.equal(prevented, true);
    assert.equal(state.bodyDirty, true);
    assert.equal(harness.commands.length, 1);
    assert.equal(harness.commands[0].command, 'insertHTML');
    assert.equal(harness.commands[0].value, '<p><br></p>');
    assert.equal(harness.commands[0].range.startContainer, body);
    assert.equal(harness.commands[0].range.startOffset, 1);
});

test('Enter before remaining block-code text does not leave the block', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    const code = new FakeHTMLElement({tagName: 'PRE'});
    const codeText = new FakeTextNode(null, 'first\nsecond');
    code.append(codeText);
    body.append(code);
    harness.select(codeText, 5);
    Object.assign(harness.currentRange(), {
        commonAncestorContainer: codeText,
        cloneRange: () => ({
            setEnd() {},
            cloneContents: () => ({textContent: '\nsecond', querySelector: () => null}),
        }),
    });
    const state = {body, form: new FakeHTMLElement(), card: new FakeHTMLElement()};

    assert.equal(
        harness.helpers.exitStyledBlockOnEnter({key: 'Enter', preventDefault() {}}, state),
        false,
    );
    assert.equal(harness.commands.length, 0);
});

test('adjacent inline-code elements are merged into one formatting run', function () {
    const harness = createHarness();
    const root = new FakeHTMLElement();
    const firstCode = new FakeHTMLElement({parentNode: root, tagName: 'TT'});
    const emptyText = new FakeTextNode(root, '');
    const secondCode = new FakeHTMLElement({parentNode: root, tagName: 'TT'});
    const firstText = new FakeTextNode(firstCode, '&ap');
    const secondText = new FakeTextNode(secondCode, 'os;');
    firstCode.childNodes.push(firstText);
    secondCode.childNodes.push(secondText);
    root.childNodes.push(firstCode, emptyText, secondCode);

    harness.helpers.mergeAdjacentInlineCode(root);

    assert.equal(root.childNodes.length, 1);
    assert.equal(root.childNodes[0], firstCode);
    assert.equal(firstCode.childNodes.length, 2);
    assert.equal(firstCode.childNodes[0], firstText);
    assert.equal(firstCode.childNodes[1], secondText);
    assert.equal(secondText.parentNode, firstCode);
});

test('ArrowRight at the end of inline code moves the caret outside without changing content', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    const paragraph = new FakeHTMLElement({tagName: 'P'});
    const inlineCode = new FakeHTMLElement({tagName: 'TT'});
    const codeText = new FakeTextNode(null, 'ПЕСНЯ:1Т');
    inlineCode.append(codeText);
    paragraph.append(inlineCode);
    body.append(paragraph);
    harness.select(codeText, codeText.data.length);
    Object.assign(harness.currentRange(), {
        commonAncestorContainer: codeText,
        cloneRange: () => ({
            setEnd() {},
            cloneContents: () => ({textContent: ''}),
        }),
    });
    const state = {body, form: new FakeHTMLElement(), card: new FakeHTMLElement()};
    let prevented = false;
    let stopped = false;

    assert.equal(harness.helpers.inlineCodeAtCaretEnd(body, {
        rangeCount: 1,
        getRangeAt: () => harness.currentRange(),
    }), inlineCode);
    assert.equal(harness.helpers.moveAfterInlineCode({
        key: 'ArrowRight',
        preventDefault() { prevented = true; },
        stopPropagation() { stopped = true; },
    }, state), true);

    assert.equal(prevented, true);
    assert.equal(stopped, true);
    const marker = paragraph.childNodes[1];
    assert.equal(marker.tagName, 'SPAN');
    assert.equal(marker.hasAttribute('data-post-inline-code-exit'), true);
    assert.equal(marker.getAttribute('contenteditable'), 'false');
    assert.equal(marker.textContent, '');
    assert.equal(harness.currentRange().startContainer, paragraph);
    assert.equal(harness.currentRange().startOffset, 2);
    assert.equal(paragraph.childNodes.length, 2);
    assert.equal(harness.commands.length, 0);
    assert.equal(state.bodyDirty, undefined);
    assert.equal(body.textContent, 'ПЕСНЯ:1Т');
});

test('inline-code exit does not intercept navigation before its visual end or with modifiers', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    const paragraph = new FakeHTMLElement({tagName: 'P'});
    const inlineCode = new FakeHTMLElement({tagName: 'TT'});
    const codeText = new FakeTextNode(null, 'code');
    inlineCode.append(codeText);
    paragraph.append(inlineCode);
    body.append(paragraph);
    harness.select(codeText, 2);
    Object.assign(harness.currentRange(), {
        commonAncestorContainer: codeText,
        cloneRange: () => ({
            setEnd() {},
            cloneContents: () => ({textContent: 'de'}),
        }),
    });
    const state = {body, form: new FakeHTMLElement(), card: new FakeHTMLElement()};

    assert.equal(harness.helpers.moveAfterInlineCode({
        key: 'ArrowRight',
        preventDefault() {},
        stopPropagation() {},
    }, state), false);
    assert.equal(harness.helpers.moveAfterInlineCode({
        key: 'ArrowRight',
        metaKey: true,
        preventDefault() {},
        stopPropagation() {},
    }, state), false);
    assert.equal(harness.helpers.moveAfterInlineCode({
        key: 'ArrowLeft',
        preventDefault() {},
        stopPropagation() {},
    }, state), false);
    assert.equal(harness.currentRange().startContainer, codeText);
    assert.equal(harness.currentRange().startOffset, 2);
});

test('inline-code exit uses existing normal text without adding a marker', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    const paragraph = new FakeHTMLElement({tagName: 'P'});
    const inlineCode = new FakeHTMLElement({tagName: 'TT'});
    const codeText = new FakeTextNode(null, 'code');
    const normalText = new FakeTextNode(null, ' after');
    inlineCode.append(codeText);
    paragraph.append(inlineCode);
    paragraph.append(normalText);
    body.append(paragraph);
    harness.select(codeText, codeText.data.length);
    Object.assign(harness.currentRange(), {
        commonAncestorContainer: codeText,
        cloneRange: () => ({
            setEnd() {},
            cloneContents: () => ({textContent: ''}),
        }),
    });
    const state = {body, form: new FakeHTMLElement(), card: new FakeHTMLElement()};

    assert.equal(harness.helpers.moveAfterInlineCode({
        key: 'ArrowRight',
        preventDefault() {},
        stopPropagation() {},
    }, state), true);

    assert.deepEqual(paragraph.childNodes, [inlineCode, normalText]);
    assert.equal(harness.currentRange().startContainer, normalText);
    assert.equal(harness.currentRange().startOffset, 0);
});

test('inline-code exit markers are removed before editor HTML is serialized', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    const paragraph = new FakeHTMLElement({tagName: 'P'});
    const inlineCode = new FakeHTMLElement({tagName: 'TT'});
    inlineCode.append(new FakeTextNode(null, 'code'));
    const marker = new FakeHTMLElement({tagName: 'SPAN'});
    marker.setAttribute('data-post-inline-code-exit', '');
    const normalText = new FakeTextNode(null, ' after');
    paragraph.append(inlineCode);
    paragraph.append(marker);
    paragraph.append(normalText);
    body.append(paragraph);

    harness.helpers.removeInlineCodeExitMarkers(body);

    assert.deepEqual(paragraph.childNodes, [inlineCode, normalText]);
    assert.equal(body.textContent, 'code after');
});

test('empty media shells and their trailing caret paragraphs are not serialized', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    const content = new FakeHTMLElement({tagName: 'P'});
    content.append(new FakeTextNode(null, 'Published text'));
    const validMedia = new FakeHTMLElement({media: true});
    validMedia.isMediaWrapper = true;
    const emptyBeforeShell = new FakeHTMLElement({tagName: 'P'});
    const emptyShell = new FakeHTMLElement();
    emptyShell.isMediaWrapper = true;
    emptyShell.append(new FakeHTMLBRElement({tagName: 'BR'}));
    const emptyAfterShell = new FakeHTMLElement({tagName: 'P'});
    emptyAfterShell.append(new FakeHTMLBRElement({tagName: 'BR'}));
    body.append(content);
    body.append(validMedia);
    body.append(emptyBeforeShell);
    body.append(emptyShell);
    body.append(emptyAfterShell);

    harness.helpers.removeTrailingEditorArtifacts(body);

    assert.deepEqual(body.childNodes, [content, validMedia]);
    assert.equal(emptyBeforeShell.parentNode, null);
    assert.equal(emptyShell.parentNode, null);
    assert.equal(emptyAfterShell.parentNode, null);
});

test('the synthetic media-boundary caret is unique and stale copies are cleared before input', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement({rect: {left: 20, top: 40}});
    body.isEditingBody = true;
    const media = new FakeHTMLElement({
        parentNode: body,
        media: true,
        rect: {left: 120, top: 240}
    });
    media.isMediaWrapper = true;
    media.childNodes.push(new FakeHTMLElement({parentNode: media}));
    body.childNodes.push(media);

    const staleFirst = new FakeHTMLElement({
        parentNode: body,
        classes: ['post-picture', 'post-media-picture', 'has-leading-boundary-caret']
    });
    const staleSecond = new FakeHTMLElement({
        parentNode: body,
        classes: ['post-picture', 'post-media-picture', 'has-leading-boundary-caret']
    });
    harness.elements.push(body, media, staleFirst, staleSecond);
    harness.document.activeElement = body;

    harness.select(media, 0);
    harness.sync();

    assert.equal(staleFirst.classList.contains('has-leading-boundary-caret'), false);
    assert.equal(staleSecond.classList.contains('has-leading-boundary-caret'), false);
    assert.equal(media.classList.contains('has-leading-boundary-caret'), true);
    assert.equal(body.classList.contains('has-leading-boundary-caret'), false);
    assert.equal(
        harness.elements.filter((element) => (
            element.classList.contains('has-leading-boundary-caret')
        )).length,
        1
    );

    const insertionRange = harness.beforeInput(body);
    const insertionParagraph = body.childNodes[0];
    assert.equal(insertionParagraph.tagName, 'P');
    assert.equal(insertionParagraph.childNodes[0].tagName, 'BR');
    assert.equal(body.childNodes[1], media);
    assert.equal(insertionRange.startBefore, null);
    assert.equal(insertionRange.startContainer, insertionParagraph);
    assert.equal(insertionRange.startOffset, 0);
    assert.equal(media.classList.contains('has-leading-boundary-caret'), false);

    const typedText = new FakeTextNode(staleFirst, 'Сегодня');
    harness.select(typedText, typedText.textContent.length);
    harness.sync();

    assert.equal(
        harness.elements.some((element) => element.classList.contains('has-leading-boundary-caret')),
        false
    );
});

test('the synthetic caret remains visible at the empty editor root', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement({rect: {left: 20, top: 40}});
    body.isEditingBody = true;
    body.childNodes.push(new FakeHTMLBRElement({parentNode: body}));
    harness.elements.push(body);
    harness.document.activeElement = body;

    harness.select(body, 0);
    harness.sync();

    assert.equal(body.classList.contains('has-leading-boundary-caret'), true);
    assert.equal(body.classList.contains('uses-synthetic-boundary-caret'), true);
});

test('typing at the editor root before leading media creates a paragraph', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    body.isEditingBody = true;
    const media = new FakeHTMLElement({parentNode: body, media: true});
    media.isMediaWrapper = true;
    media.childNodes.push(new FakeHTMLElement({parentNode: media}));
    body.childNodes.push(media);
    harness.elements.push(body, media);
    harness.document.activeElement = body;

    harness.select(body, 0);
    const insertionRange = harness.beforeInput(body);

    const insertionParagraph = body.childNodes[0];
    assert.equal(insertionParagraph.tagName, 'P');
    assert.equal(insertionParagraph.childNodes[0].tagName, 'BR');
    assert.equal(body.childNodes[1], media);
    assert.equal(insertionRange.startContainer, insertionParagraph);
    assert.equal(insertionRange.startOffset, 0);
});

test('leading media can receive a caret before it without adding layout content', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    body.isEditingBody = true;
    body.focus = function () {
        harness.document.activeElement = body;
    };
    const media = new FakeHTMLElement({parentNode: body, media: true});
    media.isMediaWrapper = true;
    media.childNodes.push(new FakeHTMLElement({parentNode: media}));
    body.childNodes.push(media);
    harness.elements.push(body, media);
    harness.select(media, 0);

    const focused = harness.helpers.focusBeforeLeadingMedia(body, media);
    const range = harness.currentRange();

    assert.equal(focused, media);
    assert.deepEqual(body.childNodes, [media]);
    assert.equal(harness.document.activeElement, body);
    assert.equal(range.startContainer, body);
    assert.equal(range.startOffset, 0);
    assert.equal(body.classList.contains('has-leading-boundary-caret'), true);
    assert.equal(body.classList.contains('uses-synthetic-boundary-caret'), true);
});

test('moving after media creates one real editor paragraph and keeps focus in the body', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    body.isEditingBody = true;
    body.focus = function () {
        harness.document.activeElement = body;
    };
    const media = new FakeHTMLElement({parentNode: body, media: true});
    media.isMediaWrapper = true;
    media.childNodes.push(new FakeHTMLElement({parentNode: media}));
    body.childNodes.push(media);
    harness.elements.push(body, media);
    harness.select(body, 0);

    const paragraph = harness.helpers.focusAfterMedia(body, media);

    assert.equal(paragraph.tagName, 'P');
    assert.equal(paragraph.childNodes[0].tagName, 'BR');
    assert.deepEqual(body.childNodes, [media, paragraph]);
    assert.equal(harness.document.activeElement, body);
    assert.equal(harness.currentRange().startContainer, paragraph);
    assert.equal(harness.currentRange().startOffset, 0);
});

test('media insertion replaces the empty editor paragraph without leaving a visual gap', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    const paragraph = new FakeHTMLElement({parentNode: body, tagName: 'P'});
    paragraph.childNodes.push(new FakeHTMLBRElement({parentNode: paragraph}));
    body.childNodes.push(paragraph);
    const range = harness.document.createRange();
    range.setStart(paragraph, 0);
    range.collapse(true);

    harness.helpers.prepareMediaInsertionRange(body, range);

    assert.equal(range.startContainer, body);
    assert.equal(range.startOffset, 0);
    assert.deepEqual(body.childNodes, []);
    assert.equal(paragraph.parentNode, null);
});

test('arrow up from an empty leading image caption moves the caret before the image', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    body.isEditingBody = true;
    body.focus = function () {
        harness.document.activeElement = body;
    };
    body.setAttribute('contenteditable', 'true');
    const media = new FakeHTMLElement({parentNode: body, media: true});
    media.isMediaWrapper = true;
    const caption = new FakeHTMLElement({parentNode: media});
    caption.textContent = '';
    media.childNodes.push(caption);
    body.childNodes.push(media);
    harness.elements.push(body, media, caption);
    harness.select(caption, 0);

    const controller = new AbortController();
    const state = {
        body,
        card: {dataset: {}},
        mediaCaptionEditors: new Map([[caption, {controller, original: ''}]]),
    };
    const event = {
        key: 'ArrowUp',
        altKey: false,
        ctrlKey: false,
        metaKey: false,
        shiftKey: false,
        isComposing: false,
        defaultPrevented: false,
        propagationStopped: false,
        preventDefault() { this.defaultPrevented = true; },
        stopPropagation() { this.propagationStopped = true; },
    };

    assert.equal(harness.helpers.moveFromLeadingMediaCaption(event, state, caption), true);
    assert.equal(event.defaultPrevented, true);
    assert.equal(event.propagationStopped, true);
    assert.equal(state.mediaCaptionEditors.size, 0);
    assert.equal(body.getAttribute('contenteditable'), 'true');
    assert.deepEqual(body.childNodes, [media]);
    assert.equal(harness.document.activeElement, body);
    assert.equal(harness.currentRange().startContainer, body);
    assert.equal(harness.currentRange().startOffset, 0);
    assert.equal(body.classList.contains('has-leading-boundary-caret'), true);
});

test('arrow down from an empty image caption moves to a visible paragraph after the image', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    body.isEditingBody = true;
    body.focus = function () {
        harness.document.activeElement = body;
    };
    body.setAttribute('contenteditable', 'true');
    const media = new FakeHTMLElement({parentNode: body, media: true});
    media.isMediaWrapper = true;
    const caption = new FakeHTMLElement({parentNode: media});
    caption.textContent = '';
    media.childNodes.push(caption);
    body.childNodes.push(media);
    harness.elements.push(body, media, caption);
    harness.select(caption, 0);

    const controller = new AbortController();
    const state = {
        body,
        card: {dataset: {}},
        mediaCaptionEditors: new Map([[caption, {controller, original: ''}]]),
    };
    const event = {
        key: 'ArrowDown',
        altKey: false,
        ctrlKey: false,
        metaKey: false,
        shiftKey: false,
        isComposing: false,
        defaultPrevented: false,
        propagationStopped: false,
        preventDefault() { this.defaultPrevented = true; },
        stopPropagation() { this.propagationStopped = true; },
    };

    assert.equal(harness.helpers.moveFromInlineMediaCaption(event, state, caption), true);
    assert.equal(event.defaultPrevented, true);
    assert.equal(event.propagationStopped, true);
    assert.equal(state.mediaCaptionEditors.size, 0);
    assert.equal(body.getAttribute('contenteditable'), 'true');
    assert.equal(body.childNodes[1].tagName, 'P');
    assert.equal(harness.document.activeElement, body);
    assert.equal(harness.currentRange().startContainer, body.childNodes[1]);
    assert.equal(harness.currentRange().startOffset, 0);
});

test('repeated boundary and caption navigation keeps exactly one visible caret', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    body.isEditingBody = true;
    body.setAttribute('contenteditable', 'true');
    body.focus = function () {
        harness.document.activeElement = body;
    };
    const media = new FakeHTMLElement({parentNode: body, media: true});
    media.isMediaWrapper = true;
    const caption = new FakeHTMLElement({parentNode: media});
    caption.textContent = '';
    caption.isConnected = true;
    caption.focus = function () {
        harness.document.activeElement = caption;
    };
    media.childNodes.push(caption);
    media.querySelector = function (selector) {
        if (selector === 'img, video, audio') {
            return {};
        }
        return selector.includes('.post-caption') ? caption : null;
    };
    body.childNodes.push(media);
    harness.elements.push(body, media, caption);
    harness.select(body, 0);
    body.classList.add('has-leading-boundary-caret', 'uses-synthetic-boundary-caret');

    const state = {
        body,
        card: {dataset: {}},
        mediaCaptionEditors: new Map(),
    };
    const event = {
        key: 'ArrowDown',
        target: body,
        altKey: false,
        ctrlKey: false,
        metaKey: false,
        shiftKey: false,
        isComposing: false,
        defaultPrevented: false,
        propagationStopped: false,
        preventDefault() { this.defaultPrevented = true; },
        stopPropagation() { this.propagationStopped = true; },
    };

    assert.equal(harness.helpers.moveFromBodyMediaBoundary(event, state), true);
    assert.equal(event.defaultPrevented, true);
    assert.equal(event.propagationStopped, true);
    assert.equal(state.mediaCaptionEditors.size, 1);
    assert.equal(harness.document.activeElement, caption);
    assert.equal(caption.classList.contains('is-editing-inline-caption'), true);
    assert.equal(body.getAttribute('contenteditable'), 'true');
    assert.equal(body.classList.contains('has-leading-boundary-caret'), false);
    assert.equal(body.classList.contains('uses-synthetic-boundary-caret'), false);
    assert.equal(
        harness.elements.filter((element) => element.classList.contains('has-leading-boundary-caret')).length,
        0,
    );
});

test('a real empty paragraph uses the browser caret instead of a full-height synthetic one', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    body.isEditingBody = true;
    const paragraph = new FakeHTMLElement({parentNode: body, tagName: 'P'});
    paragraph.childNodes.push(new FakeHTMLBRElement({parentNode: paragraph}));
    const media = new FakeHTMLElement({parentNode: body, media: true});
    media.isMediaWrapper = true;
    media.childNodes.push(new FakeHTMLElement({parentNode: media}));
    body.childNodes.push(paragraph, media);
    harness.elements.push(body, paragraph, media);
    harness.document.activeElement = body;

    harness.select(paragraph, 0);
    harness.sync();

    assert.equal(paragraph.classList.contains('has-leading-boundary-caret'), false);
    assert.equal(media.classList.contains('has-leading-boundary-caret'), false);
    assert.equal(body.classList.contains('uses-synthetic-boundary-caret'), false);
    assert.equal(
        harness.elements.filter((element) => (
            element.classList.contains('has-leading-boundary-caret')
        )).length,
        0
    );

    const typedText = new FakeTextNode(paragraph, 'Сегодня');
    paragraph.childNodes.push(typedText);
    harness.select(typedText, typedText.textContent.length);
    harness.sync();

    assert.equal(paragraph.classList.contains('has-leading-boundary-caret'), false);
    assert.equal(body.classList.contains('uses-synthetic-boundary-caret'), false);
});

test('deleting the last text before media restores the gapless boundary caret', function () {
    const harness = createHarness();
    const body = new FakeHTMLElement();
    body.isEditingBody = true;
    body.focus = function () {
        harness.document.activeElement = body;
    };
    const paragraph = new FakeHTMLElement({parentNode: body, tagName: 'P'});
    paragraph.childNodes.push(new FakeHTMLBRElement({parentNode: paragraph}));
    const media = new FakeHTMLElement({parentNode: body, media: true});
    media.isMediaWrapper = true;
    media.childNodes.push(new FakeHTMLElement({parentNode: media}));
    body.childNodes.push(paragraph, media);
    harness.elements.push(body, paragraph, media);
    harness.document.activeElement = body;
    harness.select(paragraph, 0);

    assert.equal(
        harness.helpers.collapseEmptyLeadingParagraphAfterDelete(
            {inputType: 'deleteContentBackward'},
            body,
        ),
        true,
    );
    assert.deepEqual(body.childNodes, [media]);
    assert.equal(paragraph.parentNode, null);
    assert.equal(harness.currentRange().startContainer, body);
    assert.equal(harness.currentRange().startOffset, 0);
    assert.equal(body.classList.contains('has-leading-boundary-caret'), true);
    assert.equal(body.classList.contains('uses-synthetic-boundary-caret'), true);
});
