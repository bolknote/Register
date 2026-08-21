(() => {
    'use strict';

    const contentType = 'application/vnd.register.page+json';
    const requestHeader = 'X-Register-Navigation';
    const managedHeadSelector = [
        'title',
        'meta[name="description"]',
        'meta[name="keywords"]',
        'meta[name="robots"]',
        'meta[name^="register-"]',
        'meta[property]',
        'link[rel="canonical"]',
        'link[rel="prev"]',
        'link[rel="next"]',
        'link[rel="up"]',
        'link[rel="alternate"]',
        'script[type="application/ld+json"]',
    ].join(',');
    const staticPath = /\.(?:avif|bmp|css|csv|epub|flac|gif|ico|jpe?g|js|json|m4a|mkv|mov|mp3|mp4|ogg|pdf|png|svg|tar|tgz|txt|wav|webm|webp|xml|zip)$/iu;
    const ignoredPathPrefixes = [
        '/_admin',
        '/_assets',
        '/_cache',
        '/_extensions',
        '/_inplace',
        '/_live',
        '/_pictures',
        '/_styles',
    ];
    const pageCache = new Map();
    const cacheLimit = 12;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let activeRequest = null;
    let busyTimer = 0;
    let currentUrl = window.location.href;
    let currentKey = history.state?.registerNavigationKey || createKey();
    let baselineAssets = collectDocumentAssets();

    if (
        !window.fetch
        || !window.history?.pushState
        || !document.querySelector('[data-register-page]')
    ) {
        return;
    }

    function createKey() {
        if (window.crypto?.randomUUID) {
            return window.crypto.randomUUID();
        }

        return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    }

    function normalizedAsset(value) {
        try {
            const url = new URL(value, document.baseURI);
            url.hash = '';
            return url.href;
        } catch (_error) {
            return '';
        }
    }

    function normalizeAssets(values) {
        return Array.from(new Set(values.map(normalizedAsset).filter(Boolean))).sort();
    }

    function collectDocumentAssets() {
        const values = Array.from(document.querySelectorAll('link[rel~="stylesheet"][href], script[src]'))
            .map((element) => element.getAttribute(element.matches('script') ? 'src' : 'href') || '');

        return normalizeAssets(values);
    }

    function serializeManagedHead() {
        return Array.from(document.head.querySelectorAll(managedHeadSelector))
            .map((element) => element.outerHTML)
            .join('\n');
    }

    function cleanCachedFragment(root) {
        const clone = root.cloneNode(true);
        const bindingAttributes = [
            'data-comment-storage-ready',
            'data-comment-reply-bound',
            'data-comment-moderation-bound',
            'data-comment-edit-bound',
        ];

        clone.querySelectorAll('#s2_search_tip').forEach((element) => element.remove());
        for (const attribute of bindingAttributes) {
            clone.querySelectorAll(`[${attribute}]`).forEach((element) => element.removeAttribute(attribute));
        }

        clone.querySelectorAll('.register-audio-player').forEach((player) => {
            const audio = player.querySelector(':scope > audio.register-audio-player__media');
            if (!audio) {
                return;
            }

            audio.setAttribute('controls', '');
            audio.classList.remove('register-audio-player__media');
            audio.removeAttribute('aria-hidden');
            audio.removeAttribute('tabindex');
            player.replaceWith(audio);
        });

        return clone.outerHTML;
    }

    function capturePage() {
        const root = document.querySelector('[data-register-page]');
        if (!root || root.querySelector('.is-editing, .is-confirming')) {
            return null;
        }

        return {
            version: 1,
            title: document.title,
            lang: document.documentElement.lang,
            bodyClass: document.body.className.replace(/\s*register-navigation-pending\s*/gu, ' ').trim(),
            head: serializeManagedHead(),
            fragment: cleanCachedFragment(root),
            assets: baselineAssets,
        };
    }

    function cacheCurrentPage() {
        const payload = capturePage();
        if (!payload) {
            return;
        }

        pageCache.delete(currentKey);
        pageCache.set(currentKey, {
            payload,
            scrollX: window.scrollX,
            scrollY: window.scrollY,
        });
        while (pageCache.size > cacheLimit) {
            pageCache.delete(pageCache.keys().next().value);
        }
    }

    function historyState(key) {
        const previous = history.state && typeof history.state === 'object' ? history.state : {};
        return {...previous, registerNavigationKey: key};
    }

    function sameDocumentHash(target) {
        const current = new URL(currentUrl);
        return target.origin === current.origin
            && target.pathname === current.pathname
            && target.search === current.search
            && target.hash !== current.hash;
    }

    function eligibleUrl(target) {
        if (!/^https?:$/u.test(target.protocol) || target.origin !== window.location.origin) {
            return false;
        }
        if (ignoredPathPrefixes.some((prefix) => target.pathname === prefix || target.pathname.startsWith(`${prefix}/`))) {
            return false;
        }

        return !staticPath.test(target.pathname);
    }

    function eligibleAnchor(anchor, target) {
        const rel = (anchor.getAttribute('rel') || '').split(/\s+/u);
        return !anchor.hasAttribute('download')
            && !anchor.matches('[data-history-back], [data-register-native-navigation]')
            && (!anchor.target || anchor.target === '_self')
            && !rel.includes('external')
            && !anchor.closest('.is-editing, .is-confirming, [contenteditable="true"]')
            && !document.querySelector('.post-card.is-editing, .post-card.is-confirming')
            && eligibleUrl(target);
    }

    function assetsMatch(payload) {
        return Array.isArray(payload.assets)
            && JSON.stringify(normalizeAssets(payload.assets)) === JSON.stringify(baselineAssets);
    }

    function validPayload(payload) {
        return payload
            && payload.version === 1
            && typeof payload.title === 'string'
            && typeof payload.lang === 'string'
            && typeof payload.bodyClass === 'string'
            && typeof payload.head === 'string'
            && typeof payload.fragment === 'string'
            && !/<script\b/iu.test(payload.fragment)
            && assetsMatch(payload);
    }

    function parseReplacement(fragment) {
        const template = document.createElement('template');
        template.innerHTML = fragment.trim();
        const replacement = template.content.firstElementChild;
        if (
            !(replacement instanceof HTMLElement)
            || replacement.id !== 'register-page'
            || !replacement.matches('[data-register-page]')
        ) {
            return null;
        }

        return replacement;
    }

    function activateMedia(root) {
        root.querySelectorAll('audio, video').forEach((media) => {
            media.load();
        });
    }

    function syncHead(headHtml) {
        const parsed = new DOMParser().parseFromString(
            `<!doctype html><html><head>${headHtml}</head><body></body></html>`,
            'text/html',
        );
        document.head.querySelectorAll(managedHeadSelector).forEach((element) => element.remove());
        parsed.head.querySelectorAll(managedHeadSelector).forEach((element) => {
            document.head.append(document.importNode(element, true));
        });
    }

    function dispatch(name, root) {
        document.dispatchEvent(new CustomEvent(name, {detail: {root}}));
    }

    function destroyWidgets(root) {
        if (window.RegisterReactions?.destroy) {
            window.RegisterReactions.destroy(root);
        }
        dispatch('register:fragment-will-update', root);
    }

    function enhanceWidgets(root) {
        const enhancers = [
            [window.RegisterReactions, 'enhance'],
            [window.RegisterLocalTime, 'enhance'],
            [window.RegisterSyntaxHighlighting, 'highlight'],
            [window.RegisterMath, 'render'],
            [window.RegisterAudioPlayerLoader, 'enhance'],
            [window.RegisterSearchAutocomplete, 'enhance'],
        ];
        for (const [api, method] of enhancers) {
            if (typeof api?.[method] !== 'function') {
                continue;
            }
            const result = api[method](root);
            if (result && typeof result.catch === 'function') {
                result.catch(() => {});
            }
        }
        dispatch('register:fragment-updated', root);
    }

    async function swapPage(payload) {
        const current = document.querySelector('[data-register-page]');
        const replacement = parseReplacement(payload.fragment);
        if (!current || !replacement) {
            throw new Error('Invalid partial page fragment.');
        }

        dispatch('register:navigation-will-update', current);
        destroyWidgets(current);

        let swapped = false;
        const swap = () => {
            if (swapped) {
                return;
            }
            swapped = true;
            syncHead(payload.head);
            document.title = payload.title;
            document.documentElement.lang = payload.lang;
            document.body.className = payload.bodyClass;
            current.replaceWith(replacement);
            activateMedia(replacement);
        };

        if (typeof document.startViewTransition === 'function' && !reducedMotion.matches && !document.hidden) {
            try {
                const transition = document.startViewTransition(swap);
                await transition.updateCallbackDone;
            } catch (_error) {
                swap();
            }
        } else {
            swap();
        }

        enhanceWidgets(replacement);
        dispatch('register:navigation-updated', replacement);
    }

    function scrollToTarget(url, position, focusContent) {
        window.requestAnimationFrame(() => {
            if (position) {
                window.scrollTo(position.x, position.y);
                return;
            }

            let target = null;
            if (url.hash) {
                try {
                    target = document.getElementById(decodeURIComponent(url.hash.slice(1)));
                } catch (_error) {
                    target = null;
                }
            }
            if (target) {
                target.scrollIntoView();
            } else {
                window.scrollTo(0, 0);
            }

            if (focusContent) {
                document.getElementById('content')?.focus({preventScroll: true});
            }
        });
    }

    function beginBusy() {
        window.clearTimeout(busyTimer);
        document.querySelector('[data-register-page]')?.setAttribute('aria-busy', 'true');
        busyTimer = window.setTimeout(() => document.body.classList.add('register-navigation-pending'), 120);
    }

    function endBusy() {
        window.clearTimeout(busyTimer);
        document.body.classList.remove('register-navigation-pending');
        document.querySelector('[data-register-page]')?.removeAttribute('aria-busy');
    }

    function hardNavigate(url) {
        window.location.assign(url.href);
    }

    function responseSecurityHeaders(response) {
        const names = [
            'Content-Security-Policy',
            'Content-Security-Policy-Report-Only',
            'Permissions-Policy',
            'Referrer-Policy',
            'Reporting-Endpoints',
            'X-Content-Type-Options',
        ];
        const result = {};
        for (const name of names) {
            const value = response.headers.get(name);
            if (value) {
                result[name] = value;
            }
        }

        return result;
    }

    async function navigate(target, options = {}) {
        const url = target instanceof URL ? target : new URL(target, document.baseURI);
        if (!eligibleUrl(url) || !navigator.onLine) {
            hardNavigate(url);
            return;
        }

        activeRequest?.abort();
        const request = new AbortController();
        activeRequest = request;
        beginBusy();

        try {
            const response = await window.fetch(url.href, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': contentType,
                    [requestHeader]: 'partial',
                },
                cache: 'no-store',
                redirect: 'follow',
                signal: request.signal,
            });
            const finalUrl = new URL(response.url || url.href, document.baseURI);
            if (url.hash && !finalUrl.hash) {
                finalUrl.hash = url.hash;
            }
            if (!response.headers.get('Content-Type')?.toLowerCase().startsWith(contentType)) {
                hardNavigate(finalUrl);
                return;
            }

            const payload = await response.json();
            if (!validPayload(payload)) {
                hardNavigate(finalUrl);
                return;
            }

            cacheCurrentPage();
            const mode = options.mode || 'push';
            if (mode === 'push') {
                currentKey = createKey();
                history.pushState(historyState(currentKey), '', finalUrl.href);
            } else if (mode === 'replace') {
                currentKey = options.key || createKey();
                history.replaceState(historyState(currentKey), '', finalUrl.href);
            } else {
                currentKey = options.key || history.state?.registerNavigationKey || createKey();
                if (!history.state?.registerNavigationKey) {
                    history.replaceState(historyState(currentKey), '', finalUrl.href);
                }
            }
            currentUrl = finalUrl.href;

            await swapPage(payload);
            baselineAssets = normalizeAssets(payload.assets);
            document.documentElement.dataset.registerNavigation = 'partial';
            scrollToTarget(finalUrl, options.scroll || null, mode === 'push');
            cacheCurrentPage();

            const offline = window.RegisterOffline;
            if (offline && typeof offline.storePage === 'function') {
                const stored = offline.storePage(payload, responseSecurityHeaders(response));
                if (stored && typeof stored.catch === 'function') {
                    stored.catch(() => {});
                }
            }
        } catch (error) {
            if (error?.name !== 'AbortError') {
                hardNavigate(url);
            }
        } finally {
            if (activeRequest === request) {
                activeRequest = null;
                endBusy();
            }
        }
    }

    document.addEventListener('click', (event) => {
        if (
            event.defaultPrevented
            || event.button !== 0
            || event.metaKey
            || event.ctrlKey
            || event.shiftKey
            || event.altKey
        ) {
            return;
        }
        const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!anchor) {
            return;
        }

        const target = new URL(anchor.href, document.baseURI);
        if (sameDocumentHash(target) || !eligibleAnchor(anchor, target) || !navigator.onLine) {
            return;
        }

        event.preventDefault();
        navigate(target);
    }, false);

    document.addEventListener('submit', (event) => {
        if (event.defaultPrevented || !(event.target instanceof HTMLFormElement)) {
            return;
        }
        const form = event.target;
        if (
            form.method.toLowerCase() !== 'get'
            || form.matches('[data-register-native-navigation]')
            || (form.target && form.target !== '_self')
            || form.closest('.is-editing, .is-confirming')
            || document.querySelector('.post-card.is-editing, .post-card.is-confirming')
        ) {
            return;
        }

        const target = new URL(form.action || window.location.href, document.baseURI);
        if (!eligibleUrl(target) || !navigator.onLine) {
            return;
        }
        const data = event.submitter ? new FormData(form, event.submitter) : new FormData(form);
        target.search = new URLSearchParams(data).toString();
        event.preventDefault();
        navigate(target);
    }, false);

    window.addEventListener('popstate', (event) => {
        const target = new URL(window.location.href);
        const key = event.state?.registerNavigationKey;
        if (key === currentKey && sameDocumentHash(target)) {
            currentUrl = target.href;
            scrollToTarget(target, null, false);
            return;
        }

        cacheCurrentPage();
        activeRequest?.abort();
        const cached = key ? pageCache.get(key) : null;
        if (cached) {
            currentKey = key;
            currentUrl = target.href;
            swapPage(cached.payload).then(() => {
                baselineAssets = normalizeAssets(cached.payload.assets);
                document.documentElement.dataset.registerNavigation = 'history';
                scrollToTarget(target, {x: cached.scrollX, y: cached.scrollY}, false);
            }).catch(() => hardNavigate(target));
            return;
        }

        navigate(target, {mode: 'pop', key});
    }, false);

    window.addEventListener('hashchange', () => {
        currentUrl = window.location.href;
    }, false);

    history.scrollRestoration = 'manual';
    document.documentElement.dataset.registerNavigation = 'ready';
    history.replaceState(historyState(currentKey), '', window.location.href);
    cacheCurrentPage();

    window.RegisterNavigation = Object.freeze({
        navigate: (url) => navigate(new URL(url, document.baseURI)),
    });
})();
