import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

export async function runNavigationRecoveryRegressions(browser, origin) {
    const fixture = await readFile(new URL('./recovery.html', import.meta.url), 'utf8');
    const navigation = await readFile(new URL('../../../_assets/register/partial-navigation.js', import.meta.url), 'utf8');
    const html = fixture.replace('<main id="content">',
        '<div id="register-page" data-register-page><a href="/history-b/">Next page</a><main id="content">')
        .replace('</main>', '</main></div>')
        .replace('</html>', '<script src="/partial-navigation.js"></script></html>');
    const context = await browser.newContext();
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    let payload;
    let delayedResponse;
    let delayedRequest;
    await page.route('**/partial-navigation.js', route => route.fulfill({contentType: 'text/javascript', body: navigation}));
    await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
    await page.route('**/history-*/', async route => {
        if (route.request().headers()['x-register-navigation'] !== 'partial') {
            return route.fulfill({contentType: 'text/html', body: html});
        }
        if (route.request().url().endsWith('/history-c/')) {
            delayedRequest();
            await new Promise(resolve => { delayedResponse = resolve; });
        }
        return route.fulfill({contentType: 'application/vnd.register.page+json', json: payload});
    });
    const failStorage = () => page.evaluate(() => {
        window.navigationStorageSetItem = Storage.prototype.setItem;
        Storage.prototype.setItem = function (key, value) {
            if (key.startsWith('register:post-recovery:')) throw new DOMException('Storage full', 'QuotaExceededError');
            return window.navigationStorageSetItem.call(this, key, value);
        };
    });
    const restoreStorage = () => page.evaluate(() => { Storage.prototype.setItem = window.navigationStorageSetItem; });
    const warning = () => page.locator('.is-editing .post-inplace-status').filter({hasText: 'Unable to save a local copy'}).waitFor();
    const blockedHistory = direction => page.evaluate(method => new Promise(resolve => {
        const path = location.pathname;
        const listener = () => {
            if (location.pathname === path) {
                window.removeEventListener('popstate', listener);
                resolve();
            }
        };
        window.addEventListener('popstate', listener);
        history[method]();
    }), direction);
    try {
        await page.goto(origin + '/history-a/');
        await page.waitForFunction(() => window.RegisterNavigation && window.editorTest);
        payload = await page.evaluate(() => {
            const root = document.querySelector('[data-register-page]');
            root.querySelectorAll('textarea,input').forEach(field => { field.defaultValue = field.value; });
            return {version: 1, title: 'History destination', lang: 'en', bodyClass: '', head: '', fragment: root.outerHTML,
                assets: [...document.querySelectorAll('link[rel~="stylesheet"][href],script[src]')]
                    .map(element => element.getAttribute(element.matches('script') ? 'src' : 'href'))};
        });
        await page.getByRole('link', {name: 'Next page'}).click();
        await page.waitForFunction(() => document.title === 'History destination');
        const historyLength = await page.evaluate(() => history.length);
        const key = await page.evaluate(() => history.state.registerNavigationKey);
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        let body = page.locator('.is-editing [data-post-inplace-body]');
        await body.fill('Earlier saved draft');
        await page.waitForFunction(() => RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1).list()
            .some(record => record.snapshot.body.includes('Earlier saved draft')));
        await failStorage();
        await body.fill('Latest text before Back');
        await warning();
        await blockedHistory('back');
        assert.equal(await body.textContent(), 'Latest text before Back');
        assert.equal(await page.evaluate(() => history.state.registerNavigationKey), key);
        assert.equal(await page.evaluate(() => history.length), historyLength);

        await restoreStorage();
        await page.goBack();
        await page.waitForURL(origin + '/history-a/');
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        await page.getByRole('button', {name: 'Restore text', exact: true}).click();
        body = page.locator('.is-editing [data-post-inplace-body]');
        assert.equal(await body.textContent(), 'Latest text before Back', 'Successful navigation must persist the latest copy');
        await failStorage();
        await body.fill('Latest text before Forward');
        await warning();
        await blockedHistory('forward');
        assert.equal(await body.textContent(), 'Latest text before Forward');
        assert.equal(await page.evaluate(() => history.length), historyLength);

        await restoreStorage();
        const requested = new Promise(resolve => { delayedRequest = resolve; });
        const navigating = page.evaluate(() => RegisterNavigation.navigate('/history-c/'));
        await requested;
        await failStorage();
        await body.fill('Changed while navigation fetched');
        delayedResponse();
        await navigating;
        assert.equal(new URL(page.url()).pathname, '/history-a/');
        assert.equal(await body.textContent(), 'Changed while navigation fetched');
        await warning();
        await restoreStorage();
        await page.goForward();
        await page.waitForURL(origin + '/history-b/');
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        await page.getByRole('button', {name: 'Restore text', exact: true}).click();
        assert.equal(await page.locator('.is-editing [data-post-inplace-body]').textContent(), 'Changed while navigation fetched');
        await page.goBack();
        await page.waitForURL(origin + '/history-a/');
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        await page.evaluate(() => { window.RegisterPostRecovery = null; });
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        body = page.locator('.is-editing [data-post-inplace-body]');
        await body.fill('Draft without a recovery service');
        await blockedHistory('forward');
        assert.equal(await body.textContent(), 'Draft without a recovery service');
        await warning();
        assert.deepEqual(errors, []);
        console.log(`${browser.browserType().name()}: Back/Forward and delayed navigation preserve drafts when storage fails`);
    } finally {
        delayedResponse?.();
        await context.close();
    }
}
