// Only opaque Telegram IDs cross the bridge. Register downloads and owns the bytes;
// bot tokens and expiring Telegram file URLs never appear in a comment.
export function messageMedia(message) {
    const files = [];
    function collect(source, depth = 0) {
        if (!source || depth > 32) return;
        if (Array.isArray(source.photo) && source.photo.length) {
            const photo = [...source.photo].sort((a, b) => (b.width * b.height) - (a.width * a.height))[0];
            files.push({ ...photo, kind: 'photo', extension: 'jpg', mime_type: 'image/jpeg' });
        }
        const formats = { video: ['mp4', 'video/mp4'], animation: ['mp4', 'video/mp4'],
            audio: ['mp3', 'audio/mpeg'], voice: ['ogg', 'audio/ogg'], voice_note: ['ogg', 'audio/ogg'],
            video_note: ['mp4', 'video/mp4'], document: ['bin', 'application/octet-stream'] };
        for (const [field, [extension, mime]] of Object.entries(formats)) {
            if (!source[field] || (field === 'document' && source.animation)) continue;
            files.push({ ...source[field], kind: 'file', extension, mime_type: source[field].mime_type ?? mime });
        }
        if (source.sticker) {
            const sticker = source.sticker;
            files.push({ ...sticker, kind: 'file', sticker: true,
                extension: sticker.is_animated ? 'tgs' : sticker.is_video ? 'webm' : 'webp',
                mime_type: sticker.is_animated ? 'application/x-tgsticker' : sticker.is_video ? 'video/webm' : 'image/webp' });
        }
        for (const block of source.blocks ?? []) collect(block, depth + 1);
        for (const item of source.items ?? []) collect(item, depth + 1);
    }
    collect(message);
    collect(message.rich_message);
    const seen = new Set();
    return files.filter(file => typeof file.file_id === 'string' && /^[A-Za-z0-9_-]{1,512}$/.test(file.file_id)
        && typeof file.file_unique_id === 'string' && /^[A-Za-z0-9_-]{1,128}$/.test(file.file_unique_id)
        && !seen.has(file.file_unique_id) && seen.add(file.file_unique_id)).map((file, index) => ({
        kind: file.kind,
        path: `live/${message.message_id}/${index + 1}-${file.file_unique_id}.${file.extension}`,
        file_id: file.file_id,
        file_unique_id: file.file_unique_id,
        file_size: Number.isSafeInteger(file.file_size) && file.file_size > 0 ? file.file_size : null,
        file_name: typeof file.file_name === 'string' ? file.file_name : `Telegram.${file.extension}`,
        mime_type: file.mime_type,
        sticker: file.sticker === true,
        emoji: file.sticker ? file.emoji ?? '' : '',
    }));
}
