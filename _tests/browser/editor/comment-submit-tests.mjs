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

const richFixtureHtml = html => html
    .replace('id="content" data-register-page', 'id="register-page" data-register-page')
    .replace('<script src="/_assets/register/public-auth.js"',
        '<script src="/comment-flow.js" defer></script><script src="/comment-editor.js" defer></script><script src="/_assets/register/public-auth.js"')
    .replace('<label>Draft', '<div class="comment-actions"><button type="button" class="comment-reply" data-reply-comment="12" data-reply-number="7" data-reply-name="Recipient">Reply</button></div><label>Draft');

async function openRichFixture(page, origin) {
    const url = fixtureUrl(origin);
    await page.route('**/public-auth.html?*', async route => {
        const response = await route.fetch();
        await route.fulfill({response, body: richFixtureHtml(await response.text())});
    });
    await page.goto(url);
    return richFixtureHtml(await (await page.request.get(url)).text());
}

async function routeArticle(page, path, fragment) {
    const assets = await page.locator('link[rel~="stylesheet"][href], script[src]')
        .evaluateAll(nodes => nodes.map(node => node.href || node.src));
    await page.route(url => url.pathname === path, route => route.fulfill({
        contentType: 'application/vnd.register.page+json',
        json: {version: 1, title: 'Article', lang: 'ru', bodyClass: '', head: '', assets, fragment},
    }));
}

async function richDraftRegressions(browser, origin) {
    await withPage(browser, async page => {
        await openRichFixture(page, origin);
        const form = page.locator('#comment-form');
        const editor = form.locator('.comment-editor-surface');
        const linkButton = form.locator('[data-comment-command="link"]');
        const linkInput = form.locator('[data-comment-link-input]');
        const apply = form.locator('[data-comment-link-apply]');
        const assertStored = async () => {
            const html = await form.locator('.comment-editor-source').inputValue();
            for (const kind of ['localStorage', 'sessionStorage']) {
                assert.equal(await page.evaluate(({kind, key}) => JSON.parse(window[kind].getItem(key)).text,
                    {kind, key: draftKey}), html, 'Toolbar edits must save without another keystroke');
            }
            await page.reload();
            assert.equal(await form.locator('.comment-editor-source').inputValue(), html, 'Reload retains the complete edited HTML');
        };
        await editor.fill('Draft with a link.');
        await linkButton.click();
        await linkInput.fill('https://example.test/first');
        await apply.click();
        assert.equal(await editor.locator('a').getAttribute('href'), 'https://example.test/first');
        await assertStored();
        await editor.locator('a').click();
        await linkButton.click();
        assert.equal(await linkInput.inputValue(), 'https://example.test/first');
        await linkInput.fill('https://example.test/changed?keep=1&other=2');
        await apply.click();
        assert.equal(await editor.locator('a').getAttribute('href'), 'https://example.test/changed?keep=1&other=2');
        await assertStored();
        await editor.locator('a').click();
        await linkButton.click();
        await form.locator('[data-comment-link-remove]').click();
        assert.equal(await editor.locator('a').count(), 0);
        await assertStored();
        for (const key of ['Backspace', 'Delete']) {
            await editor.fill('$$x+1$$');
            await editor.locator('.comment-editor-formula').waitFor();
            await editor.evaluate((surface, key) => {
                const formula = surface.querySelector('.comment-editor-formula');
                const range = document.createRange();
                if (key === 'Backspace') range.setStartAfter(formula);
                else range.setStartBefore(formula);
                range.collapse(true);
                surface.focus();
                getSelection().removeAllRanges();
                getSelection().addRange(range);
            }, key);
            await page.keyboard.press(key);
            assert.equal(await form.locator('.comment-editor-source').inputValue(), key === 'Backspace' ? '$$x+1$' : '$x+1$$');
            await assertStored();
        }
    });

    await withPage(browser, async page => {
        await openRichFixture(page, origin);
        const editor = page.locator('#comment-form .comment-editor-surface');
        await editor.fill('A reply kept through navigation.');
        await page.getByRole('button', {name: 'Reply', exact: true}).click();
        await page.locator('#subscribed').check();
        await page.locator('.comment-submit').click();
        const dialog = page.locator('#public-auth-dialog');
        await dialog.locator('[data-public-auth-mode-open="email"]').first().click();
        await dialog.locator('[name="comment_name"]').fill('Reply author');
        await dialog.locator('[name="comment_email"]').fill('author@example.test');
        await page.keyboard.press('Escape');
        const fields = () => page.locator('#comment-form').evaluate(form => ({
            text: form.querySelector('.comment-editor-source').value,
            name: form.querySelector('#comment-name').value, email: form.querySelector('#comment-email').value,
            parent: form.querySelector('.comment-parent-id').value, number: form.querySelector('.comment-reply-number').value,
            replyName: form.querySelector('.comment-reply-name').value, subscribed: form.querySelector('#subscribed').checked,
        }));
        const expected = await fields();
        assert.equal(expected.subscribed, true);
        assert.equal(expected.parent, '12');
        await routeArticle(page, '/another-article', '<main id="register-page" data-register-page><p>Other article</p></main>');
        for (let trip = 0; trip < 2; trip++) {
            await page.evaluate(() => RegisterNavigation.navigate('/another-article'));
            await page.goBack();
            await page.waitForFunction(() => document.documentElement.dataset.registerNavigation === 'history');
            await editor.waitFor({state: 'visible'});
            assert.deepEqual(await fields(), expected, 'History-cache restoration retains subscription, identity, text and addressee');
        }
    });

    await withPage(browser, async page => {
        const html = await openRichFixture(page, origin);
        const editor = page.locator('#comment-form .comment-editor-surface');
        await editor.fill('Old short draft.');
        await page.evaluate(() => {
            for (const size of [65536, 1024]) {
                let index = 0;
                try { for (;;) localStorage.setItem(`quota-${size}-${index++}`, 'x'.repeat(size)); }
                catch (error) { if (error.name !== 'QuotaExceededError') throw error; }
            }
        });
        const latest = 'Latest valid comment. '.repeat(200).trimEnd();
        await editor.fill(latest);
        assert.equal(await page.evaluate(key => JSON.parse(sessionStorage.getItem(key)).text, draftKey), latest);
        assert.equal(await page.evaluate(() => localStorage.getItem('comment_text_1')), 'Old short draft.');
        await routeArticle(page, '/another-article', '<main id="register-page" data-register-page><p>Other article</p></main>');
        await routeArticle(page, '/fresh-return', html.match(/<main\b[\s\S]*?<\/main>/u)[0]);
        await page.evaluate(() => RegisterNavigation.navigate('/another-article'));
        await page.evaluate(() => RegisterNavigation.navigate('/fresh-return'));
        assert.equal(await page.evaluate(() => document.documentElement.dataset.registerNavigation), 'partial');
        assert.equal(await page.locator('#comment-text').inputValue(), latest, 'Restore the newest snapshot before the legacy form-storage listener runs');
        assert.equal(await page.evaluate(key => JSON.parse(sessionStorage.getItem(key)).text, draftKey), latest,
            'Navigation must not replace the fresh snapshot with stale legacy text');
    });
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

async function deferredFormRegressions(browser, origin) {
    for (const prefix of ['', '/?', '/index.php?']) {
        await withPage(browser, async page => {
            const html = await (await page.request.get(fixtureUrl(origin, prefix))).text();
            const formHtml = html.match(/<section class="comment-form-block"[\s\S]*?<\/section>/u)?.[0];
            assert.ok(formHtml);
            await page.route('**/public-auth.html?*', async route => {
                const response = await route.fetch();
                const body = (await response.text())
                    .replace('</head>', '<script src="/comment-flow.js" defer></script></head>')
                    .replace('<label>Draft', '<button type="button" class="comment-reply" data-reply-comment="12" data-reply-number="7" data-reply-name="Recipient">Reply</button><label>Draft');
                await route.fulfill({response, body});
            });
            let requests = 0;
            await page.route(url => url.searchParams.get('comment_form') === '1', async route => {
                requests++;
                assert.equal(route.request().headers()['x-register-comment-form'], '1');
                await route.fulfill({contentType: 'text/html', headers: {'X-Register-Comment-Form': '1'}, body: formHtml});
            });
            await page.goto(fixtureUrl(origin, prefix) + '&deferred=1');
            await page.locator('#comment-form[data-comment-submit-ready]').waitFor();
            assert.equal(requests, 1, 'One private form request for each public snapshot');
            await page.locator('#comment-text').fill('A draft written into the separately loaded form.');
            await page.locator('.comment-reply').click();
            assert.equal(await page.locator('.comment-parent-id').inputValue(), '12', 'Existing reply buttons bind to the new form');
            assert.equal(await page.evaluate(key => JSON.parse(localStorage.getItem(key)).text,
                'register_comment_draft:' + prefix + '/all/auth-example'), 'A draft written into the separately loaded form.');
            await page.evaluate(() => {
                document.dispatchEvent(new CustomEvent('register:fragment-updated'));
                document.dispatchEvent(new CustomEvent('register:fragment-updated'));
            });
            assert.equal(requests, 1);
        });
    }

    await withPage(browser, async page => {
        const html = await (await page.request.get(fixtureUrl(origin))).text();
        const formHtml = html.match(/<section class="comment-form-block"[\s\S]*?<\/section>/u)[0];
        let requests = 0;
        await page.route('**/all/auth-example?comment_form=1', route => {
            requests++;
            return route.fulfill(requests === 1 ? {status: 503, body: 'Unavailable'}
                : {contentType: 'text/html', headers: {'X-Register-Comment-Form': '1'}, body: formHtml});
        });
        await page.goto(fixtureUrl(origin) + '&deferred=1');
        await page.locator('[data-comment-form-state="failed"]').waitFor();
        await page.evaluate(() => {
            for (let i = 0; i < 5; i++) document.dispatchEvent(new CustomEvent('register:fragment-updated'));
        });
        await page.waitForTimeout(150);
        assert.equal(requests, 1, 'A failed form load cannot create another fetch loop');
        await page.locator('[data-comment-form-load]').click();
        await page.locator('#comment-form[data-comment-submit-ready]').waitFor();
        assert.equal(requests, 2, 'The visible link can retry a failed load');
    });

    await withPage(browser, async page => {
        let release;
        const pending = new Promise(resolve => { release = resolve; });
        const html = await (await page.request.get(fixtureUrl(origin))).text();
        const formHtml = html.match(/<section class="comment-form-block"[\s\S]*?<\/section>/u)[0];
        await page.route('**/all/auth-example?comment_form=1', async route => {
            await pending;
            await route.fulfill({contentType: 'text/html', headers: {'X-Register-Comment-Form': '1'}, body: formHtml});
        });
        await page.goto(fixtureUrl(origin) + '&deferred=1');
        await page.locator('[data-comment-form-state="loading"]').waitFor();
        await page.locator('[data-comment-form-deferred]').evaluate(node => node.remove());
        release();
        await page.waitForTimeout(150);
        assert.equal(await page.locator('#comment-form').count(), 0, 'An old form cannot replace a newly navigated page');
    });

    await withPage(browser, async page => {
        const html = await (await page.request.get(fixtureUrl(origin))).text();
        const formHtml = html.match(/<section class="comment-form-block"[\s\S]*?<\/section>/u)[0];
        let requests = 0;
        await page.route('**/all/auth-example?comment_form=1', route => {
            requests++;
            return route.fulfill(requests === 1 ? {status: 503, body: 'Unavailable'}
                : {contentType: 'text/html', headers: {'X-Register-Comment-Form': '1'}, body: formHtml});
        });
        await page.goto(fixtureUrl(origin) + '&deferred=1');
        await page.locator('[data-comment-form-state="failed"]').waitFor();
        await page.evaluate(() => {
            // Partial-navigation history restores a DOM snapshot, including old state attributes.
            const old = document.querySelector('[data-comment-form-deferred]');
            const restored = old.cloneNode(true);
            restored.dataset.commentFormState = 'loading';
            restored.setAttribute('aria-busy', 'true');
            old.replaceWith(restored);
            document.dispatchEvent(new CustomEvent('register:fragment-updated', {detail: {root: document.body}}));
        });
        await page.locator('#comment-form[data-comment-submit-ready]').waitFor();
        assert.equal(requests, 2, 'A restored history snapshot can load a fresh private form');
    });

    const native = await browser.newPage({javaScriptEnabled: false});
    try {
        await native.route('**/site.css', async route => {
            const response = await route.fetch();
            await route.fulfill({response, body: (await response.text()) + '\n@view-transition { navigation: none; }'});
        });
        const html = await (await native.request.get(fixtureUrl(origin))).text();
        await native.route('**/all/auth-example?comment_form=1', route => route.fulfill({contentType: 'text/html', body: html}));
        await native.goto(fixtureUrl(origin) + '&deferred=1');
        assert.equal(await native.locator('#comment-form').count(), 0);
        await native.locator('[data-comment-form-load]').click();
        await native.locator('#comment-text').fill('A native comment.');
        await native.locator('#comment-name').fill('Guest');
        await native.locator('#comment-email').fill('guest@example.test');
        let posted;
        await native.route('**/all/auth-example', async route => {
            posted = new URLSearchParams(route.request().postData());
            await route.fulfill({contentType: 'text/html', body: 'Native comment accepted'});
        });
        await Promise.all([native.waitForURL('**/all/auth-example'), native.locator('.comment-submit').press('Enter')]);
        assert.equal(posted.get('text'), 'A native comment.');
    } finally { await native.close(); }
}

export async function runCommentSubmissionRegressions(browser, origin) {
    await deferredFormRegressions(browser, origin);
    await richDraftRegressions(browser, origin);
    await storageRegressions(browser, origin);
    await queryRouteRegressions(browser, origin);
    await dialogFallbackRegressions(browser, origin);
    console.log(`${browser.browserType().name()}: link edits, history and partial navigation retain complete drafts; quota recovery, query routes, auto-send and native dialog fallbacks work`);
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
