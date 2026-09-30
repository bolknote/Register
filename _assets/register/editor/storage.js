/* Bounded local recovery storage shared by both editors. */
(() => {
    'use strict';

    const lifetime = 7 * 24 * 60 * 60 * 1000;
    const maximumRecordLength = 512 * 1024;
    const maximumTotalLength = 2 * 1024 * 1024;
    const maximumRecords = 10;

    // Only an authenticated editor supplies this namespace. Never store session
    // tokens, and never enumerate another account's local drafts.
    function createStore(storage, prefix, validate, now = () => Date.now()) {
        const key = record => prefix + record.id;
        function valid(record) {
            return record?.version === 1
                && typeof record.id === 'string' && /^[a-zA-Z0-9_-]{1,80}$/u.test(record.id)
                && Number.isSafeInteger(record.savedAt)
                && record.savedAt > now() - lifetime && record.savedAt <= now() + 60000
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
        return {
            list: target => entries().map(item => item.record).filter(record => target === undefined || record.target === target),
            save(record) {
                try {
                    if (!valid(record)) return false;
                    const raw = JSON.stringify(record);
                    if (raw.length > maximumRecordLength) return false;
                    const others = entries().filter(item => item.record.id !== record.id);
                    let size = raw.length + others.reduce((sum, item) => sum + item.length, 0);
                    // Preserve every valid older copy if this write fails. In
                    // particular, pruning first cannot safely recover from quota errors.
                    storage.setItem(key(record), raw);
                    while (others.length >= maximumRecords || size > maximumTotalLength) {
                        const oldest = others.pop();
                        if (!oldest) break;
                        storage.removeItem(key(oldest.record));
                        size -= oldest.length;
                    }
                    return true;
                } catch (_) { return false; }
            },
            remove(record) {
                try {
                    // A stale tab must never erase a newer snapshot from another tab.
                    const current = JSON.parse(storage.getItem(key(record)) || 'null');
                    if (current?.savedAt === record.savedAt) storage.removeItem(key(record));
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
