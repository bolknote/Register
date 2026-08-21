'use strict';

const CACHE_NAME = 'register-offline-v1';
const OFFLINE_CACHE_HEADER = 'X-Register-Offline-Cache';
const OFFLINE_CACHE_HEADER_VALUE = 'public';
const OFFLINE_TIMING_NAME = 'register-offline';
const CACHEABLE_DESTINATIONS = new Set([
    'audio',
    'font',
    'image',
    'script',
    'style',
    'track',
    'video',
]);
const CACHEABLE_PREFIXES = [
    '/_assets/',
    '/_cache/',
    '/_extensions/',
    '/_pictures/',
    '/_styles/',
];
const CACHEABLE_ROOT_FILES = new Set([
    '/favicon.ico',
    '/site.webmanifest',
]);
const MAX_STORED_PAGE_LENGTH = 16 * 1024 * 1024;
const STORED_PAGE_SECURITY_HEADERS = new Set([
    'content-security-policy',
    'content-security-policy-report-only',
    'permissions-policy',
    'referrer-policy',
    'reporting-endpoints',
    'x-content-type-options',
]);

function pathInsideScope(url) {
    const scope = new URL(self.registration.scope);
    if (url.origin !== scope.origin || !url.pathname.startsWith(scope.pathname)) {
        return null;
    }

    return `/${url.pathname.slice(scope.pathname.length).replace(/^\/+/, '')}`;
}

function isCacheableAsset(request) {
    if (request.method !== 'GET' || !CACHEABLE_DESTINATIONS.has(request.destination)) {
        return false;
    }

    return isCacheableAssetUrl(new URL(request.url));
}

function isCacheableAssetUrl(url) {
    const path = pathInsideScope(url);
    return path !== null
        && (CACHEABLE_ROOT_FILES.has(path) || CACHEABLE_PREFIXES.some((prefix) => path.startsWith(prefix)));
}

function canStoreAsset(response) {
    return response.ok
        && !strContainsToken(response.headers.get('Cache-Control') || '', 'no-store');
}

function strContainsToken(value, token) {
    return value.toLowerCase().split(',').some((part) => part.trim() === token);
}

function canStoreNavigation(response) {
    return response.ok
        && response.headers.get(OFFLINE_CACHE_HEADER) === OFFLINE_CACHE_HEADER_VALUE;
}

function markOfflineResponse(response) {
    const headers = new Headers(response.headers);
    const serverTiming = headers.get('Server-Timing');
    headers.set(
        'Server-Timing',
        `${serverTiming ? `${serverTiming}, ` : ''}${OFFLINE_TIMING_NAME};desc="cache"`,
    );
    headers.set('X-Register-Offline-Fallback', '1');
    headers.delete('Content-Length');

    return new Response(response.body, {
        status: response.status,
        statusText: response.statusText,
        headers,
    });
}

async function navigationResponse(request) {
    const cache = await caches.open(CACHE_NAME);
    let response;

    try {
        response = await fetch(request);
    } catch (_error) {
        const cached = await cache.match(request);
        if (cached) {
            return markOfflineResponse(cached);
        }
        throw _error;
    }

    if (response.status >= 500) {
        const cached = await cache.match(request);
        if (cached) {
            return markOfflineResponse(cached);
        }
        return response;
    }

    try {
        if (canStoreNavigation(response)) {
            await cache.put(request, response.clone());
        } else {
            await cache.delete(request);
        }
    } catch (_error) {
        // A full cache must never prevent the live network response from opening.
    }

    return response;
}

async function assetResponse(request, event) {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(request);
    const update = fetch(request).then(async (response) => {
        if (canStoreAsset(response)) {
            try {
                await cache.put(request, response.clone());
            } catch (_error) {
                // The network asset is still usable when persistent storage is full.
            }
        }
        return response;
    });

    if (cached) {
        event.waitUntil(update.catch(() => {}));
        return cached;
    }

    return update;
}

async function seedPage(pageUrl, assetUrls) {
    const scope = new URL(self.registration.scope);
    const page = new URL(pageUrl, scope);
    if (pathInsideScope(page) === null) {
        return;
    }
    page.hash = '';

    const cache = await caches.open(CACHE_NAME);
    const pageRequest = new Request(page.href, {
        cache: 'force-cache',
        credentials: 'same-origin',
        headers: {'Accept': 'text/html'},
    });
    try {
        const response = await fetch(pageRequest);
        if (!canStoreNavigation(response)) {
            return;
        }
        await cache.put(pageRequest, response.clone());
    } catch (_error) {
        return;
    }

    const urls = Array.isArray(assetUrls) ? assetUrls.slice(0, 256) : [];
    for (let offset = 0; offset < urls.length; offset += 8) {
        const batch = urls.slice(offset, offset + 8).map(async (assetUrl) => {
            const url = new URL(assetUrl, scope);
            const request = new Request(url.href, {
                cache: 'force-cache',
                credentials: 'same-origin',
            });
            if (!isCacheableAssetUrl(url)) {
                return;
            }
            try {
                const response = await fetch(request);
                if (canStoreAsset(response)) {
                    await cache.put(request, response.clone());
                }
            } catch (_error) {
                // One unavailable illustration must not abort the rest of the page seed.
            }
        });
        await Promise.all(batch);
    }
}

async function storePage(pageUrl, html, securityHeaders) {
    if (typeof html !== 'string' || html.length === 0 || html.length > MAX_STORED_PAGE_LENGTH) {
        return;
    }

    const scope = new URL(self.registration.scope);
    const page = new URL(pageUrl, scope);
    if (pathInsideScope(page) === null) {
        return;
    }
    page.hash = '';

    const headers = new Headers({
        'Cache-Control': 'no-cache',
        'Content-Type': 'text/html; charset=UTF-8',
        [OFFLINE_CACHE_HEADER]: OFFLINE_CACHE_HEADER_VALUE,
    });
    let hasEnforcedPolicy = false;
    if (securityHeaders && typeof securityHeaders === 'object') {
        for (const [name, value] of Object.entries(securityHeaders)) {
            if (STORED_PAGE_SECURITY_HEADERS.has(name.toLowerCase()) && typeof value === 'string') {
                try {
                    headers.set(name, value);
                    if (name.toLowerCase() === 'content-security-policy') {
                        hasEnforcedPolicy = true;
                    }
                } catch (_error) {
                    // Ignore malformed metadata instead of losing the offline page.
                }
            }
        }
    }
    if (!hasEnforcedPolicy) {
        return;
    }

    const request = new Request(page.href, {
        credentials: 'same-origin',
        headers: {'Accept': 'text/html'},
    });
    const response = new Response(html, {status: 200, headers});
    const cache = await caches.open(CACHE_NAME);
    try {
        await cache.put(request, response);
    } catch (_error) {
        // A full private cache must not affect the currently open page.
    }
}

self.addEventListener('install', (event) => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(names
            .filter((name) => name.startsWith('register-offline-') && name !== CACHE_NAME)
            .map((name) => caches.delete(name)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }
    if (request.mode === 'navigate') {
        event.respondWith(navigationResponse(request));
        return;
    }
    if (isCacheableAsset(request)) {
        event.respondWith(assetResponse(request, event));
    }
});

self.addEventListener('message', (event) => {
    if (!event.data || typeof event.data.page !== 'string') {
        return;
    }
    if (event.data.type === 'register:offline-seed-page') {
        event.waitUntil(seedPage(event.data.page, event.data.assets));
    } else if (event.data.type === 'register:offline-store-page') {
        event.waitUntil(storePage(event.data.page, event.data.html, event.data.securityHeaders));
    }
});
