import { exportChatId, ready } from './protocol.js';
import { createDeliverySession } from './request.js';

const MAX_BYTES = 524_288;
const validAuthor = author => typeof author === 'string' && /^(user|channel)[1-9][0-9]{0,15}$/.test(author)
    && Number.isSafeInteger(Number(author.replace(/^(user|channel)/, '')));

export function messageAuthor(message) {
    if (message?.sender_chat?.id < -1_000_000_000_000) return `channel${exportChatId(message.sender_chat.id)}`;
    return Number.isSafeInteger(message?.from?.id) && message.from.id > 0 ? `user${message.from.id}` : null;
}

async function photoFor(author, api, config) {
    const id = Number(author.replace(/^(user|channel)/, ''));
    if (author.startsWith('channel')) {
        const chat = await api.getChat({ chat_id: -1_000_000_000_000 - id });
        return chat.photo ? { file_id: chat.photo.small_file_id, file_unique_id: chat.photo.small_file_unique_id } : null;
    }
    let result;
    try {
        result = await api.getUserProfilePhotos({ user_id: id, offset: 0, limit: 1 });
    } catch (error) {
        if (error?.code !== 400 || typeof api.getChatMember !== 'function') throw error;
        // Resolving a group member can make an older user's identity available to the Bot API.
        await api.getChatMember({ chat_id: config.discussionChatId, user_id: id });
        result = await api.getUserProfilePhotos({ user_id: id, offset: 0, limit: 1 });
    }
    return [...(result.photos?.[0] ?? [])].sort((a, b) => a.width * a.height - b.width * b.height)[0] ?? null;
}

/** Optional enrichment after comment delivery. Its failures never reject a Telegram update. */
export async function syncAvatars(config, api, fetch, authors = [], now = Date.now) {
    const summary = { filled: 0, unavailable: 0, failed: 0 };
    if (!ready(config) || !Array.isArray(authors) || authors.length > 20 || authors.some(author => !validAuthor(author))) return summary;
    const started = now();
    const session = createDeliverySession(fetch, id => api.getFileContent(id), { maxRetries: 2, maxDurationMs: 10_000, now });
    const scope = exportChatId(config.discussionChatId);
    const url = `${config.blogUrl.replace(/\/$/, '')}/_live/telegram/avatars`;
    const headers = { 'X-Register-Telegram-Token': config.token };
    try {
        const response = await session.fetch(`${url}?chat_id=${scope}&authors=${authors.join(',')}`, { redirect: 'error', headers });
        const result = await response.json();
        if (!response.ok || result.success !== true || !Array.isArray(result.authors)
            || result.authors.length > 10 || result.authors.some(author => !validAuthor(author)
                || (authors.length > 0 && !authors.includes(author)))) {
            summary.failed++;
            return summary;
        }
        for (const author of result.authors) {
            if (now() - started >= 10_000) break;
            try {
                let photo;
                try { photo = await photoFor(author, api, config); }
                catch (error) {
                    if (![400, 403].includes(error?.code)) throw error;
                    photo = null;
                }
                let options;
                if (!photo) {
                    options = { method: 'POST', redirect: 'error', headers: { ...headers, 'Content-Type': 'application/json' },
                        body: JSON.stringify({ chat_id: scope, author, unavailable: true }) };
                } else {
                    if (typeof photo.file_id !== 'string' || !/^[A-Za-z0-9_-]{1,512}$/.test(photo.file_id)
                        || typeof photo.file_unique_id !== 'string' || !/^[A-Za-z0-9_-]{1,128}$/.test(photo.file_unique_id)
                        || (photo.file_size ?? 0) > MAX_BYTES) throw new Error('Invalid avatar file.');
                    const bytes = await session.download(photo.file_id);
                    if (!(bytes instanceof Uint8Array) || bytes.length === 0 || bytes.length > MAX_BYTES) throw new Error('Invalid avatar size.');
                    options = { method: 'POST', redirect: 'error', headers: { ...headers,
                        'Content-Type': 'application/octet-stream', 'X-Register-Telegram-Chat': String(scope),
                        'X-Register-Telegram-Author': author, 'X-Register-Telegram-Photo': photo.file_unique_id }, body: bytes };
                }
                const sent = await session.fetch(url, options);
                const ack = await sent.json();
                if (!sent.ok || ack.success !== true || !Number.isSafeInteger(ack.filled) || ack.filled < 0) throw new Error('Avatar was not acknowledged.');
                summary.filled += ack.filled;
                if (!photo) summary.unavailable++;
            } catch { summary.failed++; }
        }
    } catch { summary.failed++; }
    return summary;
}
