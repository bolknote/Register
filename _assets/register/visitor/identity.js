(() => {
    'use strict';

    const config = document.querySelector('meta[name="register-visitor"]');
    if (!(config instanceof HTMLMetaElement)) {
        return;
    }

    const cookieName = config.dataset.cookie || '';
    const cookiePath = config.dataset.cookiePath || '/';
    const resolveUrl = config.dataset.resolveUrl || '';
    const fingerprintSrc = config.dataset.fingerprintSrc || '';
    const tokenPattern = /^[a-f0-9]{32}\.[a-f0-9]{64}$/;
    const storageKey = 'register.visitor.token.v1';
    const fingerprintAtKey = 'register.visitor.fingerprint-at.v1';
    const databaseName = 'register-visitor-v1';
    const storeName = 'identity';
    const fingerprintRefresh = 30 * 24 * 60 * 60 * 1000;
    let pendingResolution = null;
    let fingerprintAgent = null;

    const validToken = (value) => typeof value === 'string' && tokenPattern.test(value);

    const readCookie = () => {
        const prefix = `${encodeURIComponent(cookieName)}=`;
        for (const part of document.cookie.split(';')) {
            const item = part.trim();
            if (item.startsWith(prefix)) {
                const value = decodeURIComponent(item.slice(prefix.length));
                return validToken(value) ? value : null;
            }
        }
        return null;
    };

    const writeCookie = (token) => {
        const secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = `${encodeURIComponent(cookieName)}=${encodeURIComponent(token)}; Path=${cookiePath}; Max-Age=31536000; SameSite=Lax${secure}`;
    };

    const readLocal = () => {
        try {
            const token = localStorage.getItem(storageKey);
            return validToken(token) ? token : null;
        } catch (_error) {
            return null;
        }
    };

    const writeLocal = (token) => {
        try {
            localStorage.setItem(storageKey, token);
        } catch (_error) {
            // A blocked or full storage area must not break reactions.
        }
    };

    const openDatabase = () => new Promise((resolve, reject) => {
        if (!('indexedDB' in window)) {
            reject(new Error('IndexedDB is unavailable.'));
            return;
        }

        const request = indexedDB.open(databaseName, 1);
        request.onupgradeneeded = () => {
            if (!request.result.objectStoreNames.contains(storeName)) {
                request.result.createObjectStore(storeName);
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error || new Error('Unable to open IndexedDB.'));
    });

    const readIndexed = async () => {
        let database;
        try {
            database = await openDatabase();
            return await new Promise((resolve) => {
                const request = database.transaction(storeName, 'readonly').objectStore(storeName).get('token');
                request.onsuccess = () => resolve(validToken(request.result) ? request.result : null);
                request.onerror = () => resolve(null);
            });
        } catch (_error) {
            return null;
        } finally {
            database?.close();
        }
    };

    const writeIndexed = async (token) => {
        let database;
        try {
            database = await openDatabase();
            await new Promise((resolve) => {
                const transaction = database.transaction(storeName, 'readwrite');
                transaction.objectStore(storeName).put(token, 'token');
                transaction.oncomplete = () => resolve();
                transaction.onerror = () => resolve();
                transaction.onabort = () => resolve();
            });
        } catch (_error) {
            // IndexedDB is an extra recovery layer, not a runtime requirement.
        } finally {
            database?.close();
        }
    };

    const remember = (token) => {
        writeCookie(token);
        writeLocal(token);
        void writeIndexed(token);
    };

    const loadFingerprintAgent = async () => {
        if (fingerprintAgent) {
            return fingerprintAgent;
        }

        fingerprintAgent = (async () => {
            if (!window.FingerprintJS) {
                await new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = fingerprintSrc;
                    script.async = true;
                    script.onload = resolve;
                    script.onerror = () => reject(new Error('Unable to load the fingerprint library.'));
                    document.head.append(script);
                });
            }

            if (!window.FingerprintJS || typeof window.FingerprintJS.load !== 'function') {
                throw new Error('FingerprintJS did not initialize.');
            }

            return window.FingerprintJS.load({monitoring: false});
        })();

        return fingerprintAgent;
    };

    const fingerprint = async () => {
        try {
            const agent = await loadFingerprintAgent();
            const result = await agent.get();
            return typeof result.visitorId === 'string' ? result.visitorId : null;
        } catch (_error) {
            return null;
        }
    };

    const resolveOnServer = async (token, trackPage, includeFingerprint = true) => {
        const browserFingerprint = includeFingerprint ? await fingerprint() : null;
        const response = await fetch(resolveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                token,
                fingerprint: browserFingerprint,
                trackPage,
            }),
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || payload.success !== true || !validToken(payload.token)) {
            throw new Error(payload?.message || 'Unable to resolve the visitor identity.');
        }

        remember(payload.token);
        if (browserFingerprint !== null) {
            try {
                localStorage.setItem(fingerprintAtKey, String(Date.now()));
            } catch (_error) {
                // The timestamp is only an optimization.
            }
        }

        window.dispatchEvent(new CustomEvent('register:visitor-ready', {
            detail: {token: payload.token, source: payload.source || 'unknown'},
        }));

        return payload.token;
    };

    const needsFingerprintRefresh = () => {
        try {
            const lastRun = Number(localStorage.getItem(fingerprintAtKey) || 0);
            return !Number.isFinite(lastRun) || Date.now() - lastRun >= fingerprintRefresh;
        } catch (_error) {
            return true;
        }
    };

    const refreshFingerprintLater = (token) => {
        if (!needsFingerprintRefresh()) {
            return;
        }

        const refresh = () => {
            void resolveOnServer(token, false, true).catch(() => {});
        };
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(refresh, {timeout: 5000});
        } else {
            window.setTimeout(refresh, 1500);
        }
    };

    const ensure = async ({trackPage = false, force = false} = {}) => {
        const cookieToken = readCookie();
        if (cookieToken !== null && !force) {
            writeLocal(cookieToken);
            void writeIndexed(cookieToken);
            refreshFingerprintLater(cookieToken);
            return cookieToken;
        }

        if (pendingResolution !== null) {
            return pendingResolution;
        }

        pendingResolution = (async () => {
            const storedToken = cookieToken || readLocal() || await readIndexed();
            return resolveOnServer(storedToken, trackPage, true);
        })();

        try {
            return await pendingResolution;
        } finally {
            pendingResolution = null;
        }
    };

    window.RegisterVisitorIdentity = Object.freeze({
        ensure,
        refresh: () => ensure({force: true}),
        token: readCookie,
    });

    const tracksThisPage = document.querySelector('meta[name="register-analytics-page"]') !== null;
    if (tracksThisPage) {
        void ensure({trackPage: true, force: true}).catch(() => {});
    } else {
        void ensure().catch(() => {});
    }
})();
