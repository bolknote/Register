import { table, integer, text } from 'sdk/db';

export const messages = table('messages', {
    id: integer('id').primaryKey(),
    sourceTime: integer('source_time').notNull(),
    updateId: integer('update_id').notNull(),
    payload: text('payload').notNull(),
});

// Persist the event before attempting any HTTP request. There is no timer or cron dependency.
export const pending = table('pending', {
    id: integer('id').primaryKey(),
    payload: text('payload').notNull(),
    lastAttempt: integer('last_attempt').notNull().default(0),
    lastError: text('last_error').notNull().default(''),
});
