import { api, fetch } from 'sdk';
import config from './config.js';
import { store } from './store.js';
import { ingest, ingestChannelPost, ingestReaction, flush } from './relay.js';
import { ready } from './protocol.js';
import { refreshConfig, restoreConfig } from './settings.js';
import { sendSnapshot } from './transfer.js';

async function send(archive, deliveryConfig) {
    return sendSnapshot(archive, deliveryConfig, fetch, id => api.getFileContent(id));
}

async function checkReceiver() {
    try {
        // A malformed, empty snapshot verifies the real handler's outbound route
        // without inserting or changing any comment.
        const response = await fetch(`${config.blogUrl.replace(/\/$/, '')}/_live/telegram/comments`, {
            method: 'POST', redirect: 'error',
            headers: { 'Content-Type': 'application/json', 'X-Register-Telegram-Token': config.token },
            body: JSON.stringify({ id: -config.discussionChatId - 1_000_000_000_000, type: 'supergroup', messages: [] }),
        });
        return `Сайт отвечает: HTTP ${response.status}.`;
    } catch {
        return 'Не удалось подключиться к сайту из Telegram.';
    }
}

export async function receiveEvent(event, ctx, kind) {
    await restoreConfig(config, store);
    const ingestEvent = () => kind === 'channel_post'
        ? ingestChannelPost(event, ctx.update.update_id, config, store)
        : ingestReaction(event, ctx.update.update_id, config, store, kind === 'reaction_count');
    const ingested = ready(config) ? await ingestEvent() : false;
    await refreshConfig(config, store, fetch);
    if (!ready(config)) return;
    if (!ingested) await ingestEvent();
    await flush(config, store, send);
}

export async function receive(message, ctx) {
    await restoreConfig(config, store);
    const command = typeof message.text === 'string' ? message.text.split(/\s/)[0].split('@')[0] : '';
    const privateChat = message.chat?.type === 'private';
    if (privateChat && command === '/start') {
        await api.sendMessage({ chat_id: message.chat.id, text: `Твой Telegram ID: ${message.from.id}.\nУкажи его в настройках блога → Telegram → «Твой ID в Telegram». Добавь бота в группу обсуждения и отправь там /ids.\nКоманды владельца в личке: /status и /retry.` });
        return;
    }
    // Store a known discussion event before contacting the blog for settings or delivery.
    const originalGroupId = config.discussionChatId;
    let ingested = false;
    if (message.chat?.id === originalGroupId && ready(config)) {
        ingested = await ingest(message, ctx.update.update_id, config, store);
    }
    await refreshConfig(config, store, fetch);
    const owner = Number.isSafeInteger(config.ownerUserId) && config.ownerUserId > 0
        && !message.sender_chat && message.from?.id === config.ownerUserId;
    if (owner && command === '/ids') {
        const chat = await api.getChat({ chat_id: message.chat.id });
        await api.sendMessage({ chat_id: message.chat.id, text: `chat_id: ${chat.id}\nlinked_chat_id: ${chat.linked_chat_id ?? 'нет'}\nНастройки блога → Telegram: chat_id → «ID группы обсуждения», linked_chat_id → «ID канала».` });
        return;
    }
    if (owner && privateChat && (command === '/status' || command === '/retry')) {
        if (!ready({ ...config, enabled: true })) {
            await api.sendMessage({ chat_id: message.chat.id, text: 'Настрой blogUrl, token, discussionChatId и channelChatId; затем разверни конфигурацию.' });
            return;
        }
        const result = command === '/retry' && ready(config) ? await flush(config, store, send) : null;
        const status = await store.status();
        const health = await checkReceiver();
        await api.sendMessage({ chat_id: message.chat.id, text: `${config.enabled === false ? 'Импорт выключен в настройках блога.\n' : ''}${result ? `Передано: ${result.delivered}.\n` : ''}В очереди: ${status.count}.\n${health}\n${status.errors.map(e => `${e.last_error || 'ожидает'}: ${e.count}`).join('\n')}` });
        return;
    }
    if (message.chat?.id !== config.discussionChatId || !ready(config)) return;
    if (!ingested) await ingest(message, ctx.update.update_id, config, store);
    await flush(config, store, send);
}
