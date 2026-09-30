import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_assets/register/editor/request.js', import.meta.url), 'utf8');
function helper(fetch, name = 'requestJson') {
    const context = vm.createContext({window: {fetch}, AbortController, setTimeout, clearTimeout});
    vm.runInContext(source, context);
    return context.window.RegisterEditorRequest[name];
}
const never = () => new Promise(() => {});

test('a stalled save prerequisite has a deadline without starting a request', async () => {
    const wait = helper(() => { throw new Error('No request expected'); }, 'withDeadline');
    await assert.rejects(wait(never(), {timeoutMs: 10}), error => error.name === 'EditorTimeoutError');
    assert.equal(await wait(Promise.resolve('ready'), {timeoutMs: 100}), 'ready');
});

test('deadline aborts a stalled fetch and rejects even when the transport ignores abort', async () => {
    let signal;
    const request = helper(async (_url, options) => { signal = options.signal; return never(); });
    await assert.rejects(request('/save', {}, {timeoutMs: 10}), error => error.name === 'EditorTimeoutError');
    assert.equal(signal.aborted, true);
});

test('the deadline includes reading JSON after response headers arrived', async () => {
    const request = helper(async () => ({json: never}));
    await assert.rejects(request('/save', {}, {timeoutMs: 10}), error => error.name === 'EditorTimeoutError');
});

test('a bounded retry resends the exact operation and reconciles a lost response', async () => {
    let calls = 0;
    const body = new FormData();
    body.set('request_id', 'stable-operation');
    const request = helper(async (_url, options) => {
        assert.equal(options.body, body);
        if (++calls === 1) throw new Error('Lost response');
        return {json: async () => ({id: 12, replayed: true})};
    });
    const result = await request('/create', {body}, {timeoutMs: 100, retries: 1});
    assert.equal(result.data.id, 12);
    assert.equal(calls, 2);
});

test('non-idempotent operations do not retry and cancellation keeps its signal', async () => {
    let calls = 0;
    const controller = new AbortController();
    const request = helper(async (_url, {signal}) => {
        calls++;
        controller.abort();
        assert.equal(signal.aborted, true);
        throw new Error('Cancelled');
    });
    await assert.rejects(request('/media', {signal: controller.signal}, {timeoutMs: 100, retries: 1}));
    assert.equal(calls, 1);
});

test('validation and redirect responses are returned without retries', async () => {
    let calls = 0;
    const request = helper(async () => {
        calls++;
        return {status: 422, json: async () => ({field_errors: {title: ['Required']}})};
    });
    assert.equal((await request('/save', {}, {retries: 1})).response.status, 422);
    assert.equal(calls, 1);
    const redirect = helper(async () => ({redirected: true, json: () => { throw new Error('Not JSON'); }}));
    assert.equal((await redirect('/create')).data, null);
});
