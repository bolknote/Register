import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {formData, holdRequests} from './save-tests.mjs';

const images = '<p>Opening</p>\n<img src="/first.png" alt="First description">\n<p>Middle</p>\n<img src="/second.png" alt="Second description">';
const value = page => page.evaluate(() => window.adminEditor.getValue());

async function placeCaret(page, line, ch = 5) {
    await page.evaluate(({line, ch}) => {
        const cm = document.querySelector('.CodeMirror').CodeMirror;
        cm.setCursor({line, ch});
        cm.focus();
    }, {line, ch});
}

async function withPage(browser, origin, run, search = false) {
    const page = await browser.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    try {
        if (search) {
            // Load the same search/dialog addons before initialization as in production.
            for (const name of ['dialog.js', 'searchcursor.js', 'search.js', 'dialog.css']) {
                const body = await readFile(new URL(`../../../_admin/lib/codemirror/${name}`, import.meta.url), 'utf8');
                await page.route(`**/shortcut-${name}`, route => route.fulfill({contentType: name.endsWith('.css') ? 'text/css' : 'text/javascript', body}));
            }
            await page.route('**/admin.html?*', async route => {
                const response = await route.fetch();
                await route.fulfill({response, body: (await response.text()).replace('<script type="module"',
                    '<link rel="stylesheet" href="/shortcut-dialog.css">'
                    + '<script src="/shortcut-dialog.js"></script><script src="/shortcut-searchcursor.js"></script>'
                    + '<script src="/shortcut-search.js"></script><script type="module"')});
            });
        }
        await page.goto(origin + '/admin.html?id=9&codemirror=1&toolbar=1&alt=1');
        await page.waitForFunction(() => window.adminEditorReady);
        await page.evaluate(text => window.adminEditor.setValue(text, true), images);
        await run(page);
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
}

export async function runAdminShortcutTargetRegressions(browser, origin) {
    for (const field of ['alt', 'search']) {
        await withPage(browser, origin, async page => {
            let input;
            if (field === 'alt') {
                await placeCaret(page, 1);
                await page.locator('.ai-image-alt-text').click();
                input = page.locator('.ai-image-alt-input');
            } else {
                await placeCaret(page, 0);
                await page.keyboard.press('Control+f');
                input = page.locator('.CodeMirror-dialog input');
            }
            await input.fill('Unfinished field text');
            for (const key of ['Control+e', 'Control+b']) {
                await input.press(key);
                assert.equal(await value(page), images, `${key} in ${field} must not format the source`);
                assert.equal(await input.inputValue(), 'Unfinished field text');
            }

            const saves = holdRequests(page, '**/admin-save?id=9');
            await saves.installed;
            await input.press('Control+s');
            const request = await saves.next();
            const expected = field === 'alt' ? images.replace('First description', 'Unfinished field text') : images;
            assert.equal((await formData(request)).get('body').replace(/\r\n/g, '\n'), expected);
            await request.fulfill({json: {revision: 2, urlStatus: 'ok', urlTitle: '', url: '/post'}});
            await page.waitForFunction(() => document.querySelector('[name="revision"]').value === '2');
            if (field === 'search') await input.press('Escape');

            await placeCaret(page, 0);
            await page.keyboard.press('Control+e');
            assert.equal(await value(page), expected.replace('<p>Opening', '<p align="center">Opening'), 'Source formatting must still work');
            await page.getByRole('button', {name: 'Undo', exact: true}).click();
            assert.equal(await value(page), expected);
        }, field === 'search');
        console.log(`admin shortcuts: ${field} edits stay separate from source formatting and save correctly`);
    }
}

export async function runAltCursorRegressions(browser, origin) {
    const scenarios = [
        ['success', 'image'], ['http-error', 'image'], ['network-error', 'image'], ['invalid-json', 'image'],
        ['http-error', 'paragraph'], ['http-error', 'pending-image'],
    ];
    for (const [reply, cursor] of scenarios) {
        await withPage(browser, origin, async page => {
            const requests = holdRequests(page, '**/admin-ai-alt');
            await requests.installed;
            await placeCaret(page, 1);
            await page.locator('.ai-image-alt-regenerate').click();
            const first = await requests.next();
            await placeCaret(page, cursor === 'paragraph' ? 2 : 3);
            let second;
            if (cursor === 'pending-image') {
                await page.locator('.ai-image-alt-regenerate').click();
                second = await requests.next();
            }
            const completed = page.waitForEvent(reply === 'network-error' ? 'requestfailed' : 'requestfinished', {
                predicate: request => request === first.request(),
            });
            if (reply === 'network-error') await first.abort('failed');
            else if (reply === 'invalid-json') await first.fulfill({contentType: 'application/json', body: '{invalid json'});
            else await first.fulfill(reply === 'success'
                ? {json: {success: true, result: 'Generated first'}} : {status: 503, json: {success: false}});
            // Allow the response and its coalesced cursor synchronization to finish.
            await completed;
            await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            const expected = reply === 'success' ? images.replace('First description', 'Generated first') : images;
            assert.equal(await value(page), expected);
            if (cursor === 'paragraph') {
                assert.equal(await page.locator('.ai-image-alt-preview').count(), 0, 'Completion must not reopen a panel outside an image');
            } else {
                assert.equal(await page.locator('.ai-image-alt-preview img').getAttribute('src'), '/second.png');
                assert.equal(await page.locator('.ai-image-alt-preview').getAttribute('data-state'), second ? 'generating' : 'ready');
            }
            if (second) {
                await second.fulfill({json: {success: true, result: 'Generated second'}});
                await page.waitForFunction(() => window.adminEditor.getValue().includes('Generated second'));
                await page.locator('.ai-image-alt-text').waitFor();
                assert.equal(await page.locator('.ai-image-alt-text').textContent(), 'Generated second');
            }

            // The first image keeps its own result/error and remains retryable.
            await placeCaret(page, 1);
            if (reply === 'success') {
                assert.equal(await page.locator('.ai-image-alt-text').textContent(), 'Generated first');
            } else {
                await page.locator('.ai-image-alt-retry').click();
                const retry = await requests.next();
                assert.equal((await formData(retry)).get('image_src'), '/first.png');
                await placeCaret(page, 3);
                await retry.fulfill({json: {success: true, result: 'Retried first'}});
                await page.waitForFunction(() => window.adminEditor.getValue().includes('Retried first'));
                await page.waitForLoadState('networkidle');
                assert.equal(await page.locator('.ai-image-alt-preview img').getAttribute('src'), '/second.png');
            }
        });
        console.log(`admin alt: ${reply} retains the current ${cursor} and the original image result`);
    }
}
