import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_assets/register/post-inplace.js', import.meta.url), 'utf8');
const functions = source.slice(
    source.indexOf('    function restoreTypographicNoBreaks('),
    source.indexOf('    function focusEdge('),
);

const bounds = (top, height, left = 100, width = 500) => ({top, bottom: top + height, left, width, height});

class Element {
    constructor(tag, box = bounds(0, 0), fontSize = 20) {
        this.tag = tag;
        this.box = box;
        this.fontSize = fontSize;
        this.lineHeight = fontSize === 30 ? 36 : fontSize === 13 ? 13 : 32;
        this.childNodes = [];
        this.attributes = new Map();
        this.classes = new Set();
        this.classList = {add: name => this.classes.add(name)};
    }

    getBoundingClientRect() { return this.box; }
    get textContent() { return this.childNodes.map(child => child.textContent).join(''); }
    setAttribute(name, value) { this.attributes.set(name, value); }
    append(child) { this.childNodes.push(child); child.parentElement = this; }
    remove() { this.parentElement.childNodes = this.parentElement.childNodes.filter(child => child !== this); }

    matches(selectors) {
        return selectors.split(',').some(selector => {
            const value = selector.trim();
            return value.startsWith('.') ? this.classes.has(value.slice(1)) : this.tag === value;
        });
    }

    querySelector(selector) {
        for (const child of this.childNodes) {
            if (!(child instanceof Element)) continue;
            if (child.matches(selector)) return child;
            const match = child.querySelector(selector);
            if (match) return match;
        }
        return null;
    }

    closest(selector) {
        return this.matches(selector) ? this : this.parentElement?.closest(selector) || null;
    }
}

class TextNode {
    constructor(data) { this.nodeType = 3; this.data = data; }
    get textContent() { return this.data; }
    splitText(index) {
        const next = new TextNode(this.data.slice(index));
        this.data = this.data.slice(0, index);
        const position = this.parentElement.childNodes.indexOf(this);
        this.parentElement.childNodes.splice(position + 1, 0, next);
        next.parentElement = this.parentElement;
        return next;
    }
    replaceWith(element) {
        const position = this.parentElement.childNodes.indexOf(this);
        this.parentElement.childNodes.splice(position, 1, element);
        element.parentElement = this.parentElement;
        this.parentElement = null;
    }
}

function harness() {
    const frames = new Map();
    const observers = [];
    const fontListeners = new Map();
    const card = new Element('article', bounds(80, 400));
    card.padding = '8px';
    const title = new Element('span', bounds(100, 36), 30);
    const body = new Element('div', bounds(180, 96));
    const tags = new Element('span', bounds(320, 24));
    const tagSurface = new Element('span', bounds(320, 24));
    const input = new Element('input', bounds(320, 24), 13);
    tagSurface.classes.add('post-tags-surface');
    tagSurface.append(input);
    tags.append(tagSurface);
    const addText = (element, rects) => {
        const node = new TextNode('Example');
        node.rects = rects;
        element.append(node);
        return node;
    };
    addText(title, [bounds(100, 36)]);
    addText(body, [bounds(184.5, 23), bounds(248.5, 23)]);
    card.append(title);
    card.append(body);
    card.append(tags);
    const state = {card, title, body, tags};

    class Observer {
        constructor(callback) { this.callback = callback; this.targets = []; observers.push(this); }
        observe(element) { this.targets.push(element); }
        disconnect() { this.targets = []; }
    }

    const context = vm.createContext({
        Node: {TEXT_NODE: 3},
        NodeFilter: {SHOW_TEXT: 4},
        HTMLElement: Element,
        ResizeObserver: Observer,
        MutationObserver: Observer,
        requestAnimationFrame: callback => { frames.set(1, callback); return 1; },
        cancelAnimationFrame: id => frames.delete(id),
        getComputedStyle: element => ({
            fontStyle: 'normal', fontWeight: '400', fontFamily: 'serif',
            fontSize: `${element.fontSize}px`, lineHeight: `${element.lineHeight}px`,
            paddingLeft: element.paddingLeft || '0px',
            getPropertyValue: () => element.padding ?? element.parentElement?.padding ?? '',
        }),
        document: {
            createElementNS: (_, tag) => new Element(tag),
            createElement: tag => tag === 'canvas' ? ({getContext: () => ({
                font: '',
                measureText() {
                    const metrics = this.font.includes('30px') ? [29, 7, 21, 6]
                        : this.font.includes('13px') ? [13, 3, 10, 3] : [19, 4, 14, 4];
                    return Object.fromEntries([
                        'fontBoundingBoxAscent', 'fontBoundingBoxDescent',
                        'actualBoundingBoxAscent', 'actualBoundingBoxDescent',
                    ].map((name, index) => [name, metrics[index]]));
                },
            })}) : new Element(tag),
            createTreeWalker: root => {
                const nodes = [];
                const visit = element => element.childNodes.forEach(child => {
                    if (child.nodeType === 3) nodes.push(child);
                    else visit(child);
                });
                visit(root);
                return {nextNode: () => nodes.shift() || null};
            },
            createRange: () => ({
                selectNodeContents(node) { this.node = node; },
                getClientRects() { return this.node.rects; },
            }),
            fonts: {
                addEventListener: (name, callback) => fontListeners.set(name, callback),
                removeEventListener: name => fontListeners.delete(name),
            },
        },
    });
    vm.runInContext(functions, context);
    return {context, state, frames, observers, fontListeners, tagSurface, input};
}

function rectangle(state, name) {
    const svg = state.card.querySelector('.post-editor-field-surfaces');
    const rect = svg.childNodes.find(node => node.attributes.get('data-editor-field-surface') === name);
    return Object.fromEntries(['x', 'y', 'width', 'height'].map(key => [key, Number(rect.attributes.get(key))]));
}

test('all fields have the same eight-pixel padding around their text, not around font leading', () => {
    const {context, state} = harness();
    context.createEditorFieldSurfaces(state);
    // Title ink: y=108..135; body ink: y=189.5..271.5; input ink: y=327..340.
    assert.deepEqual(rectangle(state, 'title'), {x: -8, y: 20, width: 516, height: 43});
    assert.deepEqual(rectangle(state, 'body'), {x: -8, y: 101.5, width: 516, height: 98});
    assert.deepEqual(rectangle(state, 'tags'), {x: -8, y: 239, width: 516, height: 29});
});

test('decorations leave field geometry and editable HTML untouched and disappear on close', () => {
    const {context, state} = harness();
    const original = [state.title, state.body, state.tags].map(element => ({
        box: {...element.box}, children: [...element.childNodes],
    }));
    const decoration = context.createEditorFieldSurfaces(state);
    [state.title, state.body, state.tags].forEach((element, index) => {
        assert.deepEqual(element.box, original[index].box);
        assert.deepEqual(element.childNodes, original[index].children);
    });
    assert.equal(state.card.childNodes[0], state.title, 'preserve first-child selectors');
    decoration.destroy();
    assert.equal(state.card.childNodes.length, 3);
    context.createEditorFieldSurfaces(state).destroy();
    assert.equal(state.card.childNodes.length, 3, 'reopening does not accumulate decorations');
});

test('image edges and tag chips get the same padding without trimming them as text', () => {
    const {context, state, tagSurface} = harness();
    state.body.childNodes = [];
    state.body.append(new Element('img', state.body.box));
    const chip = new Element('span', tagSurface.box);
    chip.classes.add('post-tag-chip');
    tagSurface.append(chip);
    context.createEditorFieldSurfaces(state);
    assert.deepEqual(rectangle(state, 'body'), {x: -8, y: 92, width: 516, height: 112});
    assert.deepEqual(rectangle(state, 'tags'), {x: -8, y: 232, width: 516, height: 40});
});

test('the existing tablet tools gutter is outside the body field, not added to its padding', () => {
    const {context, state} = harness();
    state.body.paddingLeft = '40px';
    context.createEditorFieldSurfaces(state);
    assert.equal(rectangle(state, 'body').x, 32);
    assert.equal(rectangle(state, 'body').width, 476);
});

test('a tall empty draft keeps its whole writing surface', () => {
    const {context, state} = harness();
    state.body.childNodes = [];
    state.body.box = bounds(180, 624);
    context.createEditorFieldSurfaces(state);
    assert.deepEqual(rectangle(state, 'body'), {x: -8, y: 92, width: 516, height: 640});
});

test('typing and resizing update one decoration; closing cancels pending work', () => {
    const {context, state, frames, observers, fontListeners} = harness();
    const decoration = context.createEditorFieldSurfaces(state);
    state.body.box = bounds(180, 128);
    state.body.childNodes[0].rects[1] = bounds(280.5, 23);
    observers.forEach(observer => observer.callback());
    assert.equal(frames.size, 1);
    frames.get(1)();
    assert.equal(rectangle(state, 'body').height, 130);
    fontListeners.get('loadingdone')();
    decoration.destroy();
    assert.equal(frames.size, 0);
    assert.equal(fontListeners.size, 0);
    assert.ok(observers.every(observer => observer.targets.length === 0));
});

test('existing footer height is retained exactly, including fractional CSS pixels', () => {
    const {context} = harness();
    for (const height of [0, 24, 28.796875, 61.1875]) {
        const spacer = context.createFootHeightSpacer(new Element('div', bounds(0, height)));
        assert.equal(Number(spacer.attributes.get('height')), height);
    }
});

test('other themes without this field design do not get an unstyled SVG', () => {
    const {context, state} = harness();
    state.card.padding = '';
    context.createEditorFieldSurfaces(state).destroy();
    assert.equal(state.card.childNodes.length, 3);
});

test('server typography no-breaks keep line wrapping in edit mode without entering saved HTML', () => {
    const {context} = harness();
    const body = new Element('div');
    body.append(new TextNode('До веб-разработчики и MK-61s, потом.'));
    context.restoreTypographicNoBreaks(body, new Set(['веб-разработчики', 'MK-61s,']));
    const wrappers = body.childNodes.filter(node => node instanceof Element);
    assert.deepEqual(wrappers.map(wrapper => wrapper.tag), ['nobr', 'nobr']);
    assert.deepEqual(wrappers.map(wrapper => wrapper.childNodes[0].data), ['веб-разработчики', 'MK-61s,']);
    assert.ok(wrappers.every(wrapper => wrapper.attributes.has('data-post-editor-nowrap')));
    assert.match(source, /clone\.querySelectorAll\('\[data-post-editor-nowrap\]'\).*?replaceWith/s);
});
