import { api, fetch } from 'sdk';
import config from './config.js';
import { store } from './store.js';
import { ingest, flush } from './relay.js';
import { ready } from './protocol.js';

async function send(archive) {
    const response = await fetch(`${config.blogUrl.replace(/\/$/, '')}/_live/telegram/comments`, {
        method: 'POST',
        redirect: 'error',
        headers: { 'Content-Type': 'application/json', 'X-Register-Telegram-Token': config.token },
        body: JSON.stringify(archive),
    });
    let result;
    try { result = await response.json(); } catch { result = null; }
    return {
        ok: response.ok && result?.success === true,
        status: response.status,
        error: `http_${response.status}_${['disabled', 'unauthorized', 'post_not_found', 'invalid_snapshot', 'busy'].includes(result?.error) ? result.error : 'unexpected_response'}`,
    };
}

export async function receive(message, ctx) {
    const command = typeof message.text === 'string' ? message.text.split(/\s/)[0].split('@')[0] : '';
    const privateChat = message.chat?.type === 'private';
    if (privateChat && command === '/start') {
        await api.sendMessage({ chat_id: message.chat.id, text: `Твой Telegram ID: ${message.from.id}.\nДобавь его в ownerUserId. Затем добавь бота в группу обсуждения и отправь там /ids.\nКоманды владельца в личке: /status и /retry.` });
        return;
    }
    const owner = Number.isSafeInteger(config.ownerUserId) && config.ownerUserId > 0
        && !message.sender_chat && message.from?.id === config.ownerUserId;
    if (owner && command === '/ids') {
        const chat = await api.getChat({ chat_id: message.chat.id });
        await api.sendMessage({ chat_id: message.chat.id, text: `chat_id: ${chat.id}\nlinked_chat_id: ${chat.linked_chat_id ?? 'нет'}\nДля группы: chat_id → discussionChatId, linked_chat_id → channelChatId.` });
        return;
    }
    if (owner && privateChat && (command === '/status' || command === '/retry')) {
        if (!ready(config)) {
            await api.sendMessage({ chat_id: message.chat.id, text: 'Настрой blogUrl, token, discussionChatId и channelChatId; затем разверни конфигурацию.' });
            return;
        }
        const result = command === '/retry' ? await flush(config, store, send) : null;
        const status = await store.status();
        await api.sendMessage({ chat_id: message.chat.id, text: `${result ? `Передано: ${result.delivered}.\n` : ''}В очереди: ${status.count}.\n${status.errors.map(e => `${e.last_error || 'ожидает'}: ${e.count}`).join('\n')}` });
        return;
    }
    if (message.chat?.id !== config.discussionChatId || !ready(config)) return;
    await ingest(message, ctx.update.update_id, config, store);
    await flush(config, store, send);
}
