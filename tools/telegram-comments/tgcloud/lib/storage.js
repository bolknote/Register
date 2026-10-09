import { sourceTime } from './protocol.js';

export function createStore(db, sql) {
    return {
        async enqueue(id, payload) {
            await db.run(sql`INSERT INTO pending (id, payload, last_attempt, last_error)
                VALUES (${id}, ${JSON.stringify(payload)}, 0, '') ON CONFLICT(id) DO NOTHING`);
        },
        async remember(message) {
            await db.run(sql`INSERT INTO messages (id, source_time, update_id, payload)
                VALUES (${message.id}, ${sourceTime(message)}, ${message.bot_update_id}, ${JSON.stringify(message)})
                ON CONFLICT(id) DO UPDATE SET source_time = excluded.source_time, update_id = excluded.update_id, payload = excluded.payload
                WHERE excluded.source_time > messages.source_time
                   OR (excluded.source_time = messages.source_time AND excluded.update_id > messages.update_id)`);
        },
        async getMessage(id) {
            const row = await db.get(sql`SELECT payload FROM messages WHERE id = ${id}`);
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
