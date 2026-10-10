import { sourceTime } from './protocol.js';

export function createStore(db, sql) {
    let migration;
    async function scopeLegacyCache(fallbackConfig) {
        if (!migration) migration = (async () => {
            const row = await db.get(sql`SELECT payload FROM configuration WHERE id = 1`);
            const previous = row ? JSON.parse(row.payload) : fallbackConfig;
            const prefix = `${previous.discussionChatId}:${previous.channelChatId}:`;
            // Bind the old cache once, before changing settings. Late legacy writes
            // cannot enter the new cache after this marker has been committed.
            await db.run(sql`INSERT INTO scoped_messages (id, source_time, update_id, payload)
                SELECT ${prefix} || id, source_time, update_id, payload FROM messages
                WHERE NOT EXISTS (SELECT 1 FROM configuration WHERE id = 2)
                ON CONFLICT(id) DO NOTHING`);
            await db.run(sql`INSERT INTO configuration (id, payload) VALUES (2, 'scoped_cache') ON CONFLICT(id) DO NOTHING`);
        })();
        try { await migration; } catch (error) { migration = null; throw error; }
    }
    return {
        async getConfig() {
            const row = await db.get(sql`SELECT payload FROM configuration WHERE id = 1`);
            return row ? JSON.parse(row.payload) : null;
        },
        async saveConfig(config, previousConfig = config) {
            await scopeLegacyCache(previousConfig);
            await db.run(sql`INSERT INTO configuration (id, payload) VALUES (1, ${JSON.stringify(config)})
                ON CONFLICT(id) DO UPDATE SET payload = excluded.payload`);
        },
        async enqueue(id, payload) {
            await db.run(sql`INSERT INTO pending (id, payload, last_attempt, last_error)
                VALUES (${id}, ${JSON.stringify(payload)}, 0, '') ON CONFLICT(id) DO NOTHING`);
        },
        async remember(message, config) {
            await scopeLegacyCache(config);
            const key = `${config.discussionChatId}:${config.channelChatId}:${message.id}`;
            const existing = await this.getMessage(message.id, config);
            const sameMessage = existing && existing.from_id === message.from_id
                && existing.date_unixtime === message.date_unixtime;
            // Inline reply targets omit their own reply target, including after edits.
            // Editing text never changes the message's ancestry.
            const completesAncestry = Boolean(sameMessage && !existing.forwarded_from_id && !message.forwarded_from_id
                && !existing.reply_to_message_id && message.reply_to_message_id);
            if (sameMessage && !message.forwarded_from_id && !message.reply_to_message_id && existing.reply_to_message_id) {
                message = { ...message, reply_to_message_id: existing.reply_to_message_id };
            }
            if (completesAncestry) {
                if (sourceTime(message) < sourceTime(existing)
                    || (sourceTime(message) === sourceTime(existing) && message.bot_update_id < existing.bot_update_id)) {
                    message = { ...existing, reply_to_message_id: message.reply_to_message_id };
                }
                message = { ...message, bot_update_id: Math.max(message.bot_update_id, existing.bot_update_id) };
            }
            const completesRoot = Boolean(message.forwarded_from_id && message.text && existing && existing.forwarded_from_id === message.forwarded_from_id
                && !existing.text && sourceTime(message) >= sourceTime(existing));
            if (completesRoot) message = { ...message, bot_update_id: Math.max(message.bot_update_id, existing.bot_update_id) };
            await db.run(sql`INSERT INTO scoped_messages (id, source_time, update_id, payload)
                VALUES (${key}, ${sourceTime(message)}, ${message.bot_update_id}, ${JSON.stringify(message)})
                ON CONFLICT(id) DO UPDATE SET source_time = excluded.source_time, update_id = excluded.update_id, payload = excluded.payload
                WHERE excluded.source_time > scoped_messages.source_time
                   OR (excluded.source_time = scoped_messages.source_time AND (excluded.update_id > scoped_messages.update_id
                       OR (${completesRoot || completesAncestry ? 1 : 0} = 1 AND scoped_messages.payload = ${JSON.stringify(existing)})))`);
        },
        async getMessage(id, config) {
            await scopeLegacyCache(config);
            const key = `${config.discussionChatId}:${config.channelChatId}:${id}`;
            const row = await db.get(sql`SELECT payload FROM scoped_messages WHERE id = ${key}`);
            return row ? JSON.parse(row.payload) : null;
        },
        async pending(limit) {
            const rows = await db.all(sql`SELECT id, payload FROM pending ORDER BY last_attempt, id LIMIT ${limit}`);
            return rows.map(row => ({ id: row.id, payload: JSON.parse(row.payload) }));
        },
        async remove(id) {
            await db.run(sql`DELETE FROM pending WHERE id = ${id}`);
        },
        async failed(id, error, timestamp) {
            await db.run(sql`UPDATE pending SET last_error = ${error}, last_attempt = ${timestamp} WHERE id = ${id}`);
        },
        async status() {
            const count = await db.get(sql`SELECT count(*) AS count FROM pending`);
            const errors = await db.all(sql`SELECT last_error, count(*) AS count FROM pending GROUP BY last_error ORDER BY count(*) DESC LIMIT 5`);
            return { count: count.count, errors };
        },
    };
}
