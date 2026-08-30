import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const collectorSource = await readFile(
    new URL('../../_assets/register/analytics/collector.js', import.meta.url),
    'utf8'
);

test('collector waits for visitor identity and ignores browser privacy signals', async function () {
    const listeners = new Map();
    const beacons = [];
    const storage = new Map();
    let randomByte = 0;
    let identityCalls = 0;
    const document = {
        body: {scrollHeight: 1000},
        documentElement: {scrollHeight: 1000},
        readyState: 'interactive',
        referrer: 'https://referrer.test/article?secret=value',
        title: 'Example',
        visibilityState: 'visible',
        addEventListener(type, listener) {
            const registered = listeners.get(type) || [];
            registered.push(listener);
            listeners.set(type, registered);
        },
        querySelector(selector) {
            return selector === 'meta[name="register-analytics"]'
                ? {dataset: {collectUrl: '/_analytics/collect'}}
                : null;
        }
    };
    const location = {
        href: 'https://example.test/post?utm_source=newsletter',
        pathname: '/post',
        search: '?utm_source=newsletter'
    };
    const window = {
        RegisterAnalytics: undefined,
        RegisterVisitorIdentity: undefined,
        addEventListener() {},
        clearInterval() {},
        innerHeight: 800,
        location,
        scrollY: 0,
        setInterval() { return 1; }
    };
    const context = vm.createContext({
        Blob,
        URLSearchParams,
        console,
        crypto: {
            getRandomValues(bytes) {
                for (let index = 0; index < bytes.length; index += 1) {
                    bytes[index] = randomByte % 256;
                    randomByte += 1;
                }
                return bytes;
            }
        },
        document,
        fetch() {
            throw new Error('sendBeacon should handle this event.');
        },
        localStorage: {
            getItem(key) { return storage.get(key) ?? null; },
            setItem(key, value) { storage.set(key, value); }
        },
        location,
        navigator: {
            doNotTrack: '1',
            globalPrivacyControl: true,
            sendBeacon(url, body) {
                beacons.push({url, body});
                return true;
            }
        },
        performance,
        window
    });

    new vm.Script(collectorSource, {filename: 'collector.js'}).runInContext(context);
    assert.equal(beacons.length, 0, 'identity SDK is deliberately loaded after the collector');

    window.RegisterVisitorIdentity = {
        async ensure() {
            identityCalls += 1;
            return 'visitor-token';
        }
    };
    for (const listener of listeners.get('DOMContentLoaded') || []) {
        listener();
    }
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(identityCalls, 1);
    assert.equal(beacons.length, 1);
    assert.equal(beacons[0].url, '/_analytics/collect');
    const payload = JSON.parse(await beacons[0].body.text());
    assert.equal(payload.v, 1);
    assert.equal(payload.events.length, 1);
    assert.equal(payload.events[0].type, 'pageview');
    assert.equal(payload.events[0].path, '/post');
    assert.equal(payload.events[0].utm.source, 'newsletter');
    assert.equal(payload.events[0].properties.content_type, 'page');
    assert.equal(payload.events[0].properties.device, 'desktop');
    assert.equal(payload.events[0].properties.browser, 'Other');
    assert.equal(JSON.stringify(payload).includes('userAgent'), false);

    const presence = window.RegisterAnalytics.presence();
    assert.match(presence.pageViewId, /^[a-f0-9]{32}$/);
    assert.match(presence.sessionId, /^[a-f0-9]{32}$/);
    assert.equal(presence.path, '/post');
    assert.equal(presence.title, 'Example');
});
