import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_assets/register/comment-undo.js', import.meta.url), 'utf8');
const key = 'register.commentUndo.v2';
const initialTime = 1_800_000_000_000;

function setup({storage = new Map(), now = initialTime, url = 'https://example.test/blog/_admin/index.php', user = ':1'} = {}) {
    let currentTime = now;
    let document;
    class Element {
        constructor(tag) { this.tag = tag; this.children = []; this.events = {}; this.attributes = {}; this.dataset = {}; this.className = ''; }
        get textContent() { return (this.text || '') + this.children.map((node) => node.textContent).join(''); }
        set textContent(value) { this.text = String(value); this.children = []; }
        get classList() {
            return {
                add: (...names) => { this.className = [...new Set([...this.className.split(' '), ...names])].join(' ').trim(); },
                remove: (...names) => { this.className = this.className.split(' ').filter((name) => !names.includes(name)).join(' '); },
                contains: (name) => this.className.split(' ').includes(name)
            };
        }
        setAttribute(name, value) { this.attributes[name] = value; }
        append(...nodes) { nodes.forEach((node) => { node.remove(); this.children.push(node); node.parent = this; }); }
        appendChild(node) { this.append(node); }
        insertBefore(node, before) { node.remove(); this.children.splice(this.children.indexOf(before), 0, node); node.parent = this; }
        addEventListener(name, listener) { this.events[name] = listener; }
        remove() { if (this.parent) this.parent.children = this.parent.children.filter((node) => node !== this); this.parent = null; }
        focus() { if (!this.disabled && !this.hidden) document.activeElement = this; }
        scrollIntoView() { this.scrolled = true; }
        matches(selector) {
            const tag = selector.match(/^[a-z]+/i)?.[0];
            if (tag && this.tag !== tag) return false;
            if ([...selector.matchAll(/\.([a-z-]+)/g)].some((match) => !this.classList.contains(match[1]))) return false;
            for (const [, name, value] of selector.matchAll(/\[([^=\]]+)="([^"]*)"\]/g)) {
                const actual = name.startsWith('data-') ? this.dataset[name.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase())] : this.attributes[name];
                if (String(actual) !== value) return false;
            }
            return true;
        }
        querySelectorAll(selector) {
            if (selector.includes(',')) return [...new Set(selector.split(',').flatMap((part) => this.querySelectorAll(part.trim())))];
            const direct = selector.startsWith(':scope > ');
            const query = selector.replace(':scope > ', '');
            const all = direct ? this.children : this.children.flatMap((child) => [child, ...child.descendants()]);
            return all.filter((node) => node.matches(query));
        }
        descendants() { return this.children.flatMap((child) => [child, ...child.descendants()]); }
        querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
        click() { if (!this.disabled && !this.hidden) return this.events.click?.({preventDefault() {}}); }
    }
    const body = new Element('body');
    if (user) {
        const meta = new Element('meta');
        meta.setAttribute('name', 'register-comment-undo-user');
        meta.content = user;
        body.append(meta);
    }
    const handlers = {};
    document = {
        body, activeElement: body, createElement: (tag) => new Element(tag),
        querySelector: (selector) => body.querySelector(selector),
        querySelectorAll: (selector) => body.querySelectorAll(selector),
        getElementById: (id) => body.descendants().find((node) => node.id === id),
        addEventListener(name, listener) { handlers[name] = listener; },
        dispatchEvent(event) { handlers[event.type]?.(event); }
    };
    const calls = [];
    const navigation = [];
    let reloads = 0;
    const timeouts = new Map();
    const intervals = new Map();
    let nextTimer = 1;
    let fetchHandler = async () => response();
    const location = new URL(url);
    location.assign = (value) => navigation.push(value);
    location.reload = () => { reloads++; navigation.push(location.href); };
    const window = {
        location,
        history: {state: null, replaceState(_state, _title, url) { location.href = url; }},
        fetch(url, options) { calls.push({url, options}); return fetchHandler(url, options); },
        setTimeout(fn, delay) { const id = nextTimer++; timeouts.set(id, {fn, due: currentTime + delay}); return id; },
        clearTimeout(id) { timeouts.delete(id); },
        setInterval(fn) { const id = nextTimer++; intervals.set(id, fn); return id; },
        clearInterval(id) { intervals.delete(id); }
    };
    const sessionStorage = {
        getItem(name) { return storage.get(name) ?? null; },
        setItem(name, value) { storage.set(name, value); },
        removeItem(name) { storage.delete(name); }
    };
    class Clock extends Date { static now() { return currentTime; } }
    class CustomEvent { constructor(type) { this.type = type; } }
    vm.runInContext(source, vm.createContext({window, sessionStorage, URL, URLSearchParams, Date: Clock, document, AbortController, CustomEvent}));
    handlers.DOMContentLoaded();
    const payload = (id = 1, remaining = 600000) => ({
        undo_comment_id: id, undo_context: 'Comment #' + id + ' — Author ' + id, undo_excerpt: 'Text ' + id,
        undo_expires_at: initialTime + remaining, undo_server_time: initialTime,
        undo_labels: {restore: 'Restore', network_error: 'Connection failed', server_error: 'Server failed',
            expired: 'Expired', changed: 'Changed', session_error: 'Refresh session'}
    });
    const item = (id = 1) => ({
        url: 'index.php?entity=Comment&action=delete&id=' + id,
        data: {csrf_token: 'csrf', undo_token: 'signed-' + id}, ...payload(id)
    });
    const history = () => body.querySelector('.comment-undo-history');
    const forms = () => history()?.querySelectorAll('form') || [];
    return {
        window, storage, sessionStorage, body, document, calls, navigation, payload, item, forms,
        reloads: () => reloads,
        remember(id = 1, remaining = 600000, requestedAt) { window.RegisterCommentUndo.remember(payload(id, remaining), [{...item(id), ...payload(id, remaining)}], requestedAt); },
        async restore(index = 0) { await forms()[index].querySelector('.comment-undo-restore').click(); },
        stored: () => JSON.parse(storage.get(key)),
        respond(handler) { fetchHandler = handler; },
        collapse: () => body.querySelector('.comment-undo-collapse').click(),
        expand: () => body.querySelector('.comment-undo-toggle').click(),
        advance(ms) {
            currentTime += ms;
            for (const [id, value] of timeouts) if (value.due <= currentTime) { timeouts.delete(id); value.fn(); }
            for (const fn of intervals.values()) fn();
        },
        create(tag, className) { const node = new Element(tag); node.className = className; body.append(node); return node; }
    };
}
function response(status = 200, payload = {success: true}) {
    return {ok: status >= 200 && status < 300, status, async json() { return payload; }};
}

test('collapsing a notification preserves restoration through navigation and expansion', async () => {
    const first = setup();
    first.remember();
    first.collapse();
    assert.equal(first.body.querySelector('.comment-undo-history').hidden, true);
    assert.equal(first.stored().entries[0].data.undo_token, 'signed-1');
    const next = setup({storage: first.storage});
    assert.equal(next.body.querySelector('.comment-undo-history').hidden, true);
    next.expand();
    await next.restore();
    assert.equal(next.calls[0].options.body.get('undo_token'), 'signed-1');
    assert.equal(next.calls[0].options.body.get('csrf_token'), 'csrf');
    assert.match(next.navigation[0], /#admin-comment-1$/);
    assert.equal(next.reloads(), 1, 'Changing only the fragment must still refresh the deleted row.');
    const arrived = setup({storage: next.storage});
    assert.match(arrived.forms()[0].textContent, /Comment restored/);
    assert.equal(arrived.forms()[0].querySelector('.comment-undo-restore').hidden, true);
});

test('the countdown uses the server deadline, allows clock skew and never restarts on reload', () => {
    const runtime = setup({now: initialTime + 3_600_000});
    runtime.remember(1, 90_000, initialTime + 3_599_000);
    assert.match(runtime.forms()[0].querySelector('.comment-undo-time').textContent, /1:29/);
    runtime.advance(30_000);
    runtime.remember(1, 90_000); // Duplicate replies cannot extend an existing restoration.
    const next = setup({storage: runtime.storage, now: initialTime + 3_630_000});
    assert.match(next.forms()[0].querySelector('.comment-undo-time').textContent, /0:59/);
    next.advance(59_000);
    assert.equal(next.forms()[0].querySelector('.comment-undo-restore').hidden, true);
    assert.match(next.forms()[0].textContent, /Expired/);
    assert.equal(next.calls.length, 0);
});

test('a stalled request has a visible pending state and becomes retryable after ten seconds', async () => {
    const runtime = setup();
    runtime.remember();
    runtime.respond(() => new Promise(() => {}));
    const operation = runtime.restore();
    assert.equal(runtime.forms()[0].attributes['aria-busy'], 'true');
    assert.equal(runtime.forms()[0].querySelector('.comment-undo-restore').textContent, 'Restoring…');
    runtime.advance(10000);
    await operation;
    assert.equal(runtime.calls[0].options.signal.aborted, true);
    assert.equal(runtime.forms()[0].querySelector('.comment-undo-restore').disabled, false);
    assert.match(runtime.forms()[0].textContent, /Connection failed/);
    assert.equal(runtime.navigation.length, 0);
});

for (const [code, status, message] of [
    ['undo_expired', 409, 'Expired'],
    ['undo_changed', 409, 'Changed'],
    [null, 404, 'Changed'],
    [null, 403, 'Refresh session'],
    [null, 422, 'Refresh session']
]) {
    test('permanent restoration failure does not offer a futile retry: ' + code + '/' + status, async () => {
        const runtime = setup();
        runtime.remember();
        runtime.respond(async () => response(status, {success: false, code, message: 'technical error'}));
        await runtime.restore();
        assert.equal(runtime.forms()[0].querySelector('.comment-undo-restore').hidden, true);
        assert.match(runtime.forms()[0].textContent, new RegExp(message));
        assert.doesNotMatch(runtime.forms()[0].textContent, /technical error/);
        assert.equal(runtime.navigation.length, 0);
    });
}

test('a lost success response followed by an already-restored response is acknowledged as success', async () => {
    const runtime = setup();
    runtime.remember();
    runtime.respond(async () => response(409, {success: false, code: 'undo_already_restored'}));
    await runtime.restore();
    assert.equal(runtime.stored().entries[0].state, 'restored');
    assert.match(runtime.forms()[0].textContent, /already been restored/);
    assert.equal(runtime.navigation.length, 1);
});

test('non-JSON and network failures show human messages and preserve the signed retry', async () => {
    for (const failure of [
        async () => ({ok: false, status: 502, async json() { throw new Error('Unexpected token <'); }}),
        async () => { throw new Error('DNS internal details'); }
    ]) {
        const runtime = setup();
        runtime.remember();
        runtime.respond(failure);
        await runtime.restore();
        assert.equal(runtime.forms()[0].querySelector('.comment-undo-restore').disabled, false);
        assert.match(runtime.forms()[0].textContent, /(?:Connection|Server) failed/);
        assert.doesNotMatch(runtime.forms()[0].textContent, /Unexpected|DNS|internal/);
        assert.equal(runtime.stored().entries[0].data.undo_token, 'signed-1');
    }
});

test('bulk recovery shows partial results and never retries comments already restored', async () => {
    const runtime = setup();
    runtime.window.RegisterCommentUndo.remember(runtime.payload(), [runtime.item(1), runtime.item(2), runtime.item(3)]);
    runtime.respond(async (_, options) => options.body.get('undo_token') === 'signed-2'
        ? response(503, {success: false}) : response());
    await runtime.body.querySelector('.comment-undo-restore-all').click();
    assert.deepEqual(runtime.calls.map((call) => call.options.body.get('undo_token')), ['signed-1', 'signed-2']);
    assert.match(runtime.body.querySelector('.comment-undo-progress').textContent, /1 of 3/);
    assert.equal(runtime.navigation.length, 0);
    runtime.respond(async () => response());
    await runtime.body.querySelector('.comment-undo-restore-all').click();
    assert.deepEqual(runtime.calls.map((call) => call.options.body.get('undo_token')), ['signed-1', 'signed-2', 'signed-2', 'signed-3']);
    assert.match(runtime.body.querySelector('.comment-undo-progress').textContent, /3 of 3/);
});

test('deleted leaves keep an inline restoration control after collapsing history and after live patches', async () => {
    const runtime = setup({url: 'https://example.test/discussion'});
    const thread = runtime.create('div', 'comment-thread');
    const item = {...runtime.item(), data: {moderation_action: 'restore', comment_id: 1, comment_anchor: 4,
        return_to: '/discussion', moderation_token: 'csrf', undo_token: 'signed-1'}};
    runtime.window.RegisterCommentUndo.remember(runtime.payload(), [item]);
    runtime.collapse();
    assert.equal(thread.querySelectorAll('.comment-undo-inline').length, 1);
    assert.equal(thread.querySelector('.comment-undo-context').textContent, 'Comment #1 — Author 1');
    runtime.document.dispatchEvent({type: 'register:live-synchronized'});
    assert.equal(thread.querySelectorAll('.comment-undo-inline').length, 1);
    await thread.querySelector('.comment-undo-restore').click();
    assert.equal(runtime.calls[0].options.body.get('moderation_action'), 'restore');
    assert.match(runtime.navigation[0], /discussion#comment-1$/);
    assert.equal(runtime.reloads(), 1);
});

test('successful restoration focuses and highlights the returned row once', async () => {
    const runtime = setup();
    runtime.remember();
    await runtime.restore();
    const arrived = setup({storage: runtime.storage});
    const card = arrived.create('article', 'comment-card');
    card.id = 'admin-comment-1';
    arrived.document.dispatchEvent({type: 'register:live-synchronized'});
    assert.equal(arrived.document.activeElement, card);
    assert.equal(card.scrolled, true);
    assert.equal(card.classList.contains('comment-undo-restored'), true);
    assert.equal(arrived.stored().entries[0].focus, false);
});

test('foreign endpoints cannot receive signed tokens, and blocked storage still permits immediate recovery', async () => {
    const runtime = setup();
    runtime.window.RegisterCommentUndo.remember(runtime.payload(), [{...runtime.item(), url: 'https://foreign.test/steal'}]);
    assert.equal(runtime.forms().length, 0);
    runtime.sessionStorage.setItem = () => { throw new Error('Storage blocked'); };
    runtime.remember();
    runtime.collapse();
    runtime.expand();
    await runtime.restore();
    assert.equal(runtime.calls.length, 1);
    assert.match(runtime.calls[0].url, /^https:\/\/example.test\//);
});

test('the previous interface recovery token is migrated without extending its deadline', () => {
    const runtime = setup({storage: new Map([['register.commentUndo.v1', JSON.stringify([{
        expires: initialTime + 30000,
        items: [{url: 'index.php?entity=Comment&action=delete&id=1', data: {csrf_token: 'csrf', undo_token: 'old-token'}}]
    }])]])});
    assert.equal(runtime.forms().length, 1);
    assert.match(runtime.forms()[0].querySelector('.comment-undo-time').textContent, /0:30/);
    runtime.advance(30000);
    assert.equal(runtime.forms()[0].querySelector('.comment-undo-restore').hidden, true);
});

test('deleted comment excerpts and tokens do not appear after logout or under another user', () => {
    for (const user of [null, ':2', '/other-installation:1']) {
        const original = setup();
        original.remember();
        const next = setup({storage: original.storage, user});
        assert.equal(next.forms().length, 0);
        assert.doesNotMatch(next.body.textContent, /Author 1|Text 1/);
    }
});
