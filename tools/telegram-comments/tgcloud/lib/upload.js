export const MAX_FILE_BYTES = 20_000_000;
export const CHUNK_BYTES = 1_048_576;

async function acknowledgement(response) {
    let body;
    try { body = await response.json(); } catch { body = null; }
    const errors = ['disabled', 'unauthorized', 'invalid_media', 'busy', 'payload_size'];
    if (!response.ok || body?.success !== true || !Number.isSafeInteger(body.received) || body.received < 0
        || typeof body.complete !== 'boolean' || typeof body.owned !== 'boolean') {
        return { ok: false, status: response.status,
            error: `media_http_${response.status}_${errors.includes(body?.error) ? body.error : 'unexpected_response'}` };
    }
    return { ok: true, ...body };
}

// Download only in the bot. Upload bytes in small resumable requests before delivering
// the comment snapshot. No URL with a Bot API token is passed to the blog.
export async function uploadMedia(archive, config, fetch, download) {
    const url = config.blogUrl.replace(/\/$/, '') + '/_live/telegram/media';
    for (const message of archive.messages ?? []) {
        const files = [];
        for (const media of message.telegram_media ?? []) {
            if (media.file_size > MAX_FILE_BYTES) continue;
            const probe = await acknowledgement(await fetch(url, {
                method: 'POST', redirect: 'error',
                headers: { 'Content-Type': 'application/json', 'X-Register-Telegram-Token': config.token },
                body: JSON.stringify({ chat_id: archive.id, message_id: message.id, file_unique_id: media.file_unique_id }),
            }));
            if (!probe.ok) return probe;
            if (probe.owned) continue;
            let bytes;
            if (probe.complete) {
                if (probe.received < 1 || probe.received > MAX_FILE_BYTES || (media.file_size && media.file_size !== probe.received)) {
                    return { ok: false, status: 503, error: 'media_size_mismatch' };
                }
                media.file_size = probe.received;
            } else if (!media.file_size) {
                const source = await download(media.file_id);
                bytes = source instanceof Uint8Array ? source : new Uint8Array(source);
                if (!bytes.length || bytes.length > MAX_FILE_BYTES) return { ok: false, status: 503, error: 'media_size_mismatch' };
                media.file_size = bytes.length;
            }

            files.push({ media, probe, bytes });
        }

        if (files.length) {
            const reservation = await acknowledgement(await fetch(url, {
                method: 'POST', redirect: 'error',
                headers: { 'Content-Type': 'application/json', 'X-Register-Telegram-Token': config.token },
                body: JSON.stringify({ chat_id: archive.id, message_id: message.id,
                    files: files.map(({ media }) => ({ file_unique_id: media.file_unique_id, file_size: media.file_size })) }),
            }));
            if (!reservation.ok) return reservation;
        }

        for (const file of files) {
            const { media, probe } = file;
            if (probe.complete) continue;
            const source = file.bytes ?? await download(media.file_id);
            const bytes = source instanceof Uint8Array ? source : new Uint8Array(source);
            if (!bytes.length || bytes.length > MAX_FILE_BYTES || (media.file_size && bytes.length !== media.file_size)
                || probe.received > bytes.length || probe.received % CHUNK_BYTES !== 0) {
                return { ok: false, status: 503, error: 'media_size_mismatch' };
            }
            media.file_size = bytes.length;
            for (let offset = probe.received; offset < bytes.length;) {
                const chunk = bytes.slice(offset, offset + CHUNK_BYTES);
                const result = await acknowledgement(await fetch(url, {
                    method: 'POST', redirect: 'error', headers: {
                        'Content-Type': 'application/octet-stream', 'X-Register-Telegram-Token': config.token,
                        'X-Register-Telegram-Chat': String(archive.id), 'X-Register-Telegram-Message': String(message.id),
                        'X-Register-Telegram-File': media.file_unique_id, 'X-Register-Telegram-Size': String(bytes.length),
                        'X-Register-Telegram-Offset': String(offset),
                    }, body: chunk,
                }));
                if (!result.ok) return result;
                if (result.received < offset + chunk.length || result.received > bytes.length) {
                    return { ok: false, status: 503, error: 'media_ack_mismatch' };
                }
                offset = result.received;
                if (offset === bytes.length && !result.complete) return { ok: false, status: 503, error: 'media_ack_mismatch' };
            }
        }
    }
    return { ok: true };
}
