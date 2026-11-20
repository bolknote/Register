import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_assets/register/live-updates.js', import.meta.url), 'utf8');

function fixture() {
    class Target {
        listeners = new Map();
        addEventListener(type, callback) {
            const callbacks = this.listeners.get(type) || [];
            callbacks.push(callback);
            this.listeners.set(type, callbacks);
        }
        dispatchEvent(event) {
            for (const callback of this.listeners.get(event.type) || []) callback(event);
        }
    }
    const document = new Target();
    document.hidden = false;
    document.baseURI = 'https://example.test/';
    document.querySelector = selector => selector === 'meta[name="register-live-updates"]'
        ? {dataset: {endpoint: '/_live', cursor: '20', regions: '["posts:0"]'}} : null;
    document.querySelectorAll = () => [];
    const window = new Target();
    const navigator = {onLine: true};
    const timers = new Map();
    const calls = [];
    const responses = [];
    let now = 0;
    let sequence = 0;
    window.setTimeout = (callback, delay) => {
        const id = ++sequence;
        timers.set(id, {callback, at: now + delay});
        return id;
    };
    window.clearTimeout = id => timers.delete(id);
    window.fetch = async input => {
        const url = new URL(input);
        calls.push({at: now, url});
        const payload = responses.shift();
        if (payload instanceof Error) throw payload;
        return {ok: true, json: async () => payload || {
            cursor: Number(url.searchParams.get('cursor')), patches: {}, more: false,
        }};
    };
    const math = Object.create(Math);
    math.random = () => 0;
    const context = vm.createContext({window, document, navigator, URL, AbortController,
        Element: class {}, Math: math, CustomEvent: class {
            constructor(type, options = {}) { this.type = type; this.detail = options.detail; }
        }});
    new vm.Script(source, {filename: 'live-updates.js'}).runInContext(context);
    const nextDelay = () => Math.min(...Array.from(timers.values(), timer => timer.at)) - now;
    const advance = async milliseconds => {
        const end = now + milliseconds;
        let steps = 0;
        while (timers.size > 0) {
            const [id, timer] = [...timers.entries()].sort((a, b) => a[1].at - b[1].at)[0];
            if (timer.at > end) break;
            assert.ok(++steps < 2000, 'Polling must not spin synchronously');
            timers.delete(id);
            now = timer.at;
            await timer.callback();
        }
        now = end;
    };
    return {window, document, navigator, calls, responses, nextDelay, advance};
}

test('quiet visible tabs reduce polling traffic and retain the online presence heartbeat', async () => {
    const page = fixture();
    await page.advance(600_000);
    assert.ok(page.calls.length <= 23, `A quiet tab made ${page.calls.length} requests in ten minutes`);
    assert.ok(page.calls.length >= 20, 'The online presence heartbeat must remain active');
    const gaps = page.calls.slice(1).map((call, index) => call.at - page.calls[index].at);
    assert.equal(gaps[0], 15_000, 'The initial synchronization interval stays fast');
    assert.equal(Math.max(...gaps), 30_000, 'Quiet polling must stay below the 45-second presence window');
});

test('a changed subscribed region restores fast polling', async () => {
    const page = fixture();
    await page.advance(120_000);
    page.responses.push({cursor: 21, patches: {'posts:0': '<div>Updated article</div>'}, more: false});
    await page.advance(page.nextDelay());
    assert.equal(page.nextDelay(), 15_000);
});

test('locally acknowledged edits synchronize immediately and reset quiet polling', async () => {
    const page = fixture();
    await page.advance(120_000);
    const before = page.calls.length;
    assert.equal(page.window.RegisterLiveUpdates.acknowledge(21), true);
    await page.advance(0);
    assert.equal(page.calls.length, before + 1);
    assert.equal(page.nextDelay(), 15_000);
});

test('hidden tabs stop polling and returning to them synchronizes immediately', async () => {
    const page = fixture();
    await page.advance(120_000);
    const before = page.calls.length;
    page.document.hidden = true;
    page.document.dispatchEvent({type: 'visibilitychange'});
    await page.advance(60_000);
    assert.equal(page.calls.length, before);
    page.document.hidden = false;
    page.document.dispatchEvent({type: 'visibilitychange'});
    await page.advance(0);
    assert.equal(page.calls.length, before + 1);
    assert.equal(page.nextDelay(), 15_000);
});

test('reconnecting and explicitly refreshing a quiet tab restore fast polling', async () => {
    for (const event of ['online', 'register:live-refresh']) {
        const page = fixture();
        await page.advance(120_000);
        const before = page.calls.length;
        if (event === 'online') page.window.dispatchEvent({type: event});
        else page.document.dispatchEvent({type: event});
        await page.advance(0);
        assert.equal(page.calls.length, before + 1);
        assert.equal(page.nextDelay(), 15_000);
    }
});

test('transient failures retain backoff and recover with fast polling', async () => {
    const page = fixture();
    await page.advance(120_000);
    page.responses.push(new TypeError('Network unavailable'));
    await page.advance(page.nextDelay());
    assert.equal(page.nextDelay(), 30_000);
    await page.advance(30_000);
    assert.equal(page.nextDelay(), 15_000);
});
