import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {formData, holdRequests} from './save-tests.mjs';

const source = '<p>A sentense.</p><p>Keep <a href="/exact-url">the closing paragraph</a>.</p>';
const message = 'Unable to get a response from the site. Your text is unchanged; retry the AI request.';
const timeoutMessage = 'AI did not respond in time. Your text is unchanged; retry the AI request.';

export async function runAiNetworkRegressions(browser, origin) {
    for (const editor of ['inplace-whole', 'inplace-selection', 'admin-whole', 'admin-selection']) {
        for (const fault of ['fetch', 'body', 'server', 'timeout']) {
            const page = await browser.newPage();
            page.setDefaultTimeout(10000);
            const inplace = editor.startsWith('inplace');
            const endpoint = inplace ? '/_inplace/post/9' : '/admin-ai';
            const status = page.locator(inplace ? '.post-inplace-status' : '#ai-tools-status');
            const requests = holdRequests(page, '**' + endpoint);
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            try {
                await requests.installed;
                await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
                await page.goto(origin + (inplace ? '/recovery.html' + (fault === 'timeout' ? '?timeout=250' : '')
                    : '/admin.html?id=9&ai=1&codemirror=1&toolbar=1' + (fault === 'timeout' ? '&timeout=250' : '')));
                if (inplace) {
                    await page.getByRole('button', {name: 'Edit', exact: true}).click();
                    await page.locator('.post-card.is-editing [data-post-inplace-body]').evaluate((body, html) => {
                        body.innerHTML = html;
                        body.dispatchEvent(new InputEvent('input', {bubbles: true, inputType: 'insertText'}));
                    }, source);
                } else {
                    await page.waitForFunction(() => window.adminEditorReady);
                    await page.evaluate(html => window.adminEditor.setValue(html, true), source);
                    if (editor.endsWith('selection')) await page.evaluate(() => {
                        const cm = document.querySelector('.CodeMirror').CodeMirror;
                        cm.setSelection({line: 0, ch: 0}, {line: 0, ch: '<p>A sentense.</p>'.length});
                    });
                }
                const value = () => page.evaluate(inplace => inplace
                    ? window.editorTest.editableBodyHtml(window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing')))
                    : window.adminEditor.getValue(), inplace);
                const before = await value();
                const start = async () => {
                    if (inplace) {
                        const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
                        await body.evaluate((body, selected) => {
                            const range = document.createRange();
                            if (selected) range.selectNodeContents(body.firstElementChild);
                            else { range.selectNodeContents(body); range.collapse(true); }
                            getSelection().removeAllRanges(); getSelection().addRange(range);
                            body.focus();
                        }, editor.endsWith('selection'));
                        await body.locator('p').first().click({button: 'right'});
                        await page.locator('[data-context-ai-action="proofread"]').click();
                    } else await page.locator('[data-ai-action="proofread"]').click();
                };
                if (fault === 'body') await page.evaluate(endpoint => {
                    const json = Response.prototype.json;
                    let first = true;
                    Response.prototype.json = function (...args) {
                        if (new URL(this.url).pathname === endpoint && first) {
                            first = false;
                            return Promise.reject(new TypeError('Failed to fetch'));
                        }
                        return json.apply(this, args);
                    };
                }, endpoint);
                await start();
                const failed = await requests.next();
                const sent = await formData(failed);
                assert.equal(sent.get('ai_action'), 'proofread');
                if (fault === 'fetch') await failed.abort('failed');
                else if (fault !== 'timeout') await failed.fulfill({status: fault === 'server' ? 502 : 200,
                    json: fault === 'server' ? {success: false, message: 'AI provider is unavailable'}
                        : {success: true, action: 'ai', ai_action: 'proofread', result: 'Discard this incomplete response'}});
                await page.waitForFunction(({inplace, expected}) => {
                    const status = document.querySelector(inplace ? '.post-inplace-status' : '#ai-tools-status');
                    return status?.textContent === expected;
                }, {inplace, expected: fault === 'server' ? 'AI provider is unavailable' : fault === 'timeout' ? timeoutMessage : message});
                if (fault === 'timeout') await failed.abort('failed').catch(() => {});
                assert.equal(await value(), before, `${editor}/${fault}: the failure preserves the exact draft and links`);
                assert.equal(requests.count, 0, 'A lost response must not automatically repeat a potentially billable AI request');
                if (inplace) assert.equal(await page.locator('.post-card.is-ai-working').count(), 0);
                else assert.equal(await page.locator('[data-ai-action="proofread"]').isEnabled(), true);
                await start();
                const retry = await requests.next();
                const retryData = await formData(retry);
                assert.equal(retryData.get('text'), sent.get('text'), 'Manual retry uses the intact original source');
                await retry.fulfill({json: {success: true, action: 'ai', ai_action: 'proofread',
                    result: retryData.get('text').replace('sentense', 'sentence')}});
                await page.waitForFunction(({inplace, expected}) => (inplace
                    ? window.editorTest.editableBodyHtml(window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing')))
                    : window.adminEditor.getValue()) === expected, {inplace, expected: before.replace('sentense', 'sentence')});
                assert.deepEqual(errors, []);
            } catch (error) {
                throw new Error(`AI ${editor}/${fault}: ${error.message}; status: ${await status.textContent()}`, {cause: error});
            } finally { await page.close(); }
        }
    }
    console.log(`${browser.browserType().name()}: AI transport and broken-response errors retain exact drafts, distinguish provider errors and allow manual retry`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const [name, type] of Object.entries({chromium, firefox, webkit})) {
            if (process.env.EDITOR_TEST_BROWSER && process.env.EDITOR_TEST_BROWSER !== name) continue;
            const browser = await type.launch();
            try { await runAiNetworkRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
