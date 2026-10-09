import { db } from 'sdk';
import { sql } from 'sdk/db';
import { createStore } from './storage.js';

export const store = createStore(db, sql);
