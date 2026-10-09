import { ready } from './protocol.js';
import { blogBaseUrl, relayHeaders } from './transport.js';

// An unavailable blog must not stop durable ingestion with the last known configuration.
export async function refreshConfig(config, store, fetch) {
    if (typeof config.botApiToken !== 'string' || !config.botApiToken
        || typeof config.blogUrl !== 'string' || !config.blogUrl.startsWith('https://')) return false;
    try {
        const response = await fetch(`${blogBaseUrl(config)}/_live/telegram/config`, {
            redirect: 'error',
            headers: { 'X-Register-Telegram-Bot-Token': config.botApiToken, ...relayHeaders(config) },
        });
        if (!response.ok) return false;
        const body = await response.json();
        const remote = body?.config;
        if (body?.success !== true || typeof remote?.enabled !== 'boolean') return false;
        const candidate = {
            ...config,
            enabled: remote.enabled,
            token: remote.token,
            discussionChatId: remote.discussionChatId,
            channelChatId: remote.channelChatId,
            ownerUserId: remote.ownerUserId,
            relayUrl: remote.relayUrl ?? config.relayUrl ?? '',
            relayToken: remote.relayToken ?? config.relayToken ?? '',
        };
        if (!ready({ ...candidate, enabled: true })) return false;
        await store.saveConfig(remote, config);
        Object.assign(config, candidate);
        return true;
    } catch { return false; }
}

export async function restoreConfig(config, store) {
    const remote = await store.getConfig();
    if (remote && typeof remote.enabled === 'boolean' && ready({ ...config, ...remote, enabled: true })) {
        Object.assign(config, remote);
    }
}
