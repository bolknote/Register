import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';

const draftKey = 'register_comment_draft:/all/auth-example';
const fixtureUrl = (origin, prefix = '') => origin + '/public-auth.html?' + new URLSearchParams({providers: 'all', prefix});

async function withPage(browser, run) {
    const page = await browser.newPage({reducedMotion: 'reduce'});
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    try {
        // These navigation fixtures return instantaneous mocked documents.
        await page.route('**/site.css', async route => {
            const response = await route.fetch();
            await route.fulfill({response, body: (await response.text()) + '\n@view-transition { navigation: none; }'});
        });
        await run(page);
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
}

async function storageRegressions(browser, origin) {
    for (const full of ['sessionStorage', 'localStorage']) {
        await withPage(browser, async page => {
            // Exercise both the new snapshots and the existing form-storage listener.
            // Its beforeunload warning is expected when localStorage is full.
            page.on('dialog', dialog => dialog.accept());
            await page.route('**/public-auth.html?*', async route => {
                const response = await route.fetch();
                await route.fulfill({response, body: (await response.text()).replace(
                    '<script src="/_assets/register/public-auth.js"',
                    '<script src="/comment-flow.js" defer></script><script src="/_assets/register/public-auth.js"',
                )});
            });
            await page.goto(fixtureUrl(origin));
            await page.locator('#comment-text').fill('Old short draft.');
            await page.evaluate(kind => {
                // Fill the real browser quota, rather than replacing its storage API.
                for (const size of [65536, 1024]) {
                    let index = 0;
                    try { for (;;) window[kind].setItem(`quota-${size}-${index++}`, 'x'.repeat(size)); }
                    catch (error) { if (error.name !== 'QuotaExceededError') throw error; }
                }
            }, full);
            const latest = 'Latest valid comment. '.repeat(200);
            await page.locator('#comment-text').fill(latest);
            const copies = await page.evaluate(key => Object.fromEntries(['localStorage', 'sessionStorage']
                .map(kind => [kind, JSON.parse(window[kind].getItem(key)).text])), draftKey);
            assert.equal(copies[full], 'Old short draft.', 'The failed write really leaves an older snapshot');
            assert.equal(copies[full === 'sessionStorage' ? 'localStorage' : 'sessionStorage'], latest);
            for (let reload = 0; reload < 2; reload++) {
                await page.reload();
                assert.equal(await page.locator('#comment-text').inputValue(), latest, `${full}: reload must retain the newest complete draft`);
            }
        });
    }
    await withPage(browser, async page => {
        await page.addInitScript(() => { Date.now = () => 1000; });
        await page.goto(fixtureUrl(origin));
        await page.evaluate(key => localStorage.setItem(key, JSON.stringify({
            id: '1', text: 'Legacy draft.', name: 'Original name', email: 'reader@example.test',
            parent: '12', number: '7', replyName: 'Reply recipient', subscribed: true,
        })), draftKey);
        for (let reload = 0; reload < 2; reload++) {
            await page.reload();
            assert.equal(await page.locator('#comment-text').inputValue(), 'Legacy draft.');
            assert.equal(await page.locator('#comment-name').inputValue(), 'Original name');
            assert.equal(await page.locator('#comment-email').inputValue(), 'reader@example.test');
            assert.equal(await page.locator('.comment-parent-id').inputValue(), '12');
            assert.equal(await page.locator('#subscribed').isChecked(), true);
        }
        await page.evaluate(key => {
            const data = JSON.parse(sessionStorage.getItem(key));
            sessionStorage.setItem(key, JSON.stringify({...data, savedAt: 2000000}));
        }, draftKey);
        await page.locator('#comment-text').fill('An edit after the clock moved backwards.');
        await page.locator('#comment-text').fill('A second edit in the same millisecond.');
        assert.ok(await page.evaluate(key => JSON.parse(localStorage.getItem(key)).savedAt > 2000001, draftKey));
        await page.reload();
        assert.equal(await page.locator('#comment-text').inputValue(), 'A second edit in the same millisecond.');
    });
}

async function queryRouteRegressions(browser, origin) {
    for (const prefix of ['/index.php?', '/?']) {
        await withPage(browser, async page => {
            const url = fixtureUrl(origin, prefix);
            const traffic = [];
            page.on('request', request => { if (request.isNavigationRequest() || request.method() === 'POST') traffic.push(request.method() + ' ' + request.url()); });
            await page.goto(url);
            await page.locator('#auth-open').click();
            const dialog = page.locator('#public-auth-dialog');
            for (const form of await dialog.locator('.public-auth-provider-form').all()) {
                const action = new URL(await form.getAttribute('action'), origin);
                assert.ok((action.pathname + action.search).startsWith(prefix + '/auth/oauth/'), 'Opening the dialog preserves the provider route');
                assert.equal(action.searchParams.get('return'), prefix + '/all/auth-example&view=full#comment-draft');
            }
            for (const link of await dialog.locator('[data-public-auth-mode-open]').all()) {
                assert.ok((await link.getAttribute('href')).startsWith(prefix + '/auth&'), 'Method links retain their raw routing segment');
            }
            let providerPost;
            await page.route(requestUrl => requestUrl.pathname + requestUrl.search === prefix + '/auth/oauth/yandex&return=' + encodeURIComponent(prefix + '/all/auth-example&view=full#comment-draft'), async route => {
                providerPost = {method: route.request().method(), body: route.request().postData()};
                await route.fulfill({contentType: 'text/html', body: 'Provider accepted'});
            });
            await dialog.locator('.public-auth-provider-yandex').click();
            await page.waitForFunction(() => document.body.textContent === 'Provider accepted');
            assert.equal(providerPost.method, 'POST');
            assert.ok(providerPost.body.includes('auth-fixture-token'));

            await page.goto(url);
            await page.locator('#comment-text').fill('A comment after signing in on a query-based site.');
            await page.locator('.comment-submit').click();
            const target = await dialog.locator('[name="return_path"]').first().inputValue();
            assert.ok(target.startsWith(prefix + '/all/auth-example&comment_send='));
            assert.ok(target.endsWith('#add-comment'));

            const html = await (await page.request.get(url)).text();
            const routePath = requestUrl => requestUrl.pathname + requestUrl.search;
            let commentPost;
            await page.route(requestUrl => routePath(requestUrl).startsWith(prefix + '/auth/password')
                || routePath(requestUrl).startsWith(prefix + '/all/auth-example'), async route => {
                const request = route.request();
                if (request.method() === 'GET') {
                    const authenticated = html.replace(/<div class="comment-identity"[^>]*>[\s\S]*?<\/div>/u,
                        '<div class="comment-authenticated-identity" hidden></div>');
                    await route.fulfill({contentType: 'text/html', body: authenticated});
                } else if (routePath(new URL(request.url())).startsWith(prefix + '/auth/password')) {
                    await route.fulfill({json: {success: true, redirect: target}});
                } else {
                    commentPost = new URLSearchParams(request.postData());
                    await route.fulfill({contentType: 'text/html', body: 'Comment sent'});
                }
            });
            await dialog.getByRole('link', {name: 'Логин и пароль', exact: true}).click();
            await page.waitForFunction(() => document.activeElement?.name === 'login');
            await dialog.locator('[name="login"]').fill('member');
            await dialog.locator('[name="pass"]').fill('fixture-password');
            await dialog.locator('.public-auth-password-form button[type="submit"]').click();
            await page.waitForFunction(() => document.body.textContent === 'Comment sent').catch(async error => {
                throw new Error(JSON.stringify({prefix, traffic, state: await page.evaluate(() => ({
                    url: location.href, intent: sessionStorage.getItem('register_comment_send'),
                    source: document.querySelector('#comment-text')?.value,
                    authenticated: !!document.querySelector('.comment-authenticated-identity'),
                    status: document.querySelector('.public-auth-status')?.textContent,
                    authAction: document.querySelector('.public-auth-password-form')?.action,
                    login: document.querySelector('[name="login"]')?.value,
                    passLength: document.querySelector('[name="pass"]')?.value.length,
                    mode: document.querySelector('.public-auth-body')?.dataset.publicAuthMode,
                    flow: document.querySelector('.public-auth-body')?.dataset.commentFlow,
                    native: document.querySelector('#comment-form')?.dataset.commentNativeSubmit,
                }))}), {cause: error});
            });
            assert.equal(commentPost.get('text'), 'A comment after signing in on a query-based site.');
            assert.equal(new URL(page.url()).pathname + new URL(page.url()).search, prefix + '/all/auth-example');

            await page.goto(origin + prefix + '/all/auth-example&keep=%2Bvalue&comment_send=unmatched#add-comment');
            await page.waitForFunction(() => !new URL(location.href).searchParams.has('comment_send'));
            assert.equal(new URL(page.url()).pathname + new URL(page.url()).search, prefix + '/all/auth-example&keep=%2Bvalue',
                'Consuming an unmatched marker preserves both the route and unrelated query spelling');
        });
    }
}

async function dialogFallbackRegressions(browser, origin) {
    for (const failure of ['no-api', 'no-constructor', 'missing-dialog', 'removed-later', 'throws']) {
        await withPage(browser, async page => {
            if (failure === 'no-api') await page.addInitScript(() => { HTMLDialogElement.prototype.showModal = undefined; });
            if (failure === 'no-constructor') await page.addInitScript(() => { window.HTMLDialogElement = undefined; });
            if (failure === 'throws') await page.addInitScript(() => { HTMLDialogElement.prototype.showModal = () => { throw new Error('Unavailable'); }; });
            if (failure === 'missing-dialog') await page.route('**/public-auth.html?*', async route => {
                const response = await route.fetch();
                await route.fulfill({response, body: (await response.text()).replace(/<dialog\b[\s\S]*?<\/dialog>/u, '')});
            });
            await page.goto(fixtureUrl(origin));
            await page.locator('#comment-text').fill('A native comment with the complete identity.');
            if (failure === 'removed-later') await page.locator('#public-auth-dialog').evaluate(dialog => dialog.remove());
            if (failure === 'removed-later' || failure === 'throws') {
                await page.locator('.comment-submit').click();
            }
            assert.equal(await page.locator('#comment-name').isVisible(), true, failure + ': name remains available');
            assert.equal(await page.locator('#comment-email').isEnabled(), true);
            await page.locator('#comment-name').fill('Guest');
            await page.locator('#comment-email').fill('guest@example.test');
            let posted;
            await page.route('**/all/auth-example', async route => {
                posted = new URLSearchParams(route.request().postData());
                await route.fulfill({contentType: 'text/html', body: 'Native comment accepted'});
            });
            await page.locator('.comment-submit').click();
            await page.waitForFunction(() => document.body.textContent === 'Native comment accepted');
            assert.equal(posted.get('text'), 'A native comment with the complete identity.');
            assert.equal(posted.get('name'), 'Guest');
            assert.equal(posted.get('email'), 'guest@example.test');
        });
    }
}

export async function runCommentSubmissionRegressions(browser, origin) {
    await storageRegressions(browser, origin);
    await queryRouteRegressions(browser, origin);
    await dialogFallbackRegressions(browser, origin);
    console.log(`${browser.browserType().name()}: newest drafts survive quota failures and clock changes; query routes, auto-send and native dialog fallbacks work`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit].filter(engine => !process.env.EDITOR_TEST_BROWSER
            || engine.name() === process.env.EDITOR_TEST_BROWSER)) {
            const browser = await engine.launch();
            try { await runCommentSubmissionRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
