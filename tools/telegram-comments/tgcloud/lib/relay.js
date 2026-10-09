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
    await store.enqueue(updateId, { current, parent, source: message, discussionChatId: config.discussionChatId });
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

export async function flush(config, store, send, now = Date.now, limit = 20) {
    let delivered = 0;
    let ignored = 0;
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
            if (parent) await store.remember(parent);
            await store.remember(current);
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
        try {
            const response = await send(result.archive);
            if (!response.ok) {
                await store.failed(row.id, response.error, now());
                if (response.status >= 500 || response.status === 429 || response.status === 401 || response.status === 404) break;
                continue;
            }
            await store.remove(row.id); // Only an explicit success acknowledges delivery.
            delivered++;
        } catch {
            await store.failed(row.id, 'network_error', now());
            break;
        }
    }
    return { delivered, ignored };
}
