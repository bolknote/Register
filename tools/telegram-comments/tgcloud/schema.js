import { table, integer, text } from 'sdk/db';

export const messages = table('messages', {
    id: integer('id').primaryKey(),
    sourceTime: integer('source_time').notNull(),
    updateId: integer('update_id').notNull(),
    payload: text('payload').notNull(),
});

// Additive migration: leave the legacy cache intact, but never trust its unscoped IDs.
export const scopedMessages = table('scoped_messages', {
    id: text('id').primaryKey(),
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

// Cache the authenticated settings (id 1) and the legacy-cache migration marker (id 2).
export const configuration = table('configuration', {
    id: integer('id').primaryKey(),
    payload: text('payload').notNull(),
});
