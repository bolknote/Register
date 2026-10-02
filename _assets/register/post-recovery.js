(() => {
    'use strict';

    // The server supplies both the installation scope and the authenticated owner.
    function createStore(storage, scope, userId, now = () => Date.now()) {
        if (!Number.isSafeInteger(userId) || userId < 1 || typeof scope !== 'string' || !scope) return null;
        return window.RegisterEditorStorage.createStore(storage,
            `register:post-recovery:1:${encodeURIComponent(scope)}:${userId}:`, record => {
                const snapshot = record.snapshot;
                return /^(?:new|[1-9][0-9]*)$/u.test(record.target)
                    && Number.isSafeInteger(record.revision) && record.revision >= 0
                    && snapshot && typeof snapshot.title === 'string'
                    && typeof snapshot.body === 'string' && typeof snapshot.tags === 'string'
                    && typeof snapshot.date === 'string' && typeof snapshot.slug === 'string'
                    && (snapshot.requestId === undefined || /^[a-zA-Z0-9_-]{16,80}$/u.test(snapshot.requestId))
                    && Array.isArray(snapshot.mediaIds) && snapshot.mediaIds.length <= 1000
                    && snapshot.mediaIds.every(id => Number.isSafeInteger(id) && id > 0);
            }, now);
    }

    function cleanBody(html) {
        const template = document.createElement('template');
        template.innerHTML = html;
        const root = template.content;
        // Only the source attribute is editable state; preview children are rebuilt in isolation.
        root.querySelectorAll('div[data-post-html-source]').forEach(node => node.replaceChildren());
        root.querySelectorAll('script, style, iframe, object, embed, link, meta, base, form, input, button, textarea, select, svg, math, template, .post-media-upload, .post-media-picture.is-processing')
            .forEach(node => node.remove());
        const elements = new Set('p br div span a b strong i em s del u tt code pre blockquote h1 h2 h3 h4 h5 h6 ul ol li hr img picture source figure figcaption audio video table thead tbody tfoot tr th td caption sub sup small mark abbr q nobr'.split(' '));
        const attributes = new Set('class title alt width height colspan rowspan start reversed type controls preload loop muted playsinline data-post-media-id data-post-media-identity data-title data-post-media-overlay data-caption-font data-caption-background data-post-html-source'.split(' '));
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
                } else if (name === 'role' && attribute.value === 'figure') {
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
