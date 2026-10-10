import { uploadMedia } from './upload.js';

export const MAX_SNAPSHOT_BYTES = 262_144;

export function jsonBytes(value) {
    let bytes = 0;
    for (const character of JSON.stringify(value)) {
        const code = character.codePointAt(0);
        bytes += code < 0x80 ? 1 : code < 0x800 ? 2 : code < 0x10000 ? 3 : 4;
    }
    return bytes;
}

// Each acknowledgement makes the preceding comment safe to use as a reference.
// Keep at most one comment's attachments in staging, even for a long reply chain.
export function snapshotParts(archive) {
    if (!archive.messages || archive.messages.length < 3) return [archive];
    const [root, ...comments] = archive.messages;
    const references = [];
    return comments.map(comment => {
        const part = { ...archive, messages: [root, ...references, comment] };
        references.push({ id: comment.id, type: 'message', telegram_reference: true,
            reply_to_message_id: comment.reply_to_message_id });
        return part;
    });
}

export async function sendSnapshot(archive, config, fetch, download) {
    for (const part of snapshotParts(archive)) {
        if (jsonBytes(part) > MAX_SNAPSHOT_BYTES - 1024) {
            return { ok: false, status: 413, error: 'http_413_payload_size' };
        }
        const uploads = await uploadMedia(part, config, fetch, download);
        if (!uploads.ok) return uploads;
        const response = await fetch(`${config.blogUrl.replace(/\/$/, '')}/_live/telegram/comments`, {
            method: 'POST', redirect: 'error',
            headers: { 'Content-Type': 'application/json', 'X-Register-Telegram-Token': config.token },
            body: JSON.stringify(part),
        });
        let result;
        try { result = await response.json(); } catch { result = null; }
        if (!response.ok || result?.success !== true) {
            const errors = ['disabled', 'unauthorized', 'post_not_found', 'missing_target', 'invalid_snapshot', 'busy', 'media_pending', 'payload_size'];
            return { ok: false, status: response.status,
                error: `http_${response.status}_${errors.includes(result?.error) ? result.error : 'unexpected_response'}` };
        }
    }
    return { ok: true };
}
