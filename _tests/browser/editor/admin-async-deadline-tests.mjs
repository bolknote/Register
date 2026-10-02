import assert from 'node:assert/strict';
import {holdRequests} from './save-tests.mjs';

const imageSource = '<p>Opening</p>\n<img src="/first.png" alt="Original description">';
const previewPayload = text => ({
    success: true, message: 'Preview ready', content_html: `<p>${text}</p>`, pretty_json: text,
});
const scenarios = [
    {name: 'AI', endpoint: '/admin-ai', flag: 'ai', error: 'Failed'},
    {name: 'image alt', endpoint: '/admin-ai-alt', flag: 'alt', error: 'Alt failed'},
    {name: 'ActivityPub', endpoint: '/admin-activitypub-preview', flag: 'activitypub', error: 'Preview failed'},
];

function controls(page, scenario) {
    if (scenario.flag === 'ai') return {
        button: page.locator('[data-ai-action="proofread"]'),
        retry: page.locator('[data-ai-action="proofread"]'),
    };
    if (scenario.flag === 'alt') return {
        button: page.locator('.ai-image-alt-regenerate'),
        retry: page.locator('.ai-image-alt-retry'),
    };
    return {
        button: page.locator('[data-activitypub-preview-button]'),
        retry: page.locator('[data-activitypub-preview-button]'),
    };
}

async function result(page, scenario) {
    return scenario.flag === 'activitypub'
        ? page.locator('[data-activitypub-preview-json]').textContent()
        : page.evaluate(() => window.adminEditor.getValue());
}

export async function runAdminAsyncDeadlineRegressions(browser, origin) {
    for (const scenario of scenarios) {
        for (const phase of ['fetch', 'json']) {
            const page = await browser.newPage();
            page.setDefaultTimeout(10000);
            const errors = [];
            page.on('pageerror', error => errors.push(String(error)));
            try {
                const requests = holdRequests(page, '**' + scenario.endpoint);
                await requests.installed;
                await page.goto(origin + '/admin.html?id=9&codemirror=1&toolbar=1&timeout=250&' + scenario.flag + '=1');
                await page.waitForFunction(() => window.adminEditorReady);
                if (scenario.flag === 'alt') {
                    await page.evaluate(source => {
                        window.adminEditor.setValue(source, true);
                        const cm = document.querySelector('.CodeMirror').CodeMirror;
                        cm.setCursor({line: 1, ch: 5});
                        cm.focus();
                    }, imageSource);
                } else if (scenario.flag === 'ai') {
                    await page.evaluate(() => {
                        const cm = document.querySelector('.CodeMirror').CodeMirror;
                        cm.setSelection({line: 0, ch: 0}, {line: 0, ch: cm.getValue().length});
                        cm.focus();
                    });
                }

                await page.evaluate(({endpoint, phase}) => {
                    const fetch = window.fetch;
                    window.asyncDeadlineRequests = 0;
                    window.asyncDeadlineAborts = 0;
                    window.fetch = function (url, options) {
                        if (new URL(String(url), location.href).pathname === endpoint) {
                            window.asyncDeadlineRequests++;
                            options.signal.addEventListener('abort', () => window.asyncDeadlineAborts++, {once: true});
                        }
                        return fetch.apply(this, arguments);
                    };
                    if (phase === 'json') {
                        const json = Response.prototype.json;
                        let first = true;
                        Response.prototype.json = async function (...args) {
                            const data = await json.apply(this, args);
                            if (new URL(this.url).pathname === endpoint && first) {
                                first = false;
                                await new Promise(resolve => { window.releaseAsyncDeadlineJson = resolve; });
                            }
                            return data;
                        };
                    }
                }, {endpoint: scenario.endpoint, phase});

                const ui = controls(page, scenario);
                const originalBody = await page.evaluate(() => window.adminEditor.getValue());
                await ui.button.click();
                const stalled = await requests.next();
                if (phase === 'json') {
                    await stalled.fulfill({json: scenario.flag === 'activitypub'
                        ? previewPayload('Obsolete preview') : {success: true, result: 'Obsolete output'}});
                    await page.waitForFunction(() => typeof window.releaseAsyncDeadlineJson === 'function');
                }
                await page.waitForFunction(({flag, error}) => {
                    const selector = flag === 'ai' ? '#ai-tools-status'
                        : flag === 'alt' ? '.ai-image-alt-overlay' : '[data-activitypub-preview-status]';
                    return document.querySelector(selector)?.textContent.includes(error);
                }, {flag: scenario.flag, error: scenario.error});
                assert.equal(await ui.retry.isEnabled(), true, `${scenario.name}: timeout unlocks retry`);
                assert.equal(await page.evaluate(() => window.adminEditor.getValue()), originalBody, 'Timeout retains the source');
                assert.deepEqual(await page.evaluate(() => [window.asyncDeadlineRequests, window.asyncDeadlineAborts]), [1, 1],
                    'The deadline aborts the transport without automatically repeating the operation');
                if (scenario.flag === 'ai') {
                    assert.equal(await page.evaluate(() => document.querySelector('.CodeMirror').CodeMirror.getAllMarks()
                        .filter(marker => !marker.className).length), 0, 'Timeout releases tracked source ranges');
                    assert.equal(await page.locator('[data-ai-action]:disabled').count(), 0, 'Every AI action unlocks');
                }

                await ui.retry.click();
                const retry = await requests.next();
                await retry.fulfill({json: scenario.flag === 'activitypub'
                    ? previewPayload('Current preview') : {success: true, result: 'Current output'}});
                const expected = scenario.flag === 'activitypub' ? 'Current preview'
                    : scenario.flag === 'alt' ? imageSource.replace('Original description', 'Current output') : 'Current output';
                await page.waitForFunction(({flag, expected}) => (flag === 'activitypub'
                    ? document.querySelector('[data-activitypub-preview-json]').textContent : window.adminEditor.getValue()) === expected,
                {flag: scenario.flag, expected});
                if (phase === 'json') {
                    await page.evaluate(() => window.releaseAsyncDeadlineJson());
                    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
                    assert.equal(await result(page, scenario), expected, 'A late decoded result must not overwrite the successful retry');
                }
                assert.equal(await page.evaluate(() => window.asyncDeadlineRequests), 2, 'Only the explicit retry sends another request');
                assert.equal(await page.locator('form').evaluate(form => form.inert), false);
                assert.deepEqual(errors, []);
            } catch (error) {
                throw new Error(`admin ${scenario.name} ${phase} deadline: ${error.message}`, {cause: error});
            } finally {
                await page.close();
            }
            console.log(`admin ${scenario.name}: stalled ${phase} aborts, retains source, unlocks and permits an explicit retry`);
        }
    }
}
