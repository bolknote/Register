import { messageMedia } from './media.js';

const GROUP_OFFSET = 1_000_000_000_000;

export function exportChatId(id) {
    if (!Number.isSafeInteger(id) || id >= -GROUP_OFFSET) throw new Error('Expected a Bot API supergroup/channel ID');
    return -id - GROUP_OFFSET;
}

export function ready(config) {
    return config.enabled !== false && /^https:\/\//.test(config.blogUrl)
        && /^[a-f0-9]{64}$/.test(config.token)
        && Number.isSafeInteger(config.ownerUserId) && config.ownerUserId >= 0
        && Number.isSafeInteger(config.discussionChatId) && config.discussionChatId < -GROUP_OFFSET
        && Number.isSafeInteger(config.channelChatId) && config.channelChatId < -GROUP_OFFSET
        && config.discussionChatId !== config.channelChatId;
}

// Telegram offsets and JS string indexes both use UTF-16 code units. Split at boundaries
// so overlapping formatting never duplicates or drops any text, including emoji.
export function textEntities(text, entities = []) {
    const types = new Set(['url', 'text_link', 'bold', 'italic', 'underline', 'strikethrough', 'spoiler', 'code', 'pre', 'blockquote', 'expandable_blockquote']);
    const valid = entities.filter(e => e && types.has(e.type)
        && Number.isInteger(e.offset) && e.offset >= 0
        && Number.isInteger(e.length) && e.length > 0 && e.offset + e.length <= text.length);
    const boundaries = [...new Set([0, text.length, ...valid.flatMap(e => [e.offset, e.offset + e.length])])].sort((a, b) => a - b);
    return boundaries.slice(0, -1).map((start, i) => {
        const end = boundaries[i + 1];
        const covered = valid.filter(e => e.offset <= start && e.offset + e.length >= end);
        const entity = covered.find(e => e.type === 'text_link' || e.type === 'url') ?? covered[0];
        const segment = { type: entity?.type ?? 'plain', text: text.slice(start, end) };
        if (entity?.type === 'text_link') segment.href = entity.url;
        if (entity?.type === 'url') {
            segment.type = 'link';
            segment.href = text.slice(entity.offset, entity.offset + entity.length);
        }
        if (entity?.type === 'expandable_blockquote') segment.type = 'blockquote';
        if (entity?.type === 'pre' && entity.language) segment.language = entity.language;
        return segment;
    });
}

function richInline(node, style = 'plain', depth = 0) {
    if (depth > 32) return [];
    if (typeof node === 'string') return [{ type: style, text: node }];
    if (!node || typeof node !== 'object') return [];
    if (Array.isArray(node)) return node.flatMap(child => richInline(child, style, depth + 1));
    if (node.type === 'custom_emoji') return richInline(node.alternative_text, style, depth + 1);
    if (node.type === 'mathematical_expression') return richInline(`$$${node.expression ?? ''}$$`, style, depth + 1);
    const formats = new Set(['bold', 'italic', 'underline', 'strikethrough', 'spoiler', 'code']);
    const parts = richInline(node.text, formats.has(node.type) ? node.type : style, depth + 1);
    if (node.type === 'url' && typeof node.url === 'string') {
        return parts.map(part => ({ type: 'text_link', text: part.text, href: node.url }));
    }
    return parts;
}

function richBlocks(blocks, depth = 0) {
    if (!Array.isArray(blocks) || depth > 32) return [];
    const parts = [];
    const append = content => {
        if (!content.length) return;
        if (parts.length) parts.push({ type: 'plain', text: '\n' });
        parts.push(...content);
    };
    for (const block of blocks) {
        if (!block || typeof block !== 'object') continue;
        if (block.type === 'list') {
            for (const item of block.items ?? []) append(richBlocks(item.blocks, depth + 1));
        } else if (block.type === 'table') {
            for (const row of block.cells ?? []) {
                if (!Array.isArray(row)) continue;
                const cells = [];
                for (const cell of row) {
                    if (cells.length) cells.push({ type: 'plain', text: '\t' });
                    cells.push(...richInline(cell.text));
                }
                append(cells);
            }
        } else if (block.type === 'details') {
            append(richInline(block.summary ?? block.title));
            append(richBlocks(block.blocks, depth + 1));
        } else {
            const text = block.text ?? block.caption?.text ?? block.expression;
            append(richInline(text, block.type === 'preformatted' ? 'code' : 'plain'));
            append(richBlocks(block.blocks, depth + 1));
        }
    }
    return parts;
}

export function richMessageEntities(message) {
    return richBlocks(message?.blocks);
}

export function normaliseMessage(message, updateId, config) {
    if (message?.chat?.id !== config.discussionChatId || message.chat.type !== 'supergroup'
        || !Number.isSafeInteger(message.message_id) || message.message_id < 1
        || !Number.isSafeInteger(message.date) || message.date < 1) return null;

    // sender_chat identifies the linked channel. forward_origin describes earlier
    // provenance and may be "chat", or an entirely different original channel.
    const root = message.is_automatic_forward === true
        && message.sender_chat?.id === config.channelChatId;
    // A manual forward never establishes a channel discussion root.
    if (message.is_automatic_forward && !root) return null;
    const parent = message.reply_to_message?.message_id;
    let text = message.text ?? message.caption ?? '';
    if (typeof text !== 'string') return null;
    const rich = !text && message.rich_message ? richMessageEntities(message.rich_message) : null;
    if (rich) text = rich.map(part => part.text).join('');
    const media = root ? [] : messageMedia(message);
    if (!root && !text && !media.length) return null; // Service messages are not comments.
    const sender = message.sender_chat ?? message.from;
    const result = {
        id: message.message_id,
        type: 'message',
        date_unixtime: String(message.date),
        bot_update_id: updateId,
        from: message.sender_chat?.title ?? ([message.from?.first_name, message.from?.last_name].filter(Boolean).join(' ') || 'Telegram user'),
        from_id: message.sender_chat ? `channel${Math.abs(sender.id) - GROUP_OFFSET}` : `user${sender?.id ?? 0}`,
        text,
        text_entities: rich ?? textEntities(text, message.entities ?? message.caption_entities ?? []),
    };
    if (media.length) result.telegram_media = media;
    if (root) {
        result.forwarded_from_id = `channel${exportChatId(config.channelChatId)}`;
        if (message.forward_origin?.type === 'channel' && message.forward_origin.chat?.id === config.channelChatId
            && Number.isSafeInteger(message.forward_origin.message_id) && message.forward_origin.message_id > 0) {
            result.channel_message_id = message.forward_origin.message_id;
        }
    }
    else if (Number.isSafeInteger(parent) && parent > 0 && parent !== result.id) result.reply_to_message_id = parent;
    if (Number.isSafeInteger(message.edit_date) && message.edit_date > message.date) result.edited_unixtime = String(message.edit_date);
    return result;
}

export function normaliseChannelPost(message, updateId, config) {
    if (message?.chat?.id !== config.channelChatId || message.chat.type !== 'channel') return null;
    const result = normaliseMessage({ ...message, chat: { id: config.discussionChatId, type: 'supergroup' },
        is_automatic_forward: true, sender_chat: message.chat }, updateId, config);
    if (result) result.channel_message_id = message.message_id;
    return result;
}

export function normaliseReaction(event, updateId, config, counts = false) {
    if (![config.discussionChatId, config.channelChatId].includes(event?.chat?.id)
        || !Number.isSafeInteger(event.message_id) || event.message_id <= 0
        || !Number.isSafeInteger(event.date) || event.date <= 0) return null;
    const actor = event.user ? `user${event.user.id}` : event.actor_chat ? `chat${event.actor_chat.id}` : null;
    if (!counts && !actor) return null;
    return { type: counts ? 'count' : 'change', chat_id: event.chat.id, message_id: event.message_id,
        date: event.date, update_id: updateId, ...(counts ? { reactions: event.reactions }
            : { actor, old_reaction: event.old_reaction, new_reaction: event.new_reaction }) };
}

export function sourceTime(message) {
    return Math.max(Number(message.date_unixtime), Number(message.edited_unixtime ?? 0));
}

export function rootLinksToBlog(message, config) {
    // Serverless has no global URL constructor. Keep this parser SDK-independent.
    const host = url => typeof url === 'string' ? (url.match(/^https?:\/\/(\[[0-9a-f:]+\]|[A-Za-z0-9.-]+)(?::[0-9]+)?(?=\/|$)/i)?.[1] ?? '').toLowerCase().replace(/^www\./, '') : '';
    const hostname = host(config.blogUrl);
    for (const entity of message.text_entities ?? []) {
        const text = String(entity.text ?? '');
        const candidates = entity.href ? [entity.href] : text.split('\n')[0].match(/https?:\/\/[^\s<>]+/g) ?? [];
        for (const candidate of candidates) {
            const path = typeof candidate === 'string' ? candidate.match(/^https?:\/\/[^/]+(\/[^\s]*)/i)?.[1]?.split(/[?#]/)[0] : null;
            if (hostname && host(candidate) === hostname && path && path !== '/') return true;
        }
        if (text.includes('\n')) break;
    }
    return false;
}

export async function snapshot(messageId, store, config) {
    const chain = [];
    const seen = new Set();
    let id = messageId;
    while (id) {
        if (seen.has(id) || chain.length >= 65) return { error: 'reply_cycle_or_depth' };
        seen.add(id);
        const message = await store.getMessage(id, config);
        if (!message) return { error: 'missing_parent' };
        chain.push(message);
        if (message.forwarded_from_id) {
            if (chain.length === 1 && !rootLinksToBlog(message, config)) return { ignored: true };
            return { archive: {
                id: exportChatId(config.discussionChatId),
                name: 'Telegram discussion',
                type: 'supergroup',
                messages: chain.reverse(),
            } };
        }
        if (!message.reply_to_message_id && message.bot_update_id === 0) return { error: 'missing_parent' };
        id = message.reply_to_message_id;
    }
    return { ignored: true }; // Ordinary group conversations do not belong to a channel post.
}
