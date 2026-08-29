import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const editorSource = await readFile(
    new URL('../../_assets/register/post-inplace.js', import.meta.url),
    'utf8'
);

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
}

class FakeTextNode extends FakeNode {
    constructor(parentNode, textContent) {
        super(parentNode);
        this.nodeType = FakeNode.TEXT_NODE;
        this.textContent = textContent;
    }
}

class FakeHTMLElement extends FakeNode {
    constructor({parentNode = null, classes = [], media = false, rect = {left: 0, top: 0}} = {}) {
        super(parentNode);
        this.childNodes = [];
        this.classList = new FakeClassList(...classes);
        this.media = media;
        this.rect = rect;
    }

    contains(node) {
        for (let current = node; current; current = current.parentNode) {
            if (current === this) {
                return true;
            }
        }
        return false;
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

    querySelectorAll() {
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
        createRange: function () {
            return {
                collapsed: false,
                startBefore: null,
                collapse: function () { this.collapsed = true; },
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

    new vm.Script(editorSource, {filename: 'post-inplace.js'}).runInContext(context);

    return {
        document,
        elements,
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
    assert.equal(insertionRange.startBefore, media);
    assert.equal(insertionRange.startContainer, body);
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
});
