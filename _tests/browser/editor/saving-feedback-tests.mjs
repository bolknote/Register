import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {formData, holdRequests} from './save-tests.mjs';

function saved(data, creating) {
    return {success: true, action: creating ? 'create' : 'edit', revision: 2, title: data.get('title'),
        body_html: `<div class="post body" data-post-inplace-body>${data.get('body')}</div>`,
        published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
        tags: [], scheduled: false, message: 'Saved',
        ...(creating ? {id: 10, url: '/created', action_url: '/_inplace/post/10', token: 'fixture'} : {})};
}

export async function runSavingFeedbackRegressions(browser, origin) {
    for (const width of [1280, 390]) {
        for (const creating of [false, true]) {
            const page = await browser.newPage({viewport: {width, height: 800}, reducedMotion: 'reduce'});
            page.setDefaultTimeout(10000);
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            try {
                await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
                const requests = holdRequests(page, '**/_inplace/post/*');
                await requests.installed;
                await page.goto(origin + '/recovery.html');
                await page.getByRole('button', {name: creating ? 'New post' : 'Edit', exact: true}).click();
                const card = page.locator('.post-card').filter({has: page.locator(creating ? '#form-new' : '#form-9')});
                const body = card.locator('[data-post-inplace-body]');
                await card.locator('[data-post-inplace-title]').fill('A saved post');
                await body.fill('Retain this exact text.');
                const longPost = width === 1280 && !creating;
                if (longPost) await body.evaluate(body => {
                    for (let i = 0; i < 45; i++) {
                        const paragraph = document.createElement('p');
                        paragraph.textContent = 'An ordinary paragraph that makes this post long enough to scroll.';
                        body.append(paragraph);
                    }
                    body.dispatchEvent(new InputEvent('input', {bubbles: true, inputType: 'insertText'}));
                });
                const expectedText = await body.textContent();
                const tools = card.locator(':scope > .post-inplace-tools');
                const before = await tools.boundingBox();
                await card.getByRole('button', {name: 'Save', exact: true}).click();
                const initial = await requests.next();
                const firstData = await formData(initial);
                await page.waitForFunction(() => document.querySelector('[data-post-save-state="saving"]'));
                assert.equal(await tools.locator('.post-save-label').textContent(), 'Saving…');
                assert.equal(await tools.locator('.post-save-spinner').isVisible(), true);
                assert.equal(await card.locator('.post-edit-save').isVisible(), false, 'Saving replaces the tools instead of dimming their icons');
                assert.equal(await card.evaluate(card => card.inert), true);
                assert.equal(await page.locator('.post-save-announcement').textContent(), 'Saving…');
                assert.equal(await page.locator('.post-save-announcement').evaluate(node => node.closest('[inert]') === null), true,
                    'Saving is announced outside the inert editor');
                if (longPost) {
                    await card.evaluate(card => scrollTo(0, card.getBoundingClientRect().bottom + scrollY - 320));
                    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
                }
                const pending = await tools.boundingBox();
                assert.ok(Math.abs(before.x - pending.x) < 1 && Math.abs(before.width - pending.width) < 1,
                    'Showing progress retains the tool rail geometry');
                const indicator = await tools.locator('.post-save-feedback').boundingBox();
                assert.ok(indicator.x >= 0 && indicator.x + indicator.width <= width, 'The progress caption fits on screen');
                assert.ok(indicator.y >= 0 && indicator.y + indicator.height <= 800, 'The complete progress indicator stays visible while scrolling');
                await page.keyboard.press('Control+s');
                assert.equal(requests.count, 0, 'A repeated save shortcut cannot submit again during saving');
                let success = initial;
                let data = firstData;
                if (!creating) {
                    await initial.fulfill({status: 503, json: {success: false, message: 'Temporary save failure'}});
                    await page.waitForFunction(() => !document.querySelector('.post-card[inert]'));
                    assert.equal(await tools.getAttribute('data-post-save-state'), null);
                    assert.equal(await card.locator('.post-edit-save').isVisible(), true);
                    assert.equal(await body.textContent(), expectedText);
                    assert.equal(await card.locator('.post-inplace-edit-error').textContent(), 'Temporary save failure');
                    assert.equal(await page.locator('.post-save-announcement').textContent(), '');
                    await card.getByRole('button', {name: 'Save', exact: true}).click();
                    success = await requests.next(); data = await formData(success);
                    assert.equal(data.get('body'), firstData.get('body'));
                }
                await success.fulfill({json: saved(data, creating)});
                await page.waitForFunction(() => document.querySelector('[data-post-save-state="saved"]'));
                assert.equal(await tools.locator('.post-save-label').textContent(), 'Saved');
                assert.equal(await tools.locator('.post-save-spinner').isVisible(), false);
                assert.equal(await tools.locator('.post-save-check').isVisible(), true);
                const savedCard = page.locator(`.post-card[data-post-id="${creating ? 10 : 9}"]`);
                assert.equal(await savedCard.locator('[data-post-inplace-body]').textContent(), expectedText);
                assert.equal(await savedCard.evaluate(card => card.inert), false);
                assert.equal(await savedCard.locator('.post-inplace-status').isVisible(), false,
                    'A saved confirmation appears only once, in the tool rail');
                await page.waitForFunction(() => !document.querySelector('[data-post-save-state]'));
                assert.equal(await savedCard.getByRole('button', {name: 'Edit', exact: true}).isVisible(), true);
                assert.equal(await page.locator('.post-save-announcement').textContent(), '');
                assert.deepEqual(errors, []);
            } finally { await page.close(); }
        }
    }

    for (const fault of ['timeout', 'validation', 'invalid-response']) {
        const page = await browser.newPage({reducedMotion: 'reduce'});
        page.setDefaultTimeout(10000);
        try {
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            const requests = holdRequests(page, '**/_inplace/post/9');
            await requests.installed;
            await page.goto(origin + '/recovery.html' + (fault === 'timeout' ? '?timeout=150' : ''));
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            const card = page.locator('.post-card.is-editing');
            await card.locator('[data-post-inplace-body]').fill('A recoverable draft');
            if (fault === 'validation') await card.locator('[data-post-inplace-title]').fill('');
            await card.getByRole('button', {name: 'Save', exact: true}).click();
            if (fault === 'timeout') {
                const first = await requests.next();
                const retry = await requests.next();
                await page.waitForFunction(() => !document.querySelector('.post-card[inert]'));
                await first.abort('failed').catch(() => {}); await retry.abort('failed').catch(() => {});
            } else if (fault === 'invalid-response') {
                await (await requests.next()).fulfill({json: {success: true, action: 'edit'}});
            }
            await page.waitForFunction(() => !document.querySelector('.post-card[inert]'));
            assert.equal(await card.locator('[data-post-inplace-body]').textContent(), 'A recoverable draft');
            assert.equal(await card.locator('[data-post-save-state]').count(), 0, 'Failure never leaves a spinner or claims success');
            assert.equal(await card.getByRole('button', {name: 'Save', exact: true}).isVisible(), true);
            assert.equal(await page.locator('.post-save-announcement').textContent(), '');
            if (fault === 'validation') assert.equal(requests.count, 0);
        } finally { await page.close(); }
    }
    console.log(`${browser.browserType().name()}: save feedback replaces tools, announces progress, confirms only applied saves and restores exact drafts after failures`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const [name, type] of Object.entries({chromium, firefox, webkit})) {
            if (process.env.EDITOR_TEST_BROWSER && process.env.EDITOR_TEST_BROWSER !== name) continue;
            const browser = await type.launch();
            try { await runSavingFeedbackRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
