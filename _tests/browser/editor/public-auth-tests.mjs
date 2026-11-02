import assert from 'node:assert/strict';
import {mkdir} from 'node:fs/promises';
import {join} from 'node:path';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';

const returnPath = '/all/auth-example?view=full#comment-draft';
const draft = 'A comment drafted before signing in.';
const active = locator => locator.evaluate(element => element === document.activeElement);

async function assertLayout(dialog) {
    await dialog.evaluate(element => Promise.all(element.getAnimations({subtree: true}).map(animation => animation.finished.catch(() => {}))));
    const layout = await dialog.evaluate(element => {
        const rect = node => node.getBoundingClientRect().toJSON();
        const methods = Array.from(element.querySelectorAll('.public-auth-method')).filter(node => node.getClientRects().length);
        return {
            dialog: rect(element), viewport: innerWidth,
            methods: methods.map(node => ({rect: rect(node), label: node.textContent.trim()})),
            close: rect(element.querySelector('[data-public-auth-close]')),
            rootFont: parseFloat(getComputedStyle(document.documentElement).fontSize),
            overflow: element.scrollWidth > element.clientWidth + 1,
        };
    });
    assert.ok(layout.dialog.left >= 0 && layout.dialog.right <= layout.viewport, 'The account window fits the viewport');
    assert.ok(layout.dialog.width <= 27 * layout.rootFont + 1, `The account window keeps the compact design width: ${JSON.stringify(layout)}`);
    assert.equal(layout.overflow, false, 'No horizontal scrolling inside the window');
    assert.ok(layout.close.width >= 44 && layout.close.height >= 44, 'Closing is reachable by touch');
    for (const [index, method] of layout.methods.entries()) {
        assert.ok(method.rect.height >= 44 && method.rect.width > 180, `${method.label}: a readable full-width row`);
        if (index) {
            const previous = layout.methods[index - 1].rect;
            assert.ok(method.rect.top >= previous.bottom + 3, 'Methods form one vertical list');
            assert.ok(Math.abs(method.rect.left - previous.left) < 1 && Math.abs(method.rect.width - previous.width) < 1,
                'All methods use the same left edge and width');
        }
    }
}

export async function runPublicAuthRegressions(browser, origin) {
    const cases = [
        {width: 1280, colorScheme: 'dark', providers: 'yandex', email: '1'},
        {width: 320, colorScheme: 'light', providers: 'all', email: '1', hasTouch: true},
        {width: 390, colorScheme: 'dark', providers: 'yandex', email: '0', hasTouch: true},
        {width: 1280, colorScheme: 'light', providers: 'none', email: '1', reducedMotion: 'reduce'},
        {width: 320, colorScheme: 'dark', providers: 'none', email: '0'},
    ];
    for (const test of cases) {
        const context = await browser.newContext({viewport: {width: test.width, height: 820},
            colorScheme: test.colorScheme, hasTouch: test.hasTouch || false, reducedMotion: test.reducedMotion || 'no-preference'});
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        const query = new URLSearchParams({providers: test.providers, email: test.email});
        try {
            await page.goto(`${origin}/public-auth.html?${query}`);
            const dialog = page.locator('#public-auth-dialog');
            const open = page.locator('#auth-open');
            const methods = dialog.locator('[data-public-auth-mode-panel="methods"]');
            const emailForm = dialog.locator('.public-auth-email-form');
            const passwordForm = dialog.locator('.public-auth-password-form');
            const back = dialog.getByRole('link', {name: 'К способам входа', exact: true});
            const passwordOnly = test.providers === 'none' && test.email === '0';
            await open.click();
            await dialog.waitFor({state: 'visible'});
            await assertLayout(dialog);
            if (process.env.AUTH_TEST_SCREENSHOTS) {
                await mkdir(process.env.AUTH_TEST_SCREENSHOTS, {recursive: true});
                await dialog.screenshot({path: join(process.env.AUTH_TEST_SCREENSHOTS, `methods-${browser.browserType().name()}-${test.width}-${test.providers}.png`)});
            }
            if (passwordOnly) {
                assert.equal(await methods.count(), 0, 'One available method opens directly');
                assert.equal(await passwordForm.isVisible(), true);
                assert.equal(await back.count(), 0);
            } else {
                assert.equal(await emailForm.isVisible(), false, 'Email opens after choosing its method');
                assert.equal(await passwordForm.isVisible(), false, 'Password opens after choosing its method');
                const expected = 1 + Number(test.email === '1') + (test.providers === 'all' ? 4 : test.providers === 'yandex' ? 1 : 0);
                assert.equal(await methods.locator('.public-auth-method').count(), expected, 'Only configured methods appear');
                if (test.email === '1') {
                    await dialog.getByRole('link', {name: 'Ссылка на почту', exact: true}).click();
                    const email = emailForm.getByRole('textbox', {name: 'Электронная почта', exact: true});
                    await page.waitForFunction(() => document.activeElement?.name === 'email');
                    assert.equal(await emailForm.getByRole('button', {name: 'Получить ссылку', exact: true}).isEnabled(), true,
                        'The main action remains legible and allows validation of an empty address');
                    await emailForm.getByRole('button', {name: 'Получить ссылку', exact: true}).click();
                    assert.equal(await emailForm.locator('[data-public-auth-email-error]').isVisible(), true);
                    await email.fill('reader@example.test');
                    await back.click();
                    await page.waitForFunction(() => document.activeElement?.dataset.publicAuthModeOpen === 'email');
                    assert.equal(await methods.isVisible(), true);
                    await dialog.getByRole('link', {name: 'Ссылка на почту', exact: true}).press('Enter');
                    assert.equal(await email.inputValue(), 'reader@example.test', 'Returning to email retains the address');
                    await dialog.getByRole('link', {name: 'Войти с паролем', exact: true}).click();
                    assert.equal(await passwordForm.isVisible(), true, 'The secondary link opens the password form');
                    await dialog.getByRole('link', {name: 'Получить ссылку вместо пароля', exact: true}).click();
                    assert.equal(await email.inputValue(), 'reader@example.test', 'Switching forms retains the email address');
                    await back.click();
                }
                await dialog.getByRole('link', {name: 'Логин и пароль', exact: true}).click();
            }

            const closePosition = await dialog.locator('[data-public-auth-close]').boundingBox();
            const login = passwordForm.getByRole('textbox', {name: 'Логин', exact: true});
            await page.waitForFunction(() => document.activeElement?.name === 'login');
            await login.fill('test-user');
            await passwordForm.locator('[name="pass"]').fill('test-password');
            const remember = passwordForm.locator('[name="remember_me"]');
            assert.equal(await remember.isChecked(), true, 'The existing session choice is retained');
            await dialog.getByRole('button', {name: 'Показать пароль', exact: true}).click();
            assert.equal(await passwordForm.locator('[name="pass"]').getAttribute('type'), 'text');
            await dialog.getByRole('button', {name: 'Скрыть пароль', exact: true}).click();
            assert.equal(await passwordForm.locator('[name="pass"]').getAttribute('type'), 'password');
            if (!passwordOnly) {
                await back.click();
                const closedPosition = await dialog.locator('[data-public-auth-close]').boundingBox();
                assert.ok(Math.abs(closePosition.x - closedPosition.x) <= 0.5 && Math.abs(closePosition.y - closedPosition.y) <= 0.5,
                    'The close control does not jump between screens');
                await dialog.getByRole('link', {name: 'Логин и пароль', exact: true}).click();
                assert.equal(await login.inputValue(), 'test-user');
                assert.equal(await passwordForm.locator('[name="pass"]').inputValue(), 'test-password');
            }

            let submitted;
            await page.route('**/auth/password', async route => {
                submitted = route.request().postData();
                await route.fulfill({status: 401, contentType: 'application/json', body: JSON.stringify({success: false, message: 'Тестовая ошибка входа'})});
            });
            await passwordForm.locator('[name="pass"]').press('Enter');
            await dialog.locator('.public-auth-status.is-error').waitFor({state: 'visible'});
            assert.equal(await login.inputValue(), 'test-user', 'A failed login preserves input');
            for (const value of ['test-user', 'test-password', 'auth-fixture-token', returnPath]) assert.ok(submitted.includes(value),
                'The request retains credentials, CSRF token and the return destination');
            assert.equal(await active(login), true);
            await page.keyboard.press('Escape');
            await dialog.waitFor({state: 'hidden'});
            await page.waitForFunction(() => document.querySelector('#public-auth-dialog [name="pass"]').value === '');
            assert.equal(await active(open), true, 'Escape restores focus to the opener');
            assert.equal(await page.locator('#auth-draft').inputValue(), draft, 'Account navigation never changes the comment draft');

            await open.click();
            if (!passwordOnly && test.email === '1') {
                await dialog.getByRole('link', {name: 'Ссылка на почту', exact: true}).click();
                assert.equal(await emailForm.locator('[name="email"]').inputValue(), 'reader@example.test');
                let sent;
                await page.route('**/auth/email', async route => {
                    sent = route.request().postData();
                    await route.fulfill({status: 503, contentType: 'application/json', body: JSON.stringify({success: false, message: 'Тестовая ошибка отправки'})});
                });
                await emailForm.locator('[name="email"]').press('Enter');
                await dialog.locator('.public-auth-status.is-error').waitFor({state: 'visible'});
                assert.equal(await emailForm.locator('[name="email"]').inputValue(), 'reader@example.test');
                for (const value of ['reader@example.test', 'auth-fixture-token', returnPath]) assert.ok(sent.includes(value));
                assert.equal(await emailForm.getByRole('button', {name: 'Получить ссылку', exact: true}).isEnabled(), true);
            }
            if (process.env.AUTH_TEST_SCREENSHOTS) {
                await mkdir(process.env.AUTH_TEST_SCREENSHOTS, {recursive: true});
                await dialog.screenshot({path: join(process.env.AUTH_TEST_SCREENSHOTS, `${browser.browserType().name()}-${test.width}-${test.colorScheme}-${test.providers}.png`)});
            }
            assert.deepEqual(errors, []);
        } finally { await context.close(); }
    }

    const context = await browser.newContext();
    try {
        const page = await context.newPage();
        for (const [provider, label] of [['yandex', 'Яндекс'], ['vk', 'VK ID'], ['mail_ru', 'Mail.ru'], ['ok_ru', 'Одноклассники']]) {
            await page.goto(`${origin}/public-auth.html?providers=all`);
            await page.locator('#auth-open').click();
            await page.route(`**/auth/oauth/${provider}?*`, route => route.fulfill({contentType: 'text/html', body: '<p>Local authorization endpoint</p>'}), {times: 1});
            const request = page.waitForRequest(request => new URL(request.url()).pathname === `/auth/oauth/${provider}`);
            await page.locator('#public-auth-dialog').getByRole('button', {name: label, exact: true}).click();
            const actual = await request;
            assert.equal(actual.method(), 'POST', 'A provider starts directly with the existing protected POST');
            assert.equal(new URL(actual.url()).searchParams.get('return'), returnPath);
            assert.equal(new URLSearchParams(actual.postData()).get('auth_token'), 'auth-fixture-token');
        }
    } finally { await context.close(); }

    const native = await browser.newContext({javaScriptEnabled: false});
    try {
        // Match guest-comment E2E: test native forms independently of the site's
        // cross-document snapshot animation, whose timing blocks automated clicks.
        await native.route('**/site.css', async route => {
            const response = await route.fetch();
            await route.fulfill({response, body: await response.text() + '\n@view-transition { navigation: none; }'});
        });
        const page = await native.newPage();
        await page.goto(`${origin}/auth?providers=all`);
        const panel = page.locator('.public-auth-panel');
        await panel.getByRole('link', {name: 'Логин и пароль', exact: true}).click();
        await page.waitForURL(url => url.searchParams.get('mode') === 'password');
        assert.equal(await panel.locator('.public-auth-password-form').isVisible(), true);
        await panel.getByRole('link', {name: 'К способам входа', exact: true}).click();
        await page.waitForURL(url => !url.searchParams.has('mode'));
        await panel.getByRole('link', {name: 'Ссылка на почту', exact: true}).click();
        await page.waitForURL(url => url.searchParams.get('mode') === 'email');
        assert.equal(await panel.locator('.public-auth-email-form').isVisible(), true);
        assert.equal(await panel.locator('.public-auth-email-form [name="return_path"]').inputValue(), returnPath);
        await page.goto(`${origin}/auth?providers=none&email=0&mode=email`);
        assert.equal(await panel.locator('.public-auth-email-form').count(), 0);
        assert.equal(await panel.locator('.public-auth-password-form').isVisible(), true, 'A disabled method cannot be selected by URL');
    } finally { await native.close(); }
    console.log(`${browser.browserType().name()}: account method list, native forms, focus, retained input, errors, provider POST and no-JavaScript navigation`);
}

if (import.meta.url === pathToFileURL(process.argv[1] || '').href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit].filter(engine => !process.env.EDITOR_TEST_BROWSER || engine.name() === process.env.EDITOR_TEST_BROWSER)) {
            const browser = await engine.launch();
            try { await runPublicAuthRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
