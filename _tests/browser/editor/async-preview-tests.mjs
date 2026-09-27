import assert from 'node:assert/strict';
import {holdRequests} from './save-tests.mjs';

const template = {json: {success: true,
    template: '<!doctype html><html><body><!-- register_title --><!-- register_text --></body></html>',
}};

export async function runAsyncPreviewRegressions(browser, origin) {
    const errors = [];
    for (const codemirror of [false, true]) {
        for (const staleResult of ['success', 'http-error', 'network-error', 'malformed-json']) {
            const context = await browser.newContext();
            const page = await context.newPage();
            page.setDefaultTimeout(10000);
            page.on('pageerror', error => errors.push(String(error)));
            try {
                await page.clock.install();
                const requests = holdRequests(page, '**/admin-ajax*');
                await requests.installed;
                await page.goto(origin + '/admin.html?id=9&preview=1' + (codemirror ? '&codemirror=1' : ''));
                await page.waitForFunction(() => window.adminEditorReady);
                const initial = await requests.next();
                await page.locator('[name="title"]').fill('Latest title');
                await (await requests.next()).fulfill(template);
                await page.waitForFunction(() => document.getElementById('body-preview-frame').contentDocument
                    .getElementById('preview-header-wrapper')?.textContent === 'Latest title');
                if (codemirror) await page.evaluate(() => window.adminEditor.setValue('Latest body'));
                else await page.locator('[name="body"]').fill('Latest body');
                await page.clock.fastForward(400);
                const frame = page.frameLocator('#body-preview-frame');
                assert.equal(await frame.locator('#preview-text-wrapper').textContent(), 'Latest body');

                if (staleResult === 'success') await initial.fulfill(template);
                else if (staleResult === 'http-error') await initial.fulfill({status: 503, json: {success: false}});
                else if (staleResult === 'network-error') await initial.abort('failed');
                else await initial.fulfill({contentType: 'application/json', body: '{invalid json'});
                await page.waitForLoadState('networkidle');
                await page.clock.fastForward(6000);
                assert.equal(await frame.locator('#preview-header-wrapper').textContent(), 'Latest title');
                assert.equal(await frame.locator('#preview-text-wrapper').textContent(), 'Latest body');
                assert.equal(await frame.locator('.editor-preview-error').count(), 0);
            } finally { await context.close(); }
            console.log(`preview: stale ${staleResult} cannot overwrite newer title and body (${codemirror ? 'CodeMirror' : 'textarea'})`);
        }
    }
    assert.deepEqual(errors, []);
}
