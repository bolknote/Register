import { test } from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { readFileSync } from 'node:fs';
import { normaliseMessage, exportChatId, snapshot, textEntities } from '../../tools/telegram-comments/tgcloud/lib/protocol.js';
import { createStore } from '../../tools/telegram-comments/tgcloud/lib/storage.js';
import { ingest, flush } from '../../tools/telegram-comments/tgcloud/lib/relay.js';

const config = {
    blogUrl: 'https://example.org', token: 'a'.repeat(64), ownerUserId: 22,
    discussionChatId: -1_000_000_000_123, channelChatId: -1_000_000_000_111,
};
const group = { id: config.discussionChatId, type: 'supergroup' };
const root = {
    message_id: 1, date: 100, chat: group, sender_chat: { id: config.channelChatId, title: 'Example channel' },
    is_automatic_forward: true, forward_origin: { type: 'channel', chat: { id: config.channelChatId } },
    text: 'Example post', entities: [{ type: 'text_link', offset: 0, length: 12, url: 'http://register.localhost/apos' }],
};
const first = { message_id: 2, date: 101, chat: group, from: { id: 22, first_name: 'Reader' }, text: 'First comment', reply_to_message: root };
const reply = { message_id: 3, date: 102, chat: group, from: { id: 33, first_name: 'Another reader' }, text: 'A reply', reply_to_message: { ...first, reply_to_message: undefined } };

function sqliteStore(t) {
    const sqlite = new DatabaseSync(':memory:');
    t.after(() => sqlite.close());
    sqlite.exec(`CREATE TABLE messages (id INTEGER PRIMARY KEY, source_time INTEGER NOT NULL, update_id INTEGER NOT NULL, payload TEXT NOT NULL);
        CREATE TABLE pending (id INTEGER PRIMARY KEY, payload TEXT NOT NULL, last_attempt INTEGER NOT NULL DEFAULT 0, last_error TEXT NOT NULL DEFAULT '');`);
    const sql = (strings, ...values) => ({ text: strings.join('?'), values });
    const query = (method, q) => sqlite.prepare(q.text)[method](...q.values);
    return createStore({
        run: async q => query('run', q), get: async q => query('get', q), all: async q => query('all', q),
    }, sql);
}

test('the real Bot API message and reply become the same archive consumed by Register', async t => {
    const store = sqliteStore(t);
    await ingest(first, 100, config, store);
    await ingest(reply, 101, config, store);
    const archives = [];
    await flush(config, store, async archive => { archives.push(archive); return { ok: true }; });
    const fixture = JSON.parse(readFileSync(new URL('../_resources/telegram-live-snapshot.json', import.meta.url)));
    assert.deepEqual(archives.at(-1), fixture);
    assert.equal((await store.status()).count, 0);
});

test('failed delivery persists and retries, including a duplicated event and a lost acknowledgement', async t => {
    const store = sqliteStore(t);
    await ingest(first, 100, config, store);
    await ingest(first, 100, config, store);
    assert.equal((await store.status()).count, 1);
    await flush(config, store, async () => { throw new Error('network down'); });
    assert.equal((await store.status()).count, 1);
    assert.equal((await store.status()).errors[0].last_error, 'network_error');
    const retry = await flush(config, store, async () => ({ ok: true }));
    assert.equal(retry.delivered, 1);
    assert.equal((await store.status()).count, 0);
});

test('a reply received before its parent stays queued until the root can be resolved', async t => {
    const store = sqliteStore(t);
    await ingest(reply, 101, config, store);
    let sends = 0;
    const send = async () => { sends++; return { ok: true }; };
    await flush(config, store, send, () => 1);
    assert.equal(sends, 0);
    assert.equal((await store.status()).errors[0].last_error, 'missing_parent');
    await ingest(first, 100, config, store);
    await flush(config, store, send, () => 2);
    await flush(config, store, send, () => 3);
    assert.equal(sends, 2);
    assert.equal((await store.status()).count, 0);
});

test('an older update and an inline reply target cannot overwrite a newer edit', async t => {
    const store = sqliteStore(t);
    await ingest({ ...first, text: 'edited', edit_date: 200 }, 200, config, store);
    await flush(config, store, async () => ({ ok: true }));
    await ingest(first, 100, config, store);
    await ingest(reply, 101, config, store);
    await flush(config, store, async () => ({ ok: true }));
    assert.equal((await store.getMessage(2)).text, 'edited');
    const late = { ...first, text: 'same-second older edit', edit_date: 200 };
    await ingest(late, 199, config, store);
    await flush(config, store, async () => ({ ok: true }));
    assert.equal((await store.getMessage(2)).text, 'edited');
});

test('filters other groups and roots from other channels, and never trusts manual forwards', async t => {
    assert.equal(exportChatId(config.discussionChatId), 123);
    assert.throws(() => exportChatId(-123));
    assert.equal(normaliseMessage({ ...first, chat: { id: -123, type: 'supergroup' } }, 100, config), null);
    assert.equal(normaliseMessage({ ...root, forward_origin: { type: 'channel', chat: { id: -1_000_000_000_999 } } }, 100, config), null);
    const store = sqliteStore(t);
    await ingest({ ...root, is_automatic_forward: false }, 1, config, store);
    assert.equal((await store.status()).count, 0);
    await ingest(root, 2, config, store);
    assert.deepEqual(await flush(config, store, async () => assert.fail('root is not a comment')), { delivered: 0, ignored: 1 });
});

test('UTF-16 links, nested formatting, and unsafe URLs preserve all visible text for server sanitization', () => {
    const text = '😀 bold link\n<script>alert(1)</script>';
    const segments = textEntities(text, [
        { type: 'bold', offset: 3, length: 9 },
        { type: 'text_link', offset: 8, length: 4, url: 'javascript:alert(1)' },
    ]);
    assert.equal(segments.map(e => e.text).join(''), text);
    assert.deepEqual(segments[2], { type: 'text_link', text: 'link', href: 'javascript:alert(1)' });
});

test('busy and invalid responses keep the event, and cycles never become a blog comment', async t => {
    const store = sqliteStore(t);
    await ingest(first, 100, config, store);
    await flush(config, store, async () => ({ ok: false, status: 503, error: 'busy' }));
    assert.equal((await store.status()).count, 1);
    await flush(config, store, async () => ({ ok: false, status: 200, error: 'unexpected_response' }));
    assert.equal((await store.status()).count, 1);
    await store.remember({ ...normaliseMessage(first, 300, config), reply_to_message_id: 3 });
    await store.remember({ ...normaliseMessage(reply, 301, config), reply_to_message_id: 2 });
    assert.deepEqual(await snapshot(2, store, config), { error: 'reply_cycle_or_depth' });
});
