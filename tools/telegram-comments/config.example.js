// Copy to tgcloud/lib/config.js. This private file is deployed to Telegram, never committed.
export default {
    botApiToken: '', // BotFather Bot API token, also saved in Register settings; never commit the real value.
    blogUrl: 'https://example.org',
    token: '', // 64 hex characters; a separate bridge secret, not either BotFather token.
    ownerUserId: 0, // /start in a private chat with this bot tells you your user ID.
    discussionChatId: 0, // /ids in the discussion group (after setting ownerUserId).
    channelChatId: 0, // Also returned by /ids.
};
