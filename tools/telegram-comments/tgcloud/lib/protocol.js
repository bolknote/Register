const GROUP_OFFSET = 1_000_000_000_000;

export function exportChatId(id) {
    if (!Number.isSafeInteger(id) || id >= -GROUP_OFFSET) throw new Error('Expected a Bot API supergroup/channel ID');
    return -id - GROUP_OFFSET;
}

export function ready(config) {
    return /^https:\/\//.test(config.blogUrl)
        && /^[a-f0-9]{64}$/.test(config.token)
        && Number.isSafeInteger(config.ownerUserId) && config.ownerUserId > 0
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

export function normaliseMessage(message, updateId, config) {
    if (message?.chat?.id !== config.discussionChatId || message.chat.type !== 'supergroup'
        || !Number.isSafeInteger(message.message_id) || message.message_id < 1
        || !Number.isSafeInteger(message.date) || message.date < 1) return null;

    const root = message.is_automatic_forward === true
        && message.forward_origin?.type === 'channel'
        && message.forward_origin.chat?.id === config.channelChatId;
    // A manual forward never establishes a channel discussion root.
    if (message.is_automatic_forward && !root) return null;
    const parent = message.reply_to_message?.message_id;
    let text = message.text ?? message.caption ?? '';
    if (typeof text !== 'string') return null;
    const attachment = ['photo', 'video', 'animation', 'audio', 'voice', 'video_note', 'document', 'sticker'].some(k => message[k]);
    if (!text && attachment) text = '[Вложение из Telegram]';
    if (!root && !text) return null; // Service messages are not comments.
    const sender = message.sender_chat ?? message.from;
    const result = {
        id: message.message_id,
        type: 'message',
        date_unixtime: String(message.date),
        bot_update_id: updateId,
        from: message.sender_chat?.title ?? ([message.from?.first_name, message.from?.last_name].filter(Boolean).join(' ') || 'Telegram user'),
        from_id: message.sender_chat ? `channel${Math.abs(sender.id) - GROUP_OFFSET}` : `user${sender?.id ?? 0}`,
        text,
        text_entities: textEntities(text, message.entities ?? message.caption_entities ?? []),
    };
    if (root) result.forwarded_from_id = `channel${exportChatId(config.channelChatId)}`;
    else if (Number.isSafeInteger(parent) && parent > 0 && parent !== result.id) result.reply_to_message_id = parent;
    if (Number.isSafeInteger(message.edit_date) && message.edit_date > message.date) result.edited_unixtime = String(message.edit_date);
    return result;
}

export function sourceTime(message) {
    return Math.max(Number(message.date_unixtime), Number(message.edited_unixtime ?? 0));
}

export async function snapshot(messageId, store, config) {
    const chain = [];
    const seen = new Set();
    let id = messageId;
    while (id) {
        if (seen.has(id) || chain.length >= 65) return { error: 'reply_cycle_or_depth' };
        seen.add(id);
        const message = await store.getMessage(id);
        if (!message) return { error: 'missing_parent' };
        chain.push(message);
        if (message.forwarded_from_id) {
            if (chain.length === 1) return { ignored: true }; // Remember roots, but they are not comments.
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
