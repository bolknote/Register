(() => {
    'use strict';

    const pollInterval = 15000;
    const maximumRetryInterval = 60000;
    const pendingPatches = new Map();
    const locallyAppliedCursors = new Set();
    let endpoint = '';
    let cursor = 0;
    let regions = [];
    let timer = 0;
    let generation = 0;
    let inFlight = false;
    let retryCount = 0;
    let refreshRequested = false;
    let requestController = null;
    let enabled = false;

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
                '[data-live-lock], .comment-form-block, .is-editing, .is-confirming, [aria-busy="true"]',
            ) !== null;
    }

    function destroyWidgets(region) {
        if (window.RegisterReactions?.destroy) {
            window.RegisterReactions.destroy(region);
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
            [window.RegisterSearchAutocomplete, 'enhance'],
        ];
        for (const [api, method] of enhancers) {
            if (typeof api?.[method] !== 'function') {
                continue;
            }
            const result = api[method](region);
            if (result && typeof result.catch === 'function') {
                result.catch(() => {});
            }
        }

        document.dispatchEvent(new CustomEvent('register:fragment-updated', {
            detail: {root: region},
        }));
    }

    function localizeTimesBeforeInsertion(root) {
        if (typeof window.RegisterLocalTime?.enhance === 'function') {
            window.RegisterLocalTime.enhance(root);
        }
    }

    function normalizeCauses(value) {
        if (
            !Array.isArray(value)
            || value.length === 0
            || value.some((cause) => !Number.isSafeInteger(cause) || cause <= 0)
        ) {
            return null;
        }

        return Array.from(new Set(value));
    }

    function mergeCauses(previous, next) {
        if (!Array.isArray(previous) || !Array.isArray(next)) {
            return null;
        }

        return Array.from(new Set([...previous, ...next]));
    }

    function wasAppliedLocally(patch) {
        return Array.isArray(patch.causes)
            && patch.causes.length > 0
            && patch.causes.every((cause) => locallyAppliedCursors.has(cause));
    }

    function applyPatch(name, patch) {
        if (wasAppliedLocally(patch)) {
            return true;
        }

        const current = findRegion(name);
        if (!current || isLocked(current)) {
            return false;
        }

        const template = document.createElement('template');
        template.innerHTML = patch.html;
        const replacement = findRegion(name, template.content);
        if (!replacement) {
            return false;
        }

        localizeTimesBeforeInsertion(replacement);
        destroyWidgets(current);
        current.replaceWith(replacement);
        enhanceWidgets(replacement);

        return true;
    }

    function forgetConsumedLocalCursors() {
        const pendingCauses = new Set();
        for (const patch of pendingPatches.values()) {
            if (Array.isArray(patch.causes)) {
                patch.causes.forEach((cause) => pendingCauses.add(cause));
            }
        }
        for (const appliedCursor of locallyAppliedCursors) {
            if (appliedCursor <= cursor && !pendingCauses.has(appliedCursor)) {
                locallyAppliedCursors.delete(appliedCursor);
            }
        }
    }

    function applyPendingPatches() {
        for (const [name, patch] of pendingPatches) {
            if (applyPatch(name, patch)) {
                pendingPatches.delete(name);
            }
        }
        forgetConsumedLocalCursors();
    }

    function schedule(delay) {
        window.clearTimeout(timer);
        if (!enabled || document.hidden || !navigator.onLine) {
            return;
        }
        timer = window.setTimeout(poll, Math.max(0, delay));
    }

    function stop() {
        generation += 1;
        enabled = false;
        window.clearTimeout(timer);
        requestController?.abort();
        requestController = null;
        inFlight = false;
        retryCount = 0;
        refreshRequested = false;
        pendingPatches.clear();
        locallyAppliedCursors.clear();
    }

    function readConfiguration() {
        const config = document.querySelector('meta[name="register-live-updates"]');
        if (!config) {
            return null;
        }

        const configuredEndpoint = config.dataset.endpoint || '';
        const configuredCursor = Number.parseInt(config.dataset.cursor || '', 10);
        let configuredRegions;
        try {
            configuredRegions = JSON.parse(config.dataset.regions || '[]');
        } catch (_error) {
            return null;
        }
        if (
            configuredEndpoint === ''
            || !Number.isSafeInteger(configuredCursor)
            || configuredCursor < 0
            || !Array.isArray(configuredRegions)
            || configuredRegions.length === 0
            || configuredRegions.some((region) => typeof region !== 'string')
        ) {
            return null;
        }

        return {
            endpoint: configuredEndpoint,
            cursor: configuredCursor,
            regions: configuredRegions,
        };
    }

    function configure(delay = 0) {
        stop();
        const configuration = readConfiguration();
        if (!configuration) {
            return false;
        }

        endpoint = configuration.endpoint;
        cursor = configuration.cursor;
        regions = configuration.regions;
        enabled = true;
        schedule(delay);

        return true;
    }

    async function poll() {
        if (!enabled || inFlight || document.hidden) {
            return;
        }

        const currentGeneration = generation;
        inFlight = true;
        refreshRequested = false;
        applyPendingPatches();
        let nextDelay = pollInterval;
        const controller = new AbortController();
        requestController = controller;

        try {
            const url = new URL(endpoint, document.baseURI);
            url.searchParams.set('cursor', String(cursor));
            for (const region of regions) {
                url.searchParams.append('region[]', region);
            }
            try {
                const presence = window.RegisterAnalytics?.presence?.();
                if (presence !== null && typeof presence === 'object') {
                    url.searchParams.set('analytics_pageview_id', presence.pageViewId);
                    url.searchParams.set('analytics_session_id', presence.sessionId);
                    url.searchParams.set('analytics_path', presence.path);
                    url.searchParams.set('analytics_title', presence.title);
                }
            } catch (_error) {
                // Presence is optional; page synchronization must remain independent.
            }

            const response = await window.fetch(url, {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                cache: 'no-store',
                signal: controller.signal,
            });
            const payload = await response.json().catch(() => null);
            if (currentGeneration !== generation) {
                return;
            }
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
                    const causes = normalizeCauses(payload.causes?.[name]);
                    const previous = pendingPatches.get(name);
                    pendingPatches.set(name, {
                        html,
                        causes: previous ? mergeCauses(previous.causes, causes) : causes,
                    });
                }
            }
            applyPendingPatches();
            document.dispatchEvent(new CustomEvent('register:live-synchronized'));

            retryCount = 0;
            nextDelay = payload.more === true ? 0 : pollInterval + Math.floor(Math.random() * 1500);
        } catch (error) {
            if (error?.name !== 'AbortError' && currentGeneration === generation) {
                document.dispatchEvent(new CustomEvent('register:live-unavailable'));
                retryCount += 1;
                nextDelay = Math.min(maximumRetryInterval, pollInterval * (2 ** Math.min(retryCount, 2)))
                    + Math.floor(Math.random() * 2000);
            }
        } finally {
            if (currentGeneration === generation) {
                requestController = null;
                inFlight = false;
                schedule(refreshRequested ? 0 : nextDelay);
            }
        }
    }

    function acknowledge(appliedCursor) {
        if (!enabled || !Number.isSafeInteger(appliedCursor) || appliedCursor <= 0) {
            return false;
        }

        locallyAppliedCursors.add(appliedCursor);
        refreshRequested = true;
        applyPendingPatches();
        if (!inFlight) {
            schedule(0);
        }

        return true;
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
        if (!enabled && !configure(0)) {
            return;
        }
        refreshRequested = true;
        if (!inFlight) {
            schedule(0);
        }
    });
    document.addEventListener('register:live-unlock', applyPendingPatches);
    document.addEventListener('register:navigation-will-update', stop);
    document.addEventListener('register:navigation-updated', () => configure(0));
    window.addEventListener('offline', () => {
        window.clearTimeout(timer);
        requestController?.abort();
        document.dispatchEvent(new CustomEvent('register:live-unavailable'));
    });
    window.addEventListener('online', () => {
        if (!enabled && !configure(0)) {
            return;
        }
        refreshRequested = true;
        if (!inFlight) {
            schedule(0);
        }
    });

    window.RegisterLiveUpdates = Object.freeze({
        reconfigure: configure,
        acknowledge,
    });
    configure(1000);
})();
