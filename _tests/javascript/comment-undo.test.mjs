import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_assets/register/comment-undo.js', import.meta.url), 'utf8');
const key = 'register.commentUndo.v1';
class Element {
    constructor(tag) { this.tag = tag; this.children = []; this.events = {}; this.attributes = {}; }
    setAttribute(name, value) { this.attributes[name] = value; }
    append(...nodes) { nodes.forEach((node) => { this.children.push(node); node.parent = this; }); }
    appendChild(node) { this.append(node); }
    addEventListener(name, listener) { this.events[name] = listener; }
    remove() { if (this.parent) this.parent.children = this.parent.children.filter((node) => node !== this); }
}
function setup(storage = new Map()) {
    const body = new Element('body');
    const handlers = {};
    const calls = [];
    let reloads = 0;
    let reject = false;
    const window = {
        location: {href: 'https://example.test/blog/_admin/index.php', origin: 'https://example.test', reload() { reloads++; }},
        async fetch(url, options) {
            calls.push({url, options});
            return {ok: !reject, async json() { return reject ? {success: false, message: 'Session expired'} : {success: true}; }};
        },
    };
    const sessionStorage = {
        getItem(name) { return storage.get(name) ?? null; },
        setItem(name, value) { storage.set(name, value); },
    };
    const context = vm.createContext({
        window, sessionStorage, URL, URLSearchParams, Date,
        document: {body, createElement: (tag) => new Element(tag), addEventListener(name, listener) { handlers[name] = listener; }},
    });
    vm.runInContext(source, context);
    handlers.DOMContentLoaded();
    return {
        window, storage, body, calls, sessionStorage,
        forms: () => body.children.flatMap((node) => node.children),
        setReject(value) { reject = value; },
        reloads: () => reloads,
    };
}
const payload = {message: 'Deleted for ten minutes', undo_label: 'Undo', undo_error: 'Failed'};
const item = (token = 'signed') => ({url: 'index.php?entity=Comment&action=delete&id=1', data: {csrf_token: 'csrf', undo_token: token}});

test('Undo survives reload, sends the original signed state and updates the page only after restoration', async () => {
    const first = setup();
    first.window.RegisterCommentUndo.remember(payload, [item()]);
    assert.equal(first.forms().length, 1);
    const next = setup(first.storage);
    const form = next.forms()[0];
    await form.events.submit({preventDefault() {}});
    assert.equal(next.calls[0].url, 'https://example.test/blog/_admin/index.php?entity=Comment&action=delete&id=1');
    assert.equal(next.calls[0].options.method, 'POST');
    assert.equal(next.calls[0].options.body.get('undo_token'), 'signed');
    assert.equal(next.calls[0].options.body.get('csrf_token'), 'csrf');
    assert.equal(next.reloads(), 1);
    assert.equal(JSON.parse(next.storage.get(key)).length, 0);
});

test('a rejected restore retains Undo and its error without pretending success', async () => {
    const runtime = setup();
    runtime.window.RegisterCommentUndo.remember(payload, [item()]);
    runtime.setReject(true);
    const form = runtime.forms()[0];
    await form.events.submit({preventDefault() {}});
    assert.equal(runtime.reloads(), 0);
    assert.equal(form.children[0].textContent, 'Session expired');
    assert.equal(form.children[1].disabled, false);
    assert.equal(JSON.parse(runtime.storage.get(key))[0].items.length, 1);
});

test('bulk Undo restores each selected comment once', async () => {
    const runtime = setup();
    runtime.window.RegisterCommentUndo.remember(payload, [item('one'), item('two')]);
    await runtime.forms()[0].events.submit({preventDefault() {}});
    assert.deepEqual(runtime.calls.map((call) => call.options.body.get('undo_token')), ['one', 'two']);
    assert.equal(runtime.reloads(), 1);
});

test('expired notices and foreign origins cannot provide restore actions', () => {
    const stored = new Map([[key, JSON.stringify([{...payload, items: [item()], expires: Date.now() - 1}])]]);
    const runtime = setup(stored);
    assert.equal(runtime.forms().length, 0);
    runtime.window.RegisterCommentUndo.remember(payload, [{url: 'https://foreign.example/steal', data: {undo_token: 'signed'}}]);
    assert.equal(runtime.forms().length, 0);
    runtime.window.RegisterCommentUndo.remember(payload, [item()]);
    runtime.forms()[0].children[2].events.click();
    assert.equal(runtime.forms().length, 0);
});

test('blocked browser storage still permits immediate Undo', () => {
    const runtime = setup();
    runtime.sessionStorage.setItem = () => { throw new Error('Storage is blocked'); };
    runtime.window.RegisterCommentUndo.remember(payload, [item()]);
    assert.equal(runtime.forms().length, 1);
});
