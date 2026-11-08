import test from 'node:test';
import assert from 'node:assert/strict';
import { messageAuthor, syncAvatars } from '../../tools/telegram-comments/tgcloud/lib/avatars.js';

const config = { blogUrl: 'https://register.localhost/blog', token: 'a'.repeat(64), ownerUserId: 1,
    discussionChatId: -1000000000123, channelChatId: -1000000000111 };
const response = body => ({ ok: true, status: 200, json: async () => body });
const photo = { file_id: 'small_photo', file_unique_id: 'unique_photo', width: 160, height: 160 };

test('the avatar identity follows sender_chat for anonymous messages', () => {
    assert.equal(messageAuthor({ from: { id: 22 } }), 'user22');
    assert.equal(messageAuthor({ from: { id: 22 }, sender_chat: { id: -1000000000123 } }), 'channel123');
    assert.equal(messageAuthor({ from: { id: 0 } }), null);
});

test('a new author avatar is downloaded natively and fills older comments through authenticated bytes', async () => {
    const calls = [], downloads = [];
    const bytes = new Uint8Array([255, 216, 1, 2, 3]);
    const api = {
        async getUserProfilePhotos(input) {
            assert.deepEqual(input, { user_id: 22, offset: 0, limit: 1 });
            return { total_count: 1, photos: [[{ ...photo, file_id: 'large', width: 640, height: 640 }, photo]] };
        },
        async getFileContent(id) { downloads.push(id); return bytes; },
    };
    const result = await syncAvatars(config, api, async (url, options) => {
        calls.push({ url, options });
        assert.equal(options.redirect, 'error');
        assert.equal(options.headers['X-Register-Telegram-Token'], config.token);
        if (!options.method) return response({ success: true, authors: ['user22'] });
        assert.equal(options.body, bytes);
        assert.equal(options.headers['X-Register-Telegram-Author'], 'user22');
        assert.equal(options.headers['X-Register-Telegram-Chat'], '123');
        assert.equal(options.headers['X-Register-Telegram-Photo'], 'unique_photo');
        return response({ success: true, filled: 3 });
    }, ['user22']);
    assert.deepEqual(result, { filled: 3, unavailable: 0, failed: 0 });
    assert.deepEqual(downloads, ['small_photo']);
    assert.equal(calls.length, 2);
    assert.equal(calls[0].url, 'https://register.localhost/blog/_live/telegram/avatars?chat_id=123&authors=user22');
    assert.equal(calls[1].url, 'https://register.localhost/blog/_live/telegram/avatars');
});

test('messages sent as a channel use its small chat photo', async () => {
    const api = {
        async getChat(input) { assert.deepEqual(input, { chat_id: -1000000000123 });
            return { photo: { small_file_id: 'chat_photo', small_file_unique_id: 'chat_unique' } }; },
        async getFileContent(id) { assert.equal(id, 'chat_photo'); return new Uint8Array([1]); },
    };
    const result = await syncAvatars(config, api, async (_url, options) =>
        response(options.method ? { success: true, filled: 1 } : { success: true, authors: ['channel123'] }), ['channel123']);
    assert.equal(result.filled, 1);
});

test('an older group member is resolved before retrying an unavailable user identity', async () => {
    const calls = [];
    const api = {
        async getUserProfilePhotos() { calls.push('photos');
            if (calls.length === 1) throw Object.assign(new Error('user not found'), { code: 400 });
            return { photos: [[photo]] }; },
        async getChatMember(input) { calls.push('member'); assert.deepEqual(input, { chat_id: config.discussionChatId, user_id: 22 }); },
        async getFileContent() { return new Uint8Array([1]); },
    };
    const result = await syncAvatars(config, api, async (_url, options) =>
        response(options.method ? { success: true, filled: 1 } : { success: true, authors: ['user22'] }), ['user22']);
    assert.deepEqual(calls, ['photos', 'member', 'photos']);
    assert.equal(result.filled, 1);
});

test('a hidden or inaccessible photo gets a bounded negative result without downloading or rejecting comments', async () => {
    for (const inaccessible of [false, true]) {
        let negative = 0;
        const api = {
            async getUserProfilePhotos() {
                if (inaccessible) throw Object.assign(new Error('not accessible'), { code: 403 });
                return { total_count: 0, photos: [] };
            },
            async getChat() { return {}; },
            async getFileContent() { assert.fail('No picture is available'); },
        };
        const result = await syncAvatars(config, api, async (_url, options) => {
            if (!options.method) return response({ success: true, authors: ['user22'] });
            negative++;
            assert.deepEqual(JSON.parse(options.body), { chat_id: 123, author: 'user22', unavailable: true });
            return response({ success: true, filled: 0 });
        }, ['user22']);
        assert.deepEqual(result, { filled: 0, unavailable: 1, failed: 0 });
        assert.equal(negative, 1);
    }
});

test('a transient API failure is left retryable rather than recorded as a missing photo', async () => {
    let posts = 0;
    const result = await syncAvatars(config, { async getUserProfilePhotos() {
        throw Object.assign(new Error('temporary API failure'), { code: 500 });
    } }, async (_url, options) => {
        if (options.method) posts++;
        return response({ success: true, authors: ['user22'] });
    }, ['user22']);
    assert.deepEqual(result, { filled: 0, unavailable: 0, failed: 1 });
    assert.equal(posts, 0);
});

test('a public chat photo is imported even when the user profile photo gallery is empty', async () => {
    const api = {
        async getUserProfilePhotos() { return { total_count: 0, photos: [] }; },
        async getChat(input) {
            assert.deepEqual(input, { chat_id: 22 });
            return { photo: { small_file_id: 'public_photo', small_file_unique_id: 'public_unique' } };
        },
        async getFileContent(id) { assert.equal(id, 'public_photo'); return new Uint8Array([1, 2]); },
    };
    const result = await syncAvatars(config, api, async (_url, options) => {
        if (!options.method) return response({ success: true, authors: ['user22'] });
        assert.equal(options.headers['Content-Type'], 'application/octet-stream');
        assert.equal(options.headers['X-Register-Telegram-Photo'], 'public_unique');
        return response({ success: true, filled: 7 });
    }, ['user22']);
    assert.deepEqual(result, { filled: 7, unavailable: 0, failed: 0 });
});

test('an unavailable group member can still have a public photo in the private chat', async () => {
    const calls = [];
    const api = {
        async getUserProfilePhotos() { calls.push('photos'); throw Object.assign(new Error('unknown user'), { code: 400 }); },
        async getChatMember() { calls.push('member'); throw Object.assign(new Error('unknown member'), { code: 400 }); },
        async getChat() { calls.push('chat'); return { photo: { small_file_id: 'public_photo', small_file_unique_id: 'public_unique' } }; },
        async getFileContent() { return new Uint8Array([1]); },
    };
    const result = await syncAvatars(config, api, async (_url, options) =>
        response(options.method ? { success: true, filled: 1 } : { success: true, authors: ['user22'] }), ['user22']);
    assert.deepEqual(calls, ['photos', 'member', 'chat']);
    assert.deepEqual(result, { filled: 1, unavailable: 0, failed: 0 });
});

test('a transient public chat photo failure stays retryable while an inaccessible chat is negative cached', async () => {
    for (const code of [400, 500]) {
        let negatives = 0;
        const api = {
            async getUserProfilePhotos() { return { photos: [] }; },
            async getChat() { throw Object.assign(new Error('unavailable chat'), { code }); },
        };
        const result = await syncAvatars(config, api, async (_url, options) => {
            if (!options.method) return response({ success: true, authors: ['user22'] });
            negatives++;
            return response({ success: true, filled: 0 });
        }, ['user22']);
        assert.deepEqual(result, { filled: 0, unavailable: code === 400 ? 1 : 0, failed: code === 500 ? 1 : 0 });
        assert.equal(negatives, code === 400 ? 1 : 0);
    }
});

test('a lost avatar acknowledgement retries the same bytes without downloading them again', async () => {
    let downloads = 0, posts = 0;
    const bodies = [];
    const result = await syncAvatars(config, {
        async getUserProfilePhotos() { return { photos: [[photo]] }; },
        async getFileContent() { downloads++; return new Uint8Array([1, 2]); },
    }, async (_url, options) => {
        if (!options.method) return response({ success: true, authors: ['user22'] });
        bodies.push(options.body);
        if (++posts === 1) throw new Error('acknowledgement lost');
        return response({ success: true, filled: 0 });
    }, ['user22']);
    assert.equal(result.failed, 0);
    assert.equal(downloads, 1);
    assert.equal(posts, 2);
    assert.equal(bodies[0], bodies[1]);
});

test('network failure has bounded retries and cannot break the incoming comment handler', async () => {
    let calls = 0;
    const result = await syncAvatars(config, {}, async () => { calls++; throw new Error('network failed'); }, ['user22']);
    assert.equal(calls, 3);
    assert.deepEqual(result, { filled: 0, unavailable: 0, failed: 1 });
});

test('an already illustrated author does not cause a Telegram API call', async () => {
    const api = new Proxy({}, { get() { assert.fail('No author needs an avatar'); } });
    const result = await syncAvatars(config, api, async () => response({ success: true, authors: [] }), ['user22']);
    assert.deepEqual(result, { filled: 0, unavailable: 0, failed: 0 });
});

test('unexpected authors and oversized files are rejected before uploading any bytes', async () => {
    let calls = 0;
    const api = { async getUserProfilePhotos() { calls++; return { photos: [[{ ...photo, file_size: 524_289 }]] }; },
        async getFileContent() { assert.fail('The declared avatar is too large'); } };
    const unexpected = await syncAvatars(config, api, async () => response({ success: true, authors: ['user33'] }), ['user22']);
    assert.equal(unexpected.failed, 1);
    assert.equal(calls, 0);
    const oversized = await syncAvatars(config, api, async (_url, options) => {
        assert.equal(options.method, undefined);
        return response({ success: true, authors: ['user22'] });
    }, ['user22']);
    assert.equal(oversized.failed, 1);
    assert.equal(calls, 1);
});

test('no new avatar operation starts after the session deadline or while importing is disabled', async () => {
    let clock = 0, requests = 0;
    const result = await syncAvatars(config, {}, async () => {
        requests++; clock += 10_000;
        return response({ success: true, authors: ['user22'] });
    }, ['user22'], () => clock);
    assert.equal(requests, 1);
    assert.deepEqual(result, { filled: 0, unavailable: 0, failed: 0 });
    await syncAvatars({ ...config, enabled: false }, {}, () => assert.fail('Import is disabled'));
});
