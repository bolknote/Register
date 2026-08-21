(() => {
    'use strict';

    const config = document.querySelector('meta[name="register-offline"]');
    if (!config || !('serviceWorker' in navigator) || !window.isSecureContext) {
        return;
    }

    const workerUrl = config.dataset.worker || '';
    const scopeUrl = config.dataset.scope || '';
    const warningMessage = config.dataset.warning || '';
    const syncingMessage = config.dataset.syncing || warningMessage;
    const reloadLabel = config.dataset.reload || '';
    const allowsInitialSeed = config.dataset.seed === '1';
    if (workerUrl === '' || scopeUrl === '' || warningMessage === '') {
        return;
    }

    let banner = null;
    let message = null;
    let reloadButton = null;
    let stale = navigationUsedOfflineCache() || !navigator.onLine;

    function navigationUsedOfflineCache() {
        const navigation = performance.getEntriesByType('navigation')[0];
        return Array.isArray(navigation?.serverTiming)
            && navigation.serverTiming.some((entry) => entry.name === 'register-offline');
    }

    function ensureBanner() {
        if (banner) {
            return banner;
        }

        banner = document.createElement('div');
        banner.className = 'register-offline-warning';
        banner.setAttribute('role', 'status');
        banner.setAttribute('aria-live', 'polite');

        message = document.createElement('span');
        message.className = 'register-offline-warning-message';
        banner.append(message);

        reloadButton = document.createElement('button');
        reloadButton.className = 'register-offline-warning-reload';
        reloadButton.type = 'button';
        reloadButton.textContent = reloadLabel;
        reloadButton.addEventListener('click', () => window.location.reload());
        banner.append(reloadButton);

        document.body.insertBefore(banner, document.body.firstChild);
        return banner;
    }

    function showWarning(text = warningMessage) {
        stale = true;
        ensureBanner().hidden = false;
        message.textContent = text;
        reloadButton.hidden = !navigator.onLine || reloadLabel === '';
        document.documentElement.classList.add('register-offline-stale');
    }

    function hideWarning() {
        stale = false;
        if (banner) {
            banner.hidden = true;
        }
        document.documentElement.classList.remove('register-offline-stale');
    }

    function requestSynchronization() {
        if (!stale || !navigator.onLine) {
            return;
        }
        if (document.querySelector('meta[name="register-live-updates"]')) {
            showWarning(syncingMessage);
            document.dispatchEvent(new CustomEvent('register:live-refresh'));
        } else {
            showWarning(warningMessage);
        }
    }

    function cacheableAssetPath(url) {
        const scope = new URL(scopeUrl, document.baseURI);
        if (url.origin !== scope.origin || !url.pathname.startsWith(scope.pathname)) {
            return false;
        }
        const path = `/${url.pathname.slice(scope.pathname.length).replace(/^\/+/, '')}`;
        return [
            '/_assets/',
            '/_cache/',
            '/_extensions/',
            '/_pictures/',
            '/_styles/',
        ].some((prefix) => path.startsWith(prefix))
            || path === '/favicon.ico'
            || path === '/site.webmanifest';
    }

    function collectLoadedAssets() {
        const urls = new Set();
        const add = (value) => {
            if (typeof value !== 'string' || value === '') {
                return;
            }
            try {
                const url = new URL(value, document.baseURI);
                if (cacheableAssetPath(url)) {
                    url.hash = '';
                    urls.add(url.href);
                }
            } catch (_error) {
                // Invalid historical resource URLs stay outside the offline cache.
            }
        };

        for (const entry of performance.getEntriesByType('resource')) {
            add(entry.name);
        }
        document.querySelectorAll('link[href], script[src], img[src], source[src], audio[src], video[src], video[poster]')
            .forEach((element) => {
                add(element.currentSrc);
                add(element.getAttribute('href'));
                add(element.getAttribute('src'));
                add(element.getAttribute('poster'));
            });

        return Array.from(urls).slice(0, 256);
    }

    function currentPageAllowsStorage() {
        return document.querySelector('meta[name="register-offline"]')?.dataset.seed === '1';
    }

    function serializePage(fragment) {
        const root = document.documentElement.cloneNode(true);
        root.classList.remove('register-offline-stale');
        root.removeAttribute('data-register-navigation');
        root.querySelectorAll('.register-offline-warning').forEach((element) => element.remove());
        root.querySelectorAll([
            '[data-register-audio-player-script]',
            '[data-register-audio-player-styles]',
            '[data-register-math-script]',
            '[data-register-math-styles]',
            '[data-register-syntax-highlighting-script]',
            '[data-register-syntax-highlighting-styles]',
        ].join(',')).forEach((element) => element.remove());
        const body = root.querySelector('body');
        body?.classList.remove('register-navigation-pending');
        const currentPage = root.querySelector('[data-register-page]');
        const template = document.createElement('template');
        template.innerHTML = fragment.trim();
        const cleanPage = template.content.firstElementChild;
        if (!currentPage || !(cleanPage instanceof HTMLElement) || !cleanPage.matches('[data-register-page]')) {
            return '';
        }
        currentPage.replaceWith(cleanPage);

        return '<!DOCTYPE html>\n' + root.outerHTML;
    }

    async function storePage(payload, securityHeaders = {}) {
        if (
            !currentPageAllowsStorage()
            || !navigator.onLine
            || typeof payload?.fragment !== 'string'
            || typeof securityHeaders['Content-Security-Policy'] !== 'string'
        ) {
            return false;
        }

        const html = serializePage(payload.fragment);
        if (html === '') {
            return false;
        }

        const registration = await navigator.serviceWorker.ready;
        const worker = navigator.serviceWorker.controller || registration.active;
        if (!worker) {
            return false;
        }

        worker.postMessage({
            type: 'register:offline-store-page',
            page: window.location.href,
            html,
            securityHeaders,
        });

        return true;
    }

    async function registerWorker() {
        const initiallyControlled = navigator.serviceWorker.controller !== null;
        const registration = await navigator.serviceWorker.register(workerUrl, {
            scope: scopeUrl,
            updateViaCache: 'none',
        });
        const activeRegistration = await navigator.serviceWorker.ready;
        if (initiallyControlled || !allowsInitialSeed) {
            return;
        }

        if (document.readyState !== 'complete') {
            await new Promise((resolve) => window.addEventListener('load', resolve, {once: true}));
        }

        const worker = navigator.serviceWorker.controller || activeRegistration.active || registration.active;
        worker?.postMessage({
            type: 'register:offline-seed-page',
            page: window.location.href,
            assets: collectLoadedAssets(),
        });
    }

    window.addEventListener('offline', () => showWarning());
    window.addEventListener('online', requestSynchronization);
    document.addEventListener('register:live-unavailable', () => {
        if (stale || !navigator.onLine) {
            showWarning();
        }
    });
    document.addEventListener('register:live-synchronized', () => {
        if (navigator.onLine) {
            hideWarning();
        }
    });

    if (stale) {
        showWarning();
    }
    window.RegisterOffline = Object.freeze({storePage});
    registerWorker().catch(() => {});
})();
