export function validRelay(config) {
    return !config.relayUrl || (typeof config.relayUrl === 'string'
        && /^https:\/\/[A-Za-z0-9.-]+(?::[0-9]+)?(?:\/[A-Za-z0-9._~-]+)*\/?$/.test(config.relayUrl)
        && /^[a-f0-9]{64}$/.test(config.relayToken));
}

export function blogBaseUrl(config) {
    return (config.relayUrl && validRelay(config) ? config.relayUrl.replace(/\/$/, '') + '/blog' : config.blogUrl.replace(/\/$/, ''));
}

export function relayHeaders(config) {
    return config.relayUrl && validRelay(config) ? { 'X-Register-Telegram-Relay-Key': config.relayToken } : {};
}
