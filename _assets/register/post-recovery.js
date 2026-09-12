(() => {
    'use strict';

    const lifetime = 7 * 24 * 60 * 60 * 1000;
    const maximumRecordLength = 512 * 1024;
    const maximumTotalLength = 2 * 1024 * 1024;
    const maximumRecords = 10;

    // Only an authenticated editor supplies this namespace. Never store session
    // tokens, and never enumerate another account's local drafts.
    function createStore(storage, scope, userId, now = () => Date.now()) {
        if (!Number.isSafeInteger(userId) || userId < 1 || typeof scope !== 'string' || !scope) {
            return null;
        }
        const prefix = `register:post-recovery:1:${encodeURIComponent(scope)}:${userId}:`;
        const key = record => prefix + record.id;
        function valid(record) {
            const snapshot = record?.snapshot;
            return record?.version === 1
                && typeof record.id === 'string' && /^[a-zA-Z0-9_-]{1,80}$/u.test(record.id)
                && /^(?:new|[1-9][0-9]*)$/u.test(record.target)
                && Number.isSafeInteger(record.revision) && record.revision >= 0
                && Number.isSafeInteger(record.savedAt)
                && record.savedAt > now() - lifetime && record.savedAt <= now() + 60000
                && snapshot && typeof snapshot.title === 'string'
                && typeof snapshot.body === 'string' && typeof snapshot.tags === 'string'
                && typeof snapshot.date === 'string' && typeof snapshot.slug === 'string'
                && Array.isArray(snapshot.mediaIds) && snapshot.mediaIds.length <= 1000
                && snapshot.mediaIds.every(id => Number.isSafeInteger(id) && id > 0);
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
                    while (others.length >= maximumRecords || size > maximumTotalLength) {
                        const oldest = others.pop();
                        if (!oldest) break;
                        storage.removeItem(key(oldest.record));
                        size -= oldest.length;
                    }
                    storage.setItem(key(record), raw);
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

    function cleanBody(html) {
        const template = document.createElement('template');
        template.innerHTML = html;
        const root = template.content;
        root.querySelectorAll('script, style, iframe, object, embed, link, meta, base, form, input, button, textarea, select, svg, math, template, .post-media-upload, .post-media-picture.is-processing')
            .forEach(node => node.remove());
        const elements = new Set('p br div span a b strong i em s del u tt code pre blockquote h1 h2 h3 h4 h5 h6 ul ol li hr img picture source figure figcaption audio video table thead tbody tfoot tr th td caption sub sup small mark abbr q nobr'.split(' '));
        const attributes = new Set('class title alt width height colspan rowspan start reversed type controls preload loop muted playsinline data-post-media-id data-title'.split(' '));
        root.querySelectorAll('*').forEach(node => {
            if (!elements.has(node.localName)) {
                node.replaceWith(...node.childNodes);
                return;
            }
            Array.from(node.attributes).forEach(attribute => {
                const name = attribute.name.toLowerCase();
                if (name === 'src' || name === 'href') {
                    try {
                        const url = new URL(attribute.value, document.baseURI);
                        if ((name === 'href' ? ['http:', 'https:', 'mailto:', 'tel:'] : ['http:', 'https:']).includes(url.protocol)) return;
                    } catch (_) { /* Remove malformed URLs. */ }
                } else if (attributes.has(name)) {
                    return;
                }
                node.removeAttribute(attribute.name);
            });
            if (node.matches('audio, video')) node.setAttribute('controls', '');
            if (node.matches('img, source') && !node.hasAttribute('src')) node.remove();
        });
        return template.innerHTML;
    }

    window.RegisterPostRecovery = Object.freeze({createStore, cleanBody});
})();
