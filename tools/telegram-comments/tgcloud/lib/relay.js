import { normaliseMessage, ready, snapshot } from './protocol.js';

export async function ingest(message, updateId, config, store) {
    if (!ready(config)) throw new Error('Configure the blog, bridge secret, owner and chats first');
    if (!Number.isSafeInteger(updateId) || updateId <= 0) throw new Error('The Telegram update_id is required');
    const current = normaliseMessage(message, updateId, config);
    if (!current || (!current.forwarded_from_id && !current.reply_to_message_id)) return;
    // Inline reply targets have no update_id of their own. Version zero cannot overwrite
    // a newer copy already observed by this bot.
    const parent = message.reply_to_message ? normaliseMessage(message.reply_to_message, 0, config) : null;
    await store.enqueue(updateId, { current, parent });
}

export async function flush(config, store, send, now = Date.now, limit = 20) {
    let delivered = 0;
    let ignored = 0;
    for (const row of await store.pending(limit)) {
        const { current, parent } = row.payload;
        if (parent) await store.remember(parent);
        await store.remember(current);
        const result = await snapshot(current.id, store, config);
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
