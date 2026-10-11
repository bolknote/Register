import { exportChatId, normaliseMessage, normaliseChannelPost, normaliseReaction, rootLinksToBlog, ready, snapshot } from './protocol.js';

export async function ingest(message, updateId, config, store) {
    if (!ready(config)) throw new Error('Configure the blog, bridge secret, owner and chats first');
    if (!Number.isSafeInteger(updateId) || updateId <= 0) throw new Error('The Telegram update_id is required');
    const current = normaliseMessage(message, updateId, config);
    if (!current || (!current.forwarded_from_id && !current.reply_to_message_id)) return false;
    // Inline reply targets have no update_id of their own. Version zero cannot overwrite
    // a newer copy already observed by this bot.
    const parent = message.reply_to_message ? normaliseMessage(message.reply_to_message, 0, config) : null;
    // Keep the source until acknowledgement so a parser fix can repair a queued event.
    await store.enqueue(updateId, { current, parent, source: message, discussionChatId: config.discussionChatId,
        channelChatId: config.channelChatId });
    return true;
}

export async function ingestChannelPost(message, updateId, config, store) {
    const current = normaliseChannelPost(message, updateId, config);
    if (!current || !ready(config) || !Number.isSafeInteger(updateId) || updateId <= 0 || !rootLinksToBlog(current, config)) return false;
    await store.enqueue(updateId, { kind: 'channel_post', source: message, channelChatId: config.channelChatId });
    return true;
}

export async function ingestReaction(event, updateId, config, store, counts = false) {
    const reaction = normaliseReaction(event, updateId, config, counts);
    if (!reaction || !ready(config) || !Number.isSafeInteger(updateId) || updateId <= 0) return false;
    await store.enqueue(updateId, { kind: 'reaction', reaction, discussionChatId: config.discussionChatId,
        channelChatId: config.channelChatId });
    return true;
}

async function deliver(row, archive, config, store, send, now, attempts) {
    for (let attempt = 1; attempt <= attempts; attempt++) {
        let response;
        try {
            response = await send(archive, config);
        } catch {
            await store.failed(row.id, 'network_error', now());
            if (attempt === attempts) return { ok: false, status: 503 };
            continue;
        }
        if (response.ok) return response;
        await store.failed(row.id, response.error, now());
        // Serverless has no timers. Retry a short outage within this invocation,
        // but leave rate limits and permanent errors for a later invocation.
        if (!(response.status >= 500 && response.status < 600) || attempt === attempts) return response;
    }
}

export async function flush(config, store, send, now = Date.now, limit = 20, requireDelivery = false, attempts = 1) {
    // Settings may change while another handler awaits delivery. Keep this operation's
    // scope and credentials together all the way through cache access and HTTP delivery.
    config = { ...config };
    attempts = Number.isSafeInteger(attempts) ? Math.max(1, Math.min(3, attempts)) : 1;
    let delivered = 0;
    let ignored = 0;
    let transientFailure = false;
    for (const row of await store.pending(limit)) {
        const sourceGroup = row.payload.discussionChatId ?? row.payload.source?.chat?.id;
        if ((sourceGroup !== undefined && sourceGroup !== config.discussionChatId && row.payload.kind !== 'channel_post')
            || (row.payload.channelChatId !== undefined && row.payload.channelChatId !== config.channelChatId)) {
            await store.failed(row.id, 'scope_changed', now());
            continue;
        }
        let result;
        if (row.payload.kind === 'reaction') {
            result = { archive: { reaction_update: row.payload.reaction } };
        } else if (row.payload.kind === 'channel_post') {
            const post = normaliseChannelPost(row.payload.source, row.id, config);
            result = post ? { archive: { id: exportChatId(config.channelChatId), name: 'Telegram channel',
                type: 'supergroup', messages: [post] } } : { error: 'unsupported_message' };
        } else {
            const current = row.payload.source ? normaliseMessage(row.payload.source, row.id, config) : row.payload.current;
            const parent = row.payload.source?.reply_to_message
                ? normaliseMessage(row.payload.source.reply_to_message, 0, config)
                : row.payload.parent;
            if (!current) {
                await store.failed(row.id, 'unsupported_message', now());
                continue;
            }
            if (parent) await store.remember(parent, config);
            await store.remember(current, config);
            result = await snapshot(current.id, store, config);
        }
        if (result.ignored) {
            await store.remove(row.id);
            ignored++;
            continue;
        }
        if (result.error) {
            await store.failed(row.id, result.error, now());
            continue;
        }
        const response = await deliver(row, result.archive, config, store, send, now, attempts);
        if (!response.ok) {
            if ((response.status >= 500 && response.status < 600) || response.status === 429) {
                transientFailure = true;
                break;
            }
            if (response.status === 401 || response.status === 404) break;
            continue;
        }
        await store.remove(row.id); // Only an explicit success acknowledges delivery.
        delivered++;
    }
    if (requireDelivery && transientFailure) {
        throw new Error('Register delivery is temporarily unavailable; retry this Telegram update.');
    }
    return { delivered, ignored };
}
