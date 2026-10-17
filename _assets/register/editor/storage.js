/* Bounded local recovery storage shared by both editors. */
(() => {
    'use strict';

    const maximumRecordLength = 512 * 1024;
    const maximumTotalLength = 2 * 1024 * 1024;
    const maximumRecords = 10;

    // Only an authenticated editor supplies this namespace. Never store session
    // tokens, and never enumerate another account's local drafts.
    function createStore(storage, prefix, validate) {
        const key = record => prefix + record.id;
        function valid(record) {
                return record?.version === 1
                && typeof record.id === 'string' && /^[a-zA-Z0-9_-]{1,80}$/u.test(record.id)
                // Clock corrections can put a valid saved copy in the future.
                && Number.isSafeInteger(record.savedAt) && Number.isFinite(new Date(record.savedAt).getTime())
                && validate(record);
        }
        function entries() {
            const result = [];
            try {
                const keys = [];
                for (let index = 0; index < storage.length; index++) {
                    const item = storage.key(index);
                    if (item?.startsWith(prefix)) keys.push(item);
                }
                keys.forEach(item => {
                    const raw = storage.getItem(item);
                    let record;
                    try { record = raw?.length <= maximumRecordLength ? JSON.parse(raw) : null; } catch (_) { /* Ignore corrupt copies. */ }
                    if (valid(record) && key(record) === item) {
                        result.push({record, length: raw.length});
                    } else {
                        storage.removeItem(item);
                    }
                });
            } catch (_) { /* Storage may be blocked or unavailable. */ }
            return result.sort((left, right) => right.record.savedAt - left.record.savedAt);
        }
        function removeIfUnchanged(record) {
            const item = key(record);
            // Two snapshots can share a millisecond timestamp. Match the whole
            // saved version before deleting a copy offered by an older view.
            if (storage.getItem(item) !== JSON.stringify(record)) return false;
            storage.removeItem(item);
            return true;
        }
        return {
            list: target => entries().map(item => item.record).filter(record => target === undefined || record.target === target),
            save(record) {
                try {
                    if (!valid(record)) return false;
                    const raw = JSON.stringify(record);
                    if (raw.length > maximumRecordLength) return false;
                    // Preserve every valid older copy if this write fails. In
                    // particular, pruning first cannot safely recover from quota errors.
                    storage.setItem(key(record), raw);
                    // A different tab can update a copy during this write.
                    // Choose eviction candidates from the state after it completes.
                    let others = entries().filter(item => item.record.id !== record.id);
                    let size = raw.length + others.reduce((sum, item) => sum + item.length, 0);
                    let attempts = others.length + maximumRecords;
                    while (others.length >= maximumRecords || size > maximumTotalLength) {
                        // Keep saving bounded if other tabs keep changing candidates.
                        if (attempts-- === 0) return false;
                        const oldest = others.pop();
                        if (!oldest) return false;
                        if (removeIfUnchanged(oldest.record)) {
                            size -= oldest.length;
                        } else {
                            others = entries().filter(item => item.record.id !== record.id);
                            size = raw.length + others.reduce((sum, item) => sum + item.length, 0);
                        }
                    }
                    return true;
                } catch (_) { return false; }
            },
            remove(record) {
                try {
                    removeIfUnchanged(record);
                } catch (_) { /* Recovery must not prevent normal editing. */ }
            },
        };
    }

    function recordId() {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    }

    window.RegisterEditorStorage = Object.freeze({createStore, recordId});
})();
