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
        '        contextMenuAnchorRange,',
        '        mergeAdjacentInlineCode,',
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
        this.media = media;
        this.rect = rect;
        this.tagName = tagName;
    }

    get firstChild() {
        return this.childNodes[0] || null;
    }

    append(node) {
        node.remove();
        node.parentNode = this;
        this.childNodes.push(node);
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
        return [];
    }
}

class FakeHTMLBRElement extends FakeHTMLElement {}

function createHarness() {
    const listeners = new Map();
    const elements = [];
    let selection = null;
    const document = {
        activeElement: null,
        currentScript: {src: 'https://example.test/_assets/register/post-inplace.js?v=1'},
        documentElement: {lang: 'en'},
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
        createRange: function () {
            return {
                collapsed: false,
                startBefore: null,
                collapse: function () { this.collapsed = true; },
                setStart: function (node, offset) {
                    this.startContainer = node;
                    this.startOffset = offset;
                },
                setStartBefore: function (node) {
                    this.startBefore = node;
                    this.startContainer = node.parentNode;
                    this.startOffset = node.parentNode.childNodes.indexOf(node);
                }
            };
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
        setTimeout: setTimeout
    };
    const context = vm.createContext({
        AbortController,
        DOMException,
        HTMLBRElement: FakeHTMLBRElement,
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
        helpers: context.window.__postInplaceTest,
        beforeInput(target, inputType = 'insertText') {
            const [listener] = listeners.get('beforeinput') || [];
            assert.equal(typeof listener, 'function');
            listener({inputType, target});
            return selection.getRangeAt(0);
        },
        select(startContainer, startOffset = 0) {
            let range = {collapsed: true, startContainer, startOffset};
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

test('a selected range anchors the context menu at its boundary instead of the pointer', function () {
    const harness = createHarness();
    const clone = {kind: 'selection clone'};
    const range = {
        cloneRange: function () {
            return clone;
        }
    };

    assert.equal(
        harness.helpers.contextMenuAnchorRange({}, range, true, {clientX: 120, clientY: 240}),
        clone
    );
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

test('an empty paragraph before media has exactly one synthetic caret', function () {
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

    assert.equal(paragraph.classList.contains('has-leading-boundary-caret'), true);
    assert.equal(media.classList.contains('has-leading-boundary-caret'), false);
    assert.equal(body.classList.contains('uses-synthetic-boundary-caret'), true);
    assert.equal(
        harness.elements.filter((element) => (
            element.classList.contains('has-leading-boundary-caret')
        )).length,
        1
    );

    const typedText = new FakeTextNode(paragraph, 'Сегодня');
    paragraph.childNodes.push(typedText);
    harness.select(typedText, typedText.textContent.length);
    harness.sync();

    assert.equal(paragraph.classList.contains('has-leading-boundary-caret'), false);
    assert.equal(body.classList.contains('uses-synthetic-boundary-caret'), false);
});
