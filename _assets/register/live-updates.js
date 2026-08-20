(() => {
    'use strict';

    const config = document.querySelector('meta[name="register-live-updates"]');
    if (!config) {
        return;
    }

    const endpoint = config.dataset.endpoint || '';
    let cursor = Number.parseInt(config.dataset.cursor || '', 10);
    let regions;
    try {
        regions = JSON.parse(config.dataset.regions || '[]');
    } catch (_error) {
        return;
    }
    if (
        endpoint === ''
        || !Number.isSafeInteger(cursor)
        || cursor < 0
        || !Array.isArray(regions)
        || regions.length === 0
        || regions.some((region) => typeof region !== 'string')
    ) {
        return;
    }

    const pollInterval = 15000;
    const maximumRetryInterval = 60000;
    const pendingPatches = new Map();
    let timer = 0;
    let inFlight = false;
    let retryCount = 0;
    let refreshRequested = false;

    function findRegion(name, scope = document) {
        if (scope instanceof Element && scope.dataset.liveRegion === name) {
            return scope;
        }

        for (const element of scope.querySelectorAll('[data-live-region]')) {
            if (element.dataset.liveRegion === name) {
                return element;
            }
        }

        return null;
    }

    function isLocked(region) {
        const active = document.activeElement;
        return (active instanceof Element && region.contains(active))
            || region.matches('[data-live-lock]')
            || region.querySelector(
                '[data-live-lock], .comment-form-block, .is-editing, .is-confirming, [aria-busy="true"]'
            ) !== null;
    }

    function destroyWidgets(region) {
        const reactions = window.RegisterReactions;
        if (reactions && typeof reactions.destroy === 'function') {
            reactions.destroy(region);
        }

        document.dispatchEvent(new CustomEvent('register:fragment-will-update', {
            detail: {root: region},
        }));
    }

    function enhanceWidgets(region) {
        const enhancers = [
            [window.RegisterReactions, 'enhance'],
            [window.RegisterLocalTime, 'enhance'],
            [window.RegisterSyntaxHighlighting, 'highlight'],
            [window.RegisterMath, 'render'],
            [window.RegisterAudioPlayerLoader, 'enhance'],
        ];
        for (const [api, method] of enhancers) {
            if (api && typeof api[method] === 'function') {
                const result = api[method](region);
                if (result && typeof result.catch === 'function') {
                    result.catch(() => {});
                }
            }
        }

        document.dispatchEvent(new CustomEvent('register:fragment-updated', {
            detail: {root: region},
        }));
    }

    function applyPatch(name, html) {
        const current = findRegion(name);
        if (!current || isLocked(current)) {
            return false;
        }

        const template = document.createElement('template');
        template.innerHTML = html;
        const replacement = findRegion(name, template.content);
        if (!replacement) {
            return false;
        }

        destroyWidgets(current);
        current.replaceWith(replacement);
        enhanceWidgets(replacement);

        return true;
    }

    function applyPendingPatches() {
        for (const [name, html] of pendingPatches) {
            if (applyPatch(name, html)) {
                pendingPatches.delete(name);
            }
        }
    }

    function schedule(delay) {
        window.clearTimeout(timer);
        if (document.hidden || !navigator.onLine) {
            return;
        }
        timer = window.setTimeout(poll, Math.max(0, delay));
    }

    async function poll() {
        if (inFlight || document.hidden) {
            return;
        }

        inFlight = true;
        refreshRequested = false;
        applyPendingPatches();
        let nextDelay = pollInterval;

        try {
            const url = new URL(endpoint, document.baseURI);
            url.searchParams.set('cursor', String(cursor));
            for (const region of regions) {
                url.searchParams.append('region[]', region);
            }

            const response = await window.fetch(url, {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                cache: 'no-store',
            });
            const payload = await response.json().catch(() => null);
            if (
                !response.ok
                || !payload
                || !Number.isSafeInteger(payload.cursor)
                || payload.cursor < cursor
                || typeof payload.patches !== 'object'
                || payload.patches === null
            ) {
                throw new Error('Invalid live-update response.');
            }

            cursor = payload.cursor;
            for (const [name, html] of Object.entries(payload.patches)) {
                if (regions.includes(name) && typeof html === 'string') {
                    pendingPatches.set(name, html);
                }
            }
            applyPendingPatches();
            document.dispatchEvent(new CustomEvent('register:live-synchronized'));

            retryCount = 0;
            nextDelay = payload.more === true ? 0 : pollInterval + Math.floor(Math.random() * 1500);
        } catch (_error) {
            document.dispatchEvent(new CustomEvent('register:live-unavailable'));
            retryCount += 1;
            nextDelay = Math.min(maximumRetryInterval, pollInterval * (2 ** Math.min(retryCount, 2)))
                + Math.floor(Math.random() * 2000);
        } finally {
            inFlight = false;
            schedule(refreshRequested ? 0 : nextDelay);
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            window.clearTimeout(timer);
        } else {
            schedule(0);
        }
    });
    document.addEventListener('focusout', () => window.setTimeout(applyPendingPatches, 0));
    document.addEventListener('register:live-refresh', () => {
        refreshRequested = true;
        if (!inFlight) {
            schedule(0);
        }
    });
    document.addEventListener('register:live-unlock', applyPendingPatches);
    window.addEventListener('offline', () => {
        window.clearTimeout(timer);
        document.dispatchEvent(new CustomEvent('register:live-unavailable'));
    });
    window.addEventListener('online', () => {
        refreshRequested = true;
        if (!inFlight) {
            schedule(0);
        }
    });

    schedule(1000);
})();
