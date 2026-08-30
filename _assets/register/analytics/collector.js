(() => {
    'use strict';

    const config = document.querySelector('meta[name="register-analytics"]');
    const collectUrl = config?.dataset.collectUrl || '';
    if (collectUrl === '' || window.RegisterAnalytics !== undefined) {
        return;
    }

    const consentKey = 'register.analytics.consent.v1';
    const sessionKey = 'register.analytics.session.v1';
    const sessionTtl = 30 * 60 * 1000;
    const heartbeatMilliseconds = 30000;
    const identifierPattern = /^[a-f0-9]{32}$/;
    let enabled = false;
    let initialized = null;
    let heartbeat = null;
    let sessionId = '';
    let pageViewId = '';
    let pagePath = '/';
    let pageTitle = '';
    let pageReferrer = '';
    let pageUtm = {};
    let visibleSince = null;
    let activeMilliseconds = 0;
    let maximumScrollDepth = 0;
    let navigationReferrer = '';

    const storedConsent = () => {
        try {
            return localStorage.getItem(consentKey);
        } catch (_error) {
            return null;
        }
    };

    const trackingAllowed = () => storedConsent() !== 'denied';

    const randomIdentifier = () => {
        const bytes = new Uint8Array(16);
        if (globalThis.crypto?.getRandomValues) {
            globalThis.crypto.getRandomValues(bytes);
        } else {
            for (let index = 0; index < bytes.length; index += 1) {
                bytes[index] = Math.floor(Math.random() * 256);
            }
        }
        return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    };

    const readSession = () => {
        try {
            const value = JSON.parse(localStorage.getItem(sessionKey) || 'null');
            if (value
                && identifierPattern.test(value.id)
                && Number.isFinite(value.last)
                && value.last >= Date.now() - sessionTtl
                && value.last <= Date.now() + 5 * 60 * 1000
            ) {
                return value.id;
            }
        } catch (_error) {
            // Blocked or damaged storage simply starts a new anonymous session.
        }
        return randomIdentifier();
    };

    const touchSession = () => {
        try {
            localStorage.setItem(sessionKey, JSON.stringify({id: sessionId, last: Date.now()}));
        } catch (_error) {
            // Session persistence is an accuracy improvement, not a runtime requirement.
        }
    };

    const utm = () => {
        const query = new URLSearchParams(location.search);
        return {
            source: query.get('utm_source') || '',
            medium: query.get('utm_medium') || '',
            campaign: query.get('utm_campaign') || '',
        };
    };

    const baseEvent = (type) => ({
        id: randomIdentifier(),
        type,
        occurred_at: Date.now(),
        session_id: sessionId,
        pageview_id: pageViewId,
        path: pagePath,
        title: pageTitle,
        referrer: pageReferrer,
        utm: pageUtm,
    });

    const visitorIdentity = async () => {
        if (window.RegisterVisitorIdentity?.ensure) {
            return window.RegisterVisitorIdentity;
        }
        if (document.readyState !== 'complete') {
            await new Promise((resolve) => {
                document.addEventListener('DOMContentLoaded', resolve, {once: true});
            });
        }
        return window.RegisterVisitorIdentity?.ensure
            ? window.RegisterVisitorIdentity
            : null;
    };

    const deliver = async (events) => {
        if (!enabled || events.length === 0 || !trackingAllowed()) {
            return false;
        }

        const identity = await visitorIdentity();
        if (identity === null) {
            return false;
        }
        await identity.ensure();
        touchSession();

        const body = JSON.stringify({v: 1, events});
        const blob = new Blob([body], {type: 'application/json'});
        if (navigator.sendBeacon?.(collectUrl, blob)) {
            return true;
        }

        const response = await fetch(collectUrl, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body,
        });
        return response.ok;
    };

    const captureVisibleTime = () => {
        if (visibleSince === null) {
            return;
        }
        activeMilliseconds += Math.max(0, performance.now() - visibleSince);
        visibleSince = null;
    };

    const updateScrollDepth = () => {
        const documentHeight = Math.max(document.documentElement.scrollHeight, document.body?.scrollHeight || 0);
        const scrollable = Math.max(0, documentHeight - window.innerHeight);
        const depth = scrollable === 0
            ? 100
            : Math.min(100, Math.round((window.scrollY / scrollable) * 100));
        maximumScrollDepth = Math.max(maximumScrollDepth, depth);
    };

    const flushEngagement = () => {
        captureVisibleTime();
        updateScrollDepth();
        const milliseconds = Math.min(300000, Math.round(activeMilliseconds));
        const scrollDepth = maximumScrollDepth;
        activeMilliseconds = 0;
        maximumScrollDepth = 0;
        if (document.visibilityState === 'visible') {
            visibleSince = performance.now();
        }
        if (pageViewId === '' || (milliseconds < 1000 && scrollDepth === 0)) {
            return Promise.resolve(false);
        }

        const event = baseEvent('engagement');
        event.engagement_ms = milliseconds;
        event.scroll_depth = scrollDepth;
        return deliver([event]).catch(() => false);
    };

    const recordPageView = (referrer) => {
        sessionId = readSession();
        touchSession();
        pageViewId = randomIdentifier();
        pagePath = location.pathname || '/';
        pageTitle = document.title || '';
        pageReferrer = referrer || '';
        pageUtm = utm();
        activeMilliseconds = 0;
        maximumScrollDepth = 0;
        visibleSince = document.visibilityState === 'visible' ? performance.now() : null;
        return deliver([baseEvent('pageview')]).catch(() => false);
    };

    const start = () => {
        if (enabled || !trackingAllowed()) {
            return Promise.resolve(false);
        }
        enabled = true;
        const firstView = recordPageView(document.referrer);
        heartbeat = window.setInterval(() => {
            void flushEngagement();
        }, heartbeatMilliseconds);
        return firstView;
    };

    const initialize = () => {
        initialized ??= start();
        return initialized;
    };

    const track = async (name, properties = {}) => {
        if (typeof name !== 'string' || !/^[a-zA-Z0-9_.:-]{1,64}$/.test(name)) {
            throw new TypeError('Invalid analytics event name.');
        }
        await initialize();
        if (!enabled) {
            return false;
        }
        const event = baseEvent('event');
        event.name = name;
        event.properties = properties;
        return deliver([event]);
    };

    const setConsent = (granted) => {
        try {
            localStorage.setItem(consentKey, granted ? 'granted' : 'denied');
        } catch (_error) {
            // The in-memory state still applies to this page.
        }

        if (!granted) {
            enabled = false;
            if (heartbeat !== null) {
                window.clearInterval(heartbeat);
                heartbeat = null;
            }
            return Promise.resolve(false);
        }

        initialized = null;
        return initialize();
    };

    window.RegisterAnalytics = Object.freeze({
        enabled: () => enabled && trackingAllowed(),
        setConsent,
        track,
    });

    document.addEventListener('visibilitychange', () => {
        if (!enabled) {
            return;
        }
        if (document.visibilityState === 'hidden') {
            void flushEngagement();
        } else if (visibleSince === null) {
            visibleSince = performance.now();
        }
    });
    window.addEventListener('scroll', updateScrollDepth, {passive: true});
    window.addEventListener('pagehide', () => {
        if (enabled) {
            void flushEngagement();
        }
    });
    document.addEventListener('register:navigation-will-update', () => {
        navigationReferrer = location.href;
        if (enabled) {
            void flushEngagement();
        }
    });
    document.addEventListener('register:navigation-updated', () => {
        if (enabled) {
            void recordPageView(navigationReferrer);
        }
    });

    void initialize();
})();
