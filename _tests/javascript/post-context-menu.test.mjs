import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_assets/register/post-inplace.js', import.meta.url), 'utf8');
const functions = source.slice(
    source.indexOf('    function createContextMenuAnchor('),
    source.indexOf('    function visibleContextButtons('),
);
assert.ok(functions.length > 0);

class Element {
    constructor(tag) {
        this.tag = tag;
        this.children = [];
        this.attributes = new Map();
        this.classes = new Set();
        this.classList = {add: name => this.classes.add(name)};
    }

    setAttribute(name, value) { this.attributes.set(name, value); }
    append(child) { this.children.push(child); child.parentElement = this; }
    remove() { this.parentElement.children = this.parentElement.children.filter(child => child !== this); }
}

function harness() {
    const body = new Element('body');
    const editor = new Element('div');
    editor.children = [{textContent: 'A long selection stays intact.'}];
    body.append(editor);
    const context = vm.createContext({
        window: {innerWidth: 1200, innerHeight: 800},
        getComputedStyle: element => ({position: element.position || 'absolute'}),
        document: {body, createElementNS: (_, tag) => new Element(tag)},
    });
    vm.runInContext(functions, context);
    return {context, body, editor};
}

const plain = value => JSON.parse(JSON.stringify(value));
const geometry = element => Object.fromEntries(
    ['x', 'y', 'width', 'height'].map(key => [key, Number(element.attributes.get(key))]),
);

test('pointer context menus use the click, never the far end of a selection', () => {
    const {context} = harness();
    const range = {
        getClientRects() { throw new Error('Mouse placement must not depend on selection geometry'); },
    };
    assert.deepEqual(plain(context.contextMenuPoint({}, range, {clientX: 120, clientY: 240}, null)), {
        x: 120, y: 240,
    });
    assert.deepEqual(plain(context.contextMenuPoint({}, range, {clientX: 0, clientY: 0}, null)), {
        x: 0, y: 0,
    });
});

test('keyboard menus use a visible selection line even if the selection ends offscreen', () => {
    const {context} = harness();
    const range = {getClientRects: () => [
        {left: 80, top: -90, bottom: -60, height: 30},
        {left: 80, top: 120, bottom: 150, height: 30},
        {left: 80, top: 900, bottom: 930, height: 30},
    ]};
    assert.deepEqual(plain(context.contextMenuPoint({}, range, null, null)), {x: 80, y: 150});
});

test('keyboard menus can open at a caret or in an empty editor', () => {
    const {context} = harness();
    const range = {
        getClientRects: () => [{left: 125, top: 200, bottom: 225, height: 25, width: 0}],
    };
    assert.deepEqual(plain(context.contextMenuPoint({}, range, null, null)), {x: 125, y: 225});
    const empty = {getClientRects: () => [], getBoundingClientRect: () => ({height: 0})};
    const state = {body: {getBoundingClientRect: () => ({left: 80, bottom: 180})}};
    assert.deepEqual(plain(context.contextMenuPoint(state, empty, null, null)), {x: 80, y: 180});
});

test('image menus use the pointer, with an image-boundary fallback for keyboard access', () => {
    const {context} = harness();
    const image = {getBoundingClientRect: () => ({left: 80, top: 250})};
    assert.deepEqual(plain(context.contextMenuPoint({}, null, {clientX: 320, clientY: 400}, image)), {
        x: 320, y: 400,
    });
    assert.deepEqual(plain(context.contextMenuPoint({}, null, null, image)), {x: 80, y: 250});
});

test('menus stay at the pointer when there is room and flip at the right and bottom edges', () => {
    const {context} = harness();
    const size = {width: 352, height: 393};
    const viewport = {width: 1200, height: 800};
    const cases = [
        [{x: 120, y: 240}, {x: 124, y: 244}],
        [{x: 1180, y: 240}, {x: 824, y: 244}],
        [{x: 120, y: 780}, {x: 124, y: 383}],
        [{x: 1180, y: 780}, {x: 824, y: 383}],
        [{x: 0, y: 0}, {x: 12, y: 12}],
        [{x: -100, y: -100}, {x: 12, y: 12}],
        [{x: 2400, y: 1600}, {x: 836, y: 395}],
    ];
    for (const [point, expected] of cases) {
        assert.deepEqual(plain(context.contextMenuPosition(point, size, viewport)), expected);
    }
});

test('a menu with no room on either side is clamped, not pushed out of the viewport', () => {
    const {context} = harness();
    assert.deepEqual(plain(context.contextMenuPosition(
        {x: 350, y: 250}, {width: 650, height: 450}, {width: 720, height: 500},
    )), {x: 12, y: 12});
});

test('opening and removing the overlay never inserts nodes into editable content', () => {
    const {context, body, editor} = harness();
    const children = [...editor.children];
    const {anchor, viewport} = context.createContextMenuAnchor();
    assert.equal(anchor.tag, 'svg');
    assert.equal(anchor.parentElement, body);
    assert.equal(viewport.tag, 'foreignObject');
    assert.deepEqual(editor.children, children);
    assert.ok(anchor.classes.has('post-editor-context-anchor'));
    anchor.remove();
    assert.deepEqual(body.children, [editor]);
    assert.deepEqual(editor.children, children);
});

test('desktop overlay geometry follows the click and is recomputed for a different panel size', () => {
    const {context} = harness();
    const overlay = context.createContextMenuAnchor();
    const size = {width: 352, height: 393};
    const menu = {getBoundingClientRect: () => size};
    const state = {...overlay, menu, point: {x: 1150, y: 700}};
    context.positionContextMenu(state);
    assert.deepEqual(geometry(overlay.viewport), {x: 794, y: 303, ...size});
    size.width = 300;
    size.height = 120;
    context.positionContextMenu(state);
    assert.deepEqual(geometry(overlay.viewport), {x: 846, y: 576, ...size});
});

test('mobile bottom sheets retain full-viewport positioning instead of pointer offsets', () => {
    const {context} = harness();
    context.window.innerWidth = 390;
    context.window.innerHeight = 700;
    const overlay = context.createContextMenuAnchor();
    overlay.viewport.setAttribute('x', '120');
    overlay.viewport.setAttribute('y', '240');
    context.positionContextMenu({...overlay, menu: {position: 'fixed'}, point: {x: 120, y: 240}});
    assert.deepEqual(geometry(overlay.viewport), {x: 0, y: 0, width: 390, height: 700});
});
