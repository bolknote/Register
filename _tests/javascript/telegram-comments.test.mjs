import { test } from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { readFileSync } from 'node:fs';
import { normaliseMessage, normaliseChannelPost, exportChatId, snapshot, textEntities, ready } from '../../tools/telegram-comments/tgcloud/lib/protocol.js';
import { messageMedia } from '../../tools/telegram-comments/tgcloud/lib/media.js';
import { createStore } from '../../tools/telegram-comments/tgcloud/lib/storage.js';
import { ingest, ingestChannelPost, ingestReaction, flush } from '../../tools/telegram-comments/tgcloud/lib/relay.js';
import { refreshConfig, restoreConfig } from '../../tools/telegram-comments/tgcloud/lib/settings.js';
import { blogBaseUrl, relayHeaders } from '../../tools/telegram-comments/tgcloud/lib/transport.js';

const config = {
    blogUrl: 'https://register.localhost', token: 'a'.repeat(64), ownerUserId: 22,
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
        CREATE TABLE pending (id INTEGER PRIMARY KEY, payload TEXT NOT NULL, last_attempt INTEGER NOT NULL DEFAULT 0, last_error TEXT NOT NULL DEFAULT '');
        CREATE TABLE configuration (id INTEGER PRIMARY KEY, payload TEXT NOT NULL);`);
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
    assert.equal(normaliseMessage({ ...root, sender_chat: { id: -1_000_000_000_999, title: 'Other channel' } }, 100, config), null);
    const store = sqliteStore(t);
    await ingest({ ...root, is_automatic_forward: false }, 1, config, store);
    assert.equal((await store.status()).count, 0);
    await ingest(root, 2, config, store);
    assert.deepEqual(await flush(config, store, async archive => {
        assert.equal(archive.messages.length, 1); // Register the post for later reactions without inserting a comment.
        return { ok: true };
    }), { delivered: 1, ignored: 0 });
});

test('real photo sizes, captionless videos and all sticker formats retain downloadable file identities', () => {
    const file = { file_id: 'valid-file', file_unique_id: 'unique', file_size: 123 };
    const photo = normaliseMessage({ ...first, text: undefined, photo: [
        { ...file, file_unique_id: 'small', width: 90, height: 90 }, { ...file, width: 800, height: 600 },
    ] }, 100, config);
    assert.equal(photo.text, '');
    assert.equal(photo.telegram_media[0].file_unique_id, 'unique');
    assert.equal(photo.telegram_media[0].path, 'live/2/1-unique.jpg');
    const video = normaliseMessage({ ...first, text: undefined, video: { ...file, mime_type: 'video/mp4' }, caption: 'A clip' }, 101, config);
    assert.equal(video.text, 'A clip');
    assert.equal(video.telegram_media[0].mime_type, 'video/mp4');
    for (const [flags, extension] of [[{}, 'webp'], [{ is_video: true }, 'webm'], [{ is_animated: true }, 'tgs']]) {
        const sticker = normaliseMessage({ ...first, text: undefined, sticker: { ...file, ...flags, emoji: '🙂' } }, 102, config);
        assert.equal(sticker.telegram_media[0].sticker, true);
        assert.ok(sticker.telegram_media[0].path.endsWith(`.${extension}`));
    }
    assert.equal(messageMedia({ message_id: 2, rich_message: { blocks: [
        { type: 'collage', blocks: [{ type: 'photo', photo: [{ ...file, width: 10, height: 10 }] }] },
        { type: 'video', video: { ...file, file_unique_id: 'another' } },
    ] } }).length, 2);
});

test('channel post IDs never collide with discussion IDs and reaction events survive lost acknowledgements', async t => {
    const store = sqliteStore(t);
    const post = { ...root, is_automatic_forward: undefined, chat: { id: config.channelChatId, type: 'channel' } };
    assert.equal(normaliseChannelPost({ ...post, chat: group }, 20, config), null);
    await ingestChannelPost(post, 20, config, store);
    await ingest(first, 21, config, store);
    const event = { chat: group, message_id: 2, date: 200, user: { id: 22 }, old_reaction: [], new_reaction: [{ type: 'emoji', emoji: '👍' }] };
    await ingestReaction(event, 22, config, store);
    await ingestReaction(event, 22, config, store);
    assert.equal((await store.status()).count, 3);
    const archives = [];
    await flush(config, store, async archive => { archives.push(archive); return { ok: false, status: 503, error: 'busy' }; });
    assert.equal((await store.status()).count, 3);
    await flush(config, store, async archive => { archives.push(archive); return { ok: true }; });
    assert.equal(archives[0].id, 111);
    assert.equal(archives[0].messages[0].channel_message_id, 1);
    assert.equal((await store.getMessage(1)).forwarded_from_id, 'channel111');
    assert.equal(archives.find(archive => archive.reaction_update)?.reaction_update.actor, 'user22');
    assert.equal((await store.status()).count, 0);
    await ingestReaction({ ...event, chat: post.chat, reactions: [{ type: { type: 'emoji', emoji: '👍' }, total_count: 3 }] }, 23, config, store, true);
    await flush(config, store, async archive => { assert.equal(archive.reaction_update.type, 'count'); return { ok: true }; });
    assert.equal(await ingestReaction({ ...event, chat: { id: -1000000000999 } }, 24, config, store), false);
});

test('automatic discussion forwards are scoped by sender_chat even when origin is chat or another channel', async t => {
    const store = sqliteStore(t);
    const chatOriginRoot = { ...root, forward_origin: { type: 'chat', sender_chat: { id: config.channelChatId }, date: 100 } };
    assert.equal(normaliseMessage(chatOriginRoot, 99, config)?.forwarded_from_id, 'channel111');
    const forwardedArticle = { ...root, forward_origin: { type: 'channel', chat: { id: -1_000_000_000_999 }, message_id: 50, date: 90 } };
    assert.equal(normaliseMessage(forwardedArticle, 99, config)?.forwarded_from_id, 'channel111');
    await ingest({ ...first, reply_to_message: chatOriginRoot }, 100, config, store);
    const result = await flush(config, store, async archive => {
        assert.equal(archive.messages[0].forwarded_from_id, 'channel111');
        return { ok: true };
    });
    assert.equal(result.delivered, 1);
    assert.equal((await store.status()).count, 0);
});

test('rich channel posts retain the first-line article link and formatted comment text', async t => {
    const store = sqliteStore(t);
    const richRoot = { ...root, text: undefined, entities: undefined, rich_message: { blocks: [
        { type: 'paragraph', text: [{ type: 'url', url: 'https://example.org/post', text: { type: 'bold', text: 'Article' } }, '\nExcerpt'] },
        { type: 'photo', caption: { text: 'Photo caption' } },
    ] } };
    const richComment = { ...first, text: undefined, reply_to_message: richRoot, rich_message: { blocks: [
        { type: 'paragraph', text: ['😀 ', { type: 'italic', text: 'A rich comment' }] },
    ] } };
    await ingest(richComment, 100, config, store);
    await flush(config, store, async archive => {
        assert.deepEqual(archive.messages[0].text_entities[0], { type: 'text_link', text: 'Article', href: 'https://example.org/post' });
        assert.equal(archive.messages[0].text, 'Article\nExcerpt\nPhoto caption');
        assert.equal(archive.messages[1].text, '😀 A rich comment');
        assert.equal(archive.messages[1].text_entities[1].type, 'italic');
        return { ok: true };
    });
    assert.equal((await store.status()).count, 0);
});

test('retry reparses the retained source instead of keeping an obsolete incomplete parent', async t => {
    const store = sqliteStore(t);
    await ingest(first, 100, config, store);
    const [row] = await store.pending(1);
    // Simulate an older parser's interpretation while preserving the genuine event.
    await store.remove(row.id);
    await store.enqueue(row.id, { ...row.payload, parent: null });
    assert.equal((await flush(config, store, async () => ({ ok: true }))).delivered, 1);
});

test('a full root snapshot repairs an empty cached root without changing its event identity', async t => {
    const store = sqliteStore(t);
    const fullRoot = normaliseMessage(root, 99, config);
    await store.remember({ ...fullRoot, text: '', text_entities: [] });
    await ingest(first, 100, config, store);
    await flush(config, store, async archive => {
        assert.equal(archive.messages[0].text, 'Example post');
        return { ok: true };
    });
    assert.equal((await store.getMessage(1)).bot_update_id, 99);
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

test('authenticated settings rotate the bridge key and survive an unavailable blog', async t => {
    const store = sqliteStore(t);
    const current = { ...config, botApiToken: 'test-bot-token' };
    const remote = { enabled: true, token: 'b'.repeat(64), ownerUserId: 44,
        discussionChatId: config.discussionChatId, channelChatId: config.channelChatId };
    assert.equal(await refreshConfig(current, store, async (url, options) => {
        assert.equal(url, config.blogUrl + '/_live/telegram/config');
        assert.equal(options.headers['X-Register-Telegram-Bot-Token'], 'test-bot-token');
        assert.equal(options.redirect, 'error');
        return { ok: true, json: async () => ({ success: true, config: remote }) };
    }), true);
    assert.equal(current.token, remote.token);
    assert.equal(current.ownerUserId, 44);
    const restarted = { ...config, botApiToken: 'test-bot-token' };
    await restoreConfig(restarted, store);
    assert.equal(restarted.token, remote.token);
    assert.equal(await refreshConfig(restarted, store, async () => { throw new Error('network down'); }), false);
    assert.equal(restarted.ownerUserId, 44);
    await refreshConfig(restarted, store, async () => ({ ok: true,
        json: async () => ({ success: true, config: { ...remote, enabled: false, ownerUserId: 0 } }) }));
    assert.equal(ready(restarted), false);
    assert.equal(restarted.ownerUserId, 0);
});

test('a rejected settings response cannot change secrets or trusted scope', async t => {
    const store = sqliteStore(t);
    const current = { ...config, botApiToken: 'test-bot-token' };
    for (const response of [
        { ok: false },
        { ok: true, json: async () => ({ success: false, config: { enabled: true, token: 'b'.repeat(64) } }) },
        { ok: true, json: async () => ({ success: true, config: { enabled: true, token: 'unsafe' } }) },
    ]) {
        assert.equal(await refreshConfig(current, store, async () => response), false);
        assert.deepEqual(current, { ...config, botApiToken: 'test-bot-token' });
    }
    assert.equal(await store.getConfig(), null);
});

test('optional HTTPS relay authenticates settings traffic without changing the canonical article host', async t => {
    const store = sqliteStore(t);
    const local = { ...config, botApiToken: '123456:fake-token', relayUrl: 'https://relay.example:8443', relayToken: 'b'.repeat(64) };
    assert.equal(blogBaseUrl(local), 'https://relay.example:8443/blog');
    assert.deepEqual(relayHeaders(local), { 'X-Register-Telegram-Relay-Key': 'b'.repeat(64) });
    assert.equal(ready({ ...local, relayUrl: 'http://relay.example' }), false);
    assert.equal(ready({ ...local, relayUrl: 'https://user:pass@relay.example' }), false);
    const changed = { enabled: true, token: 'c'.repeat(64), discussionChatId: config.discussionChatId,
        channelChatId: config.channelChatId, ownerUserId: config.ownerUserId, relayUrl: local.relayUrl, relayToken: local.relayToken };
    assert.equal(await refreshConfig(local, store, async (url, options) => {
        assert.equal(url, 'https://relay.example:8443/blog/_live/telegram/config');
        assert.equal(options.headers['X-Register-Telegram-Relay-Key'], local.relayToken);
        return { ok: true, json: async () => ({ success: true, config: changed }) };
    }), true);
    assert.equal(local.blogUrl, config.blogUrl);
    assert.equal(local.token, changed.token);
});

test('switching groups never uses another groups message IDs or loses its pending source', async t => {
    const store = sqliteStore(t);
    const current = { ...config, botApiToken: 'test-bot-token' };
    await ingest(first, 100, current, store);
    await store.remember(normaliseMessage(root, 99, current));
    await refreshConfig(current, store, async () => ({ ok: true, json: async () => ({ success: true, config: {
        enabled: true, token: config.token, ownerUserId: config.ownerUserId,
        discussionChatId: -1_000_000_000_999, channelChatId: config.channelChatId,
    } }) }));
    assert.equal(await store.getMessage(1), null);
    await flush(current, store, async () => assert.fail('the old group must not be delivered into the new scope'));
    assert.equal((await store.status()).count, 1);
    assert.equal((await store.status()).errors[0].last_error, 'scope_changed');
    assert.equal((await store.pending(1))[0].payload.source.text, 'First comment');
});
