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
    const idleMilliseconds = 30000;
    const identifierPattern = /^[a-f0-9]{32}$/;
    const downloadPattern = /\.([a-z0-9]{1,12})(?:$|[?#])/i;
    const downloadExtensions = new Set([
        '7z', 'avi', 'csv', 'doc', 'docx', 'epub', 'gz', 'm4a', 'mkv', 'mov', 'mp3', 'mp4',
        'ods', 'odt', 'pdf', 'ppt', 'pptx', 'rar', 'rtf', 'tar', 'wav', 'webm', 'xls', 'xlsx', 'zip',
    ]);
    let enabled = false;
    let initialized = null;
    let heartbeat = null;
    let sessionId = '';
    let pageViewId = '';
    let pagePath = '/';
    let pageTitle = '';
    let pageReferrer = '';
    let pageUtm = {};
    let pageProperties = {};
    let contentElement = null;
    let visibleSince = null;
    let lastActivityAt = 0;
    let activeMilliseconds = 0;
    let maximumScrollDepth = 0;
    let navigationReferrer = '';
    let vitalsSent = false;
    let largestContentfulPaint = null;
    let cumulativeLayoutShift = 0;
    let layoutShiftObserved = false;
    let interactionToNextPaint = null;

    const storedConsent = () => {
        try {
            return localStorage.getItem(consentKey);
        } catch (_error) {
            return null;
        }
    };

    // Browser privacy headers are intentionally not an analytics opt-out for this installation.
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

    const browserContext = () => {
        const userAgent = navigator.userAgent || '';
        const width = Number(globalThis.screen?.width) || Number(window.innerWidth) || 0;
        const languageSource = String(navigator.language || '').replace('_', '-');
        const languageMatch = languageSource.match(/^([a-z]{2,3})(?:-([a-z]{2}))?/i);
        const language = languageMatch === null
            ? ''
            : `${languageMatch[1].toLowerCase()}${languageMatch[2] ? `-${languageMatch[2].toUpperCase()}` : ''}`;

        let device = 'desktop';
        if (/iPad|Tablet|PlayBook|Silk/i.test(userAgent) || (width > 700 && width <= 1100 && navigator.maxTouchPoints > 1)) {
            device = 'tablet';
        } else if (/Android|iPhone|iPod|Mobile|IEMobile|Opera Mini/i.test(userAgent) || (width > 0 && width <= 700)) {
            device = 'mobile';
        }

        let browser = 'Other';
        if (/SamsungBrowser/i.test(userAgent)) {
            browser = 'Samsung Internet';
        } else if (/Edg\//i.test(userAgent)) {
            browser = 'Edge';
        } else if (/Firefox\//i.test(userAgent)) {
            browser = 'Firefox';
        } else if (/Chrome\//i.test(userAgent) || /CriOS\//i.test(userAgent)) {
            browser = 'Chrome';
        } else if (/Safari\//i.test(userAgent)) {
            browser = 'Safari';
        }

        let os = 'Other';
        if (/CrOS/i.test(userAgent)) {
            os = 'ChromeOS';
        } else if (/Android/i.test(userAgent)) {
            os = 'Android';
        } else if (/iPhone|iPad|iPod/i.test(userAgent)) {
            os = 'iOS';
        } else if (/Windows/i.test(userAgent)) {
            os = 'Windows';
        } else if (/Macintosh|Mac OS X/i.test(userAgent)) {
            os = 'macOS';
        } else if (/Linux/i.test(userAgent)) {
            os = 'Linux';
        }

        const screenClass = width >= 1600 ? 'wide' : (width >= 1024 ? 'large' : (width >= 600 ? 'medium' : 'small'));
        return {
            browser,
            device,
            os,
            screen: screenClass,
            ...(language === '' ? {} : {language}),
        };
    };

    const contentContext = () => {
        const explicit = document.querySelector('[data-analytics-content-type]');
        contentElement = explicit || document.querySelector('#content') || document.querySelector('main');
        const bodyClasses = document.body?.classList;
        const query = new URLSearchParams(location.search);
        let contentType = explicit?.dataset.analyticsContentType || '';
        if (contentType === '') {
            if (document.body?.dataset?.analyticsError !== undefined) {
                contentType = 'error';
            } else if (document.querySelector('form[role="search"]') && query.has('q')) {
                contentType = 'search';
            } else if (bodyClasses?.contains('blog')) {
                contentType = location.pathname === '/' ? 'blog-list' : 'archive';
            } else {
                contentType = location.pathname === '/' ? 'home' : 'page';
            }
        }

        const properties = {content_type: contentType};
        const contentId = explicit?.dataset.analyticsContentId || '';
        const author = explicit?.dataset.analyticsAuthor || '';
        const section = explicit?.dataset.analyticsSection || '';
        const publishedAt = Number(explicit?.dataset.analyticsPublishedAt || 0);
        const words = String(contentElement?.innerText || '').trim();
        const wordCount = words === '' ? 0 : words.split(/\s+/u).length;
        if (contentId !== '') properties.content_id = contentId;
        if (author !== '') properties.author = author;
        if (section !== '') properties.section = section;
        if (Number.isInteger(publishedAt) && publishedAt > 0) properties.published_at = publishedAt;
        if (wordCount > 0) properties.word_count = Math.min(200000, wordCount);
        return properties;
    };

    const refreshPageProperties = () => {
        pageProperties = {...browserContext(), ...contentContext()};
    };

    const baseEvent = (type) => {
        const event = {
            id: randomIdentifier(),
            type,
            occurred_at: Date.now(),
            session_id: sessionId,
            pageview_id: pageViewId,
            path: pagePath,
            title: pageTitle,
            referrer: pageReferrer,
            utm: pageUtm,
        };
        if (type === 'pageview' || type === 'engagement') {
            event.properties = pageProperties;
        }
        return event;
    };

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
        const now = performance.now();
        const activeUntil = Math.min(now, lastActivityAt + idleMilliseconds);
        activeMilliseconds += Math.max(0, activeUntil - visibleSince);
        visibleSince = null;
    };

    const updateScrollDepth = () => {
        if (contentElement?.getBoundingClientRect) {
            const rectangle = contentElement.getBoundingClientRect();
            const contentTop = window.scrollY + rectangle.top;
            const contentHeight = Math.max(1, rectangle.height, contentElement.scrollHeight || 0);
            const viewportBottom = window.scrollY + window.innerHeight;
            const depth = Math.min(100, Math.max(0, Math.round(((viewportBottom - contentTop) / contentHeight) * 100)));
            maximumScrollDepth = Math.max(maximumScrollDepth, depth);
            return;
        }

        const documentHeight = Math.max(document.documentElement.scrollHeight, document.body?.scrollHeight || 0);
        const scrollable = Math.max(0, documentHeight - window.innerHeight);
        const depth = scrollable === 0
            ? 100
            : Math.min(100, Math.round((window.scrollY / scrollable) * 100));
        maximumScrollDepth = Math.max(maximumScrollDepth, depth);
    };

    const resumeActivity = () => {
        if (!enabled || document.visibilityState !== 'visible') {
            return;
        }
        captureVisibleTime();
        lastActivityAt = performance.now();
        visibleSince = lastActivityAt;
        updateScrollDepth();
    };

    const flushEngagement = () => {
        captureVisibleTime();
        updateScrollDepth();
        const milliseconds = Math.min(300000, Math.round(activeMilliseconds));
        const scrollDepth = maximumScrollDepth;
        activeMilliseconds = 0;
        maximumScrollDepth = 0;
        const now = performance.now();
        if (document.visibilityState === 'visible' && now <= lastActivityAt + idleMilliseconds) {
            visibleSince = now;
        }
        if (pageViewId === '' || (milliseconds < 1000 && scrollDepth === 0)) {
            return Promise.resolve(false);
        }

        const event = baseEvent('engagement');
        event.engagement_ms = milliseconds;
        event.scroll_depth = scrollDepth;
        return deliver([event]).catch(() => false);
    };

    const navigationType = () => {
        const type = performance.getEntriesByType?.('navigation')?.[0]?.type;
        return ['back_forward', 'navigate', 'prerender', 'reload'].includes(type) ? type : 'other';
    };

    const flushVitals = () => {
        if (vitalsSent || pageViewId === '') {
            return Promise.resolve(false);
        }
        const properties = {nav_type: navigationType()};
        if (largestContentfulPaint !== null) properties.lcp_ms = Math.min(120000, Math.max(0, Math.round(largestContentfulPaint)));
        if (layoutShiftObserved) properties.cls_milli = Math.min(10000, Math.max(0, Math.round(cumulativeLayoutShift * 1000)));
        if (interactionToNextPaint !== null) properties.inp_ms = Math.min(60000, Math.max(0, Math.round(interactionToNextPaint)));
        if (properties.lcp_ms === undefined && properties.cls_milli === undefined && properties.inp_ms === undefined) {
            return Promise.resolve(false);
        }

        vitalsSent = true;
        const event = baseEvent('event');
        event.name = 'web_vitals';
        event.properties = properties;
        return deliver([event]).catch(() => false);
    };

    const observeVitals = () => {
        if (typeof PerformanceObserver === 'undefined') {
            return;
        }
        const observe = (type, callback, options = {}) => {
            const observer = new PerformanceObserver((list) => callback(list.getEntries()));
            try {
                observer.observe({type, buffered: true, ...options});
            } catch (_error) {
                if (Object.keys(options).length === 0) {
                    return;
                }
                try {
                    observer.observe({type, buffered: true});
                } catch (_fallbackError) {
                    // Older engines expose only a subset of the Web Performance APIs.
                }
            }
        };
        observe('largest-contentful-paint', (entries) => {
            const entry = entries.at(-1);
            if (entry) largestContentfulPaint = entry.startTime;
        });
        observe('layout-shift', (entries) => {
            layoutShiftObserved = layoutShiftObserved || entries.length > 0;
            entries.forEach((entry) => {
                if (!entry.hadRecentInput) cumulativeLayoutShift += entry.value;
            });
        });
        observe('event', (entries) => {
            entries.forEach((entry) => {
                if (entry.interactionId > 0) {
                    interactionToNextPaint = Math.max(interactionToNextPaint || 0, entry.duration);
                }
            });
        }, {durationThreshold: 40});
    };

    const recordPageView = (referrer) => {
        sessionId = readSession();
        touchSession();
        pageViewId = randomIdentifier();
        pagePath = location.pathname || '/';
        pageTitle = document.title || '';
        pageReferrer = referrer || '';
        pageUtm = utm();
        refreshPageProperties();
        vitalsSent = false;
        largestContentfulPaint = null;
        cumulativeLayoutShift = 0;
        layoutShiftObserved = false;
        interactionToNextPaint = null;
        activeMilliseconds = 0;
        maximumScrollDepth = 0;
        lastActivityAt = performance.now();
        visibleSince = document.visibilityState === 'visible' ? lastActivityAt : null;
        updateScrollDepth();
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
        if (properties === null || typeof properties !== 'object' || Array.isArray(properties)) {
            throw new TypeError('Analytics event properties must be an object.');
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

    const goal = (name, properties = {}) => {
        if (typeof name !== 'string' || !/^[a-zA-Z0-9_:-]{1,59}$/.test(name)) {
            throw new TypeError('Invalid analytics goal name.');
        }
        return track(`goal.${name}`, properties);
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

    const presence = () => {
        if (!enabled
            || !trackingAllowed()
            || !identifierPattern.test(pageViewId)
            || !identifierPattern.test(sessionId)
        ) {
            return null;
        }
        return {
            pageViewId,
            sessionId,
            path: pagePath.slice(0, 1024),
            title: pageTitle.slice(0, 160),
        };
    };

    window.RegisterAnalytics = Object.freeze({
        enabled: () => enabled && trackingAllowed(),
        goal,
        presence,
        setConsent,
        track,
    });

    document.addEventListener('visibilitychange', () => {
        if (!enabled) {
            return;
        }
        if (document.visibilityState === 'hidden') {
            void flushEngagement();
            void flushVitals();
        } else {
            resumeActivity();
        }
    });
    ['keydown', 'pointerdown', 'touchstart'].forEach((type) => {
        window.addEventListener(type, resumeActivity, {passive: true});
    });
    window.addEventListener('scroll', resumeActivity, {passive: true});
    window.addEventListener('pagehide', () => {
        if (enabled) {
            void flushEngagement();
            void flushVitals();
        }
    });
    document.addEventListener('click', (event) => {
        const anchor = event.target?.closest?.('a[href]');
        if (!anchor || !enabled) {
            return;
        }
        let url;
        try {
            url = new URL(anchor.href, location.href);
        } catch (_error) {
            return;
        }
        const extension = url.pathname.match(downloadPattern)?.[1]?.toLowerCase() || '';
        if (anchor.hasAttribute('download') || downloadExtensions.has(extension)) {
            void track('file.download', extension === '' ? {} : {extension}).catch(() => false);
        } else if (/^https?:$/.test(url.protocol) && url.host !== location.host) {
            void track('outbound.click', {host: url.hostname}).catch(() => false);
        }
    });
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!enabled || !form?.matches) {
            return;
        }
        if (form.matches('#comment-form')) {
            void track('comment.submit').catch(() => false);
        } else if (form.matches('[role="search"], .search-form, .register_search_form')) {
            void track('site.search').catch(() => false);
        }
    });
    document.addEventListener('register:navigation-will-update', () => {
        navigationReferrer = location.href;
        if (enabled) {
            void flushEngagement();
            void flushVitals();
        }
    });
    document.addEventListener('register:navigation-updated', () => {
        if (enabled) {
            void recordPageView(navigationReferrer);
        }
    });

    observeVitals();
    void initialize();
})();
