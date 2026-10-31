import assert from 'node:assert/strict';
import {chromium, firefox, webkit} from 'playwright';
import {pathToFileURL} from 'node:url';
import {createFixtureServer} from './server.mjs';

const bounds = page => page.evaluate(async () => {
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    const box = selector => document.querySelector(selector)?.getBoundingClientRect().toJSON();
    return {viewport: document.documentElement.getBoundingClientRect().width, scrollWidth: document.documentElement.scrollWidth,
        title: box('.site-title'), body: box('.post.body'), actions: box('.site-header-actions'),
        account: box('.public-auth-account'), search: box('.register-header-search'),
        input: box('#register_search_input'), plus: box('.post-create-start')};
});
const assertNoOverlap = (a, b, message) => assert.ok(!a || !b
    || a.right <= b.left + 0.5 || b.right <= a.left + 0.5
    || a.bottom <= b.top + 0.5 || b.bottom <= a.top + 0.5, message);

async function runLargeFontHeaderRegressions(browser, origin) {
    for (const hasTouch of [false, true]) {
        for (const font of [24, 32]) {
            const context = await browser.newContext({hasTouch, viewport: {width: 1280, height: 900}});
            const page = await context.newPage();
            page.setDefaultTimeout(10000);
            await page.route('**/site.css', async route => {
                const response = await route.fetch();
                await route.fulfill({response, body: (await response.text()) + `\nhtml { font-size: ${font}px; }`});
            });
            try {
                const paths = ['/header-unread.html', '/header-guest.html'];
                if (font === 32 && !hasTouch) paths.push('/header-oldschool.html', '/header-pixel-forest.html', '/header-system-1.html');
                for (const path of paths) {
                    await page.goto(origin + path);
                    const themed = !['/header-unread.html', '/header-guest.html'].includes(path);
                    for (const width of themed ? [320, 390, 1280] : [320, 390, 760, 761, 1120, 1280, 1920, 2300, 2560]) {
                        await page.setViewportSize({width, height: 900});
                        const closed = await bounds(page);
                        const label = `${browser.browserType().name()} ${font}px font, ${width}px viewport, ${hasTouch ? 'touch' : 'mouse'}, ${path}`;
                        assert.ok(themed ? closed.title.width > 0 : closed.title.width >= Math.min(18 * font, closed.body.width) - 1,
                            `${label}: the title keeps readable space or moves below the corner row: ${JSON.stringify(closed)}`);
                        assertNoOverlap(closed.actions, closed.title, label + ': closed controls do not cover the heading');
                        assertNoOverlap(closed.actions, closed.plus, label + ': the new-post control stays accessible');
                        const toggle = page.locator('[data-header-search] > summary');
                        if (hasTouch) await toggle.tap(); else await toggle.click();
                        await page.waitForFunction(() => document.activeElement?.id === 'register_search_input');
                        const opened = await bounds(page);
                        assert.ok(opened.scrollWidth <= (themed ? Math.max(width, closed.scrollWidth) : width),
                            label + ': opening search does not introduce horizontal overflow');
                        assertNoOverlap(opened.actions, opened.title, label + ': expanded search does not cover the heading');
                        assertNoOverlap(opened.actions, opened.plus, label + ': expanded search does not cover author tools');
                        assert.ok(opened.input.width >= 100, label + ': search retains room for text');
                        assert.ok(opened.account.left >= 0 && opened.account.right <= width,
                            label + ': the account stays entirely on screen');
                        assert.deepEqual(opened.body, closed.body, label + ': opening search never moves the article');
                        const input = page.locator('#register_search_input');
                        if (!themed) assert.equal(await input.evaluate(element => parseFloat(getComputedStyle(element).fontSize)), font,
                            label + ': search text preserves the larger font');
                        for (const control of await page.locator('.public-auth-login-button, .public-auth-unread, .public-auth-user-menu > summary, [data-header-search] > summary').all()) {
                            const box = await control.boundingBox();
                            assert.ok((themed || box.width >= 43.99 && box.height >= 43.99) && box.x >= 0 && box.x + box.width <= width,
                                `${label}: every corner control remains visible with a 44px hit area: ${JSON.stringify(box)}`);
                        }
                        await input.press('Escape');
                        if (path === '/header-unread.html') {
                            const account = page.locator('.public-auth-user-menu > summary');
                            if (hasTouch) await account.tap(); else await account.click();
                            assert.equal(await page.locator('.public-auth-menu-identity strong').innerText(), 'Тестовый автор');
                            if (hasTouch) await account.tap(); else await account.click();
                        }
                    }
                }
            } finally { await context.close(); }
        }
    }
    console.log(`${browser.browserType().name()}: large fonts retain a readable title, reachable account, stable search layout and touch targets`);
}

export async function runHeaderActionsRegressions(browser, origin) {
    for (const colorScheme of ['light', 'dark']) {
        const context = await browser.newContext({viewport: {width: 1280, height: 900}, colorScheme});
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        await page.route('**/search?title=*', route => route.fulfill({contentType: 'text/html',
            body: '<a href="/all/found">Найденная заметка</a>'}));
        try {
            for (const path of ['/responsive-layout.html', '/header-unread.html', '/header-guest.html', '/header-long-title.html']) {
                await page.goto(origin + path);
                const search = page.locator('[data-header-search]');
                const toggle = search.locator('summary');
                const input = search.locator('input[type="search"]');
                assert.equal(await page.locator('.public-auth-user-menu > summary .public-auth-user-name').count(), 0);
                for (const width of [320, 390, 760, 761, 1119, 1120, 1121, 1280, 1920, 3840]) {
                    await page.setViewportSize({width, height: 900});
                    const closed = await bounds(page);
                    assert.equal(await input.isVisible(), false, 'The field starts inside the closed magnifying-glass control');
                    assert.ok(Math.abs(closed.account.right - (closed.viewport - 12)) <= 1,
                        `The account stays in the right corner at ${width}px: ${JSON.stringify(closed)}`);
                    assert.ok(closed.search.right <= closed.account.left, 'Search stands to the left of the account');
                    assertNoOverlap(closed.actions, closed.title, 'Corner controls leave the title unobstructed');
                    assertNoOverlap(closed.actions, closed.plus, 'Search and account do not cover the author control');
                    assert.ok(closed.title.width <= closed.body.width + 1, 'The heading stays inside the reading column');
                    assert.ok(Math.abs(closed.title.left - closed.body.left) < 1, 'The heading keeps the content origin');
                    if (width >= 1120) {
                        assert.ok(closed.title.top <= closed.actions.top + 1,
                            `A wide header has no empty row above its title at ${width}px: ${JSON.stringify(closed)}`);
                    } else {
                        assert.ok(closed.title.top >= closed.actions.bottom, 'A narrow header leaves room for corner controls');
                    }
                    for (const control of await page.locator('.public-auth-login-button, .public-auth-unread, .public-auth-user-menu > summary, [data-header-search] > summary').all()) {
                        const size = await control.boundingBox();
                        assert.ok(size.width >= 43.99 && size.height >= 43.99,
                            `Corner controls keep a 44px touch target: ${await control.getAttribute('class')} at ${width}px: ${JSON.stringify(size)}`);
                    }

                    await toggle.click();
                    await page.waitForFunction(() => document.activeElement?.id === 'register_search_input');
                    const open = await bounds(page);
                    assert.equal(open.viewport, open.scrollWidth, 'Opening search cannot cause horizontal overflow');
                    assert.ok(open.input.width >= 100, `An open field remains usable at ${width}px`);
                    assert.ok(Math.abs(open.account.right - closed.account.right) < 0.5, 'The account does not move when search opens');
                    assertNoOverlap(open.actions, open.title, 'Expanded search leaves the title unobstructed');
                    assertNoOverlap(open.actions, open.plus, 'Expanded search leaves the author control accessible');
                    assert.deepEqual(open.body, closed.body, 'Opening search does not move or resize article content');
                    await input.fill('Сохранённый запрос');
                    await input.press('Escape');
                    assert.equal(await input.isVisible(), false, 'Escape collapses the field');
                    assert.equal(await toggle.evaluate(element => element === document.activeElement), true, 'Escape returns focus to the magnifier');
                    await toggle.click();
                    await page.waitForFunction(() => document.activeElement?.id === 'register_search_input');
                    assert.equal(await input.inputValue(), 'Сохранённый запрос', 'Closing search preserves its query');
                    await page.locator('.site-title').click();
                    assert.equal(await input.isVisible(), false, 'A click outside closes the field');
                }
            }

            await page.goto(origin + '/responsive-layout.html');
            const toggle = page.locator('[data-header-search] > summary');
            const input = page.locator('#register_search_input');
            await toggle.click();
            await input.pressSequentially('example');
            await page.locator('#register_search_tip a').waitFor({state: 'visible'});
            await input.press('Escape');
            assert.equal(await input.isVisible(), false, 'Escape also closes search while autocomplete is visible');
            await page.waitForFunction(() => document.activeElement?.matches('[data-header-search] > summary'));
            await toggle.click();
            await page.locator('.public-auth-user-menu > summary').click();
            assert.equal(await page.locator('.public-auth-menu-identity strong').innerText(), 'Тестовый автор');
            assert.equal(await page.locator('.public-auth-menu-item[type="submit"]').isVisible(), true, 'The account dropdown retains its actions');
            assert.equal(await input.isVisible(), false, 'The account and search do not open over one another');

            // Native form submission still works when autocomplete is disabled.
            await page.route('**/_assets/register/search/autocomplete.js', route => route.fulfill({contentType: 'text/javascript', body: ''}));
            await page.goto(origin + '/header-guest.html');
            await page.route('**/search?q=*', route => route.fulfill({contentType: 'text/html', body: '<h1>Search results</h1>'}));
            await page.locator('[data-header-search] > summary').click();
            await page.locator('#register_search_input').fill('проверка поиска');
            await Promise.all([page.waitForURL('**/search?q=*'), page.locator('#register_search_input').press('Enter')]);
            assert.equal(new URL(page.url()).searchParams.get('q'), 'проверка поиска');
            assert.deepEqual(errors, []);

            for (const theme of ['oldschool', 'pixel-forest', 'system-1']) {
                await page.goto(origin + `/header-${theme}.html`);
                for (const width of [390, 1280]) {
                    await page.setViewportSize({width, height: 900});
                    const closed = await bounds(page);
                    assertNoOverlap(closed.actions, closed.title, `${theme}: controls leave the title accessible: ${JSON.stringify(closed)}`);
                    assertNoOverlap(closed.actions, closed.plus, `${theme}: author tools remain accessible`);
                    await page.locator('[data-header-search] > summary').click();
                    await page.waitForFunction(() => document.activeElement?.id === 'register_search_input');
                    const open = await bounds(page);
                    assert.ok(open.scrollWidth <= open.viewport + 1, `${theme}: expanded search stays inside the page`);
                    assertNoOverlap(open.actions, open.title, `${theme}: expanded search leaves the title accessible`);
                    assertNoOverlap(open.actions, open.plus, `${theme}: expanded search leaves the author control accessible`);
                    await page.locator('#register_search_input').press('Escape');
                }
            }
        } finally { await context.close(); }
    }

    const noScript = await browser.newContext({javaScriptEnabled: false, viewport: {width: 390, height: 900}});
    try {
        const page = await noScript.newPage();
        await page.goto(origin + '/header-guest.html');
        assert.equal(await page.locator('#register_search_input').isVisible(), false);
        await page.locator('[data-header-search] > summary').click();
        assert.equal(await page.locator('#register_search_input').isVisible(), true, 'Native disclosure remains usable without JavaScript');
    } finally { await noScript.close(); }
    await runLargeFontHeaderRegressions(browser, origin);
    console.log(`${browser.browserType().name()}: corner account, expanding search, suggestions, keyboard, no-JS and 320–3840px layout passed`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit].filter(engine => !process.env.EDITOR_TEST_BROWSER || engine.name() === process.env.EDITOR_TEST_BROWSER)) {
            const browser = await engine.launch();
            try { await runHeaderActionsRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
