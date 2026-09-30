import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const fallback = 'Unable to save. Please try again.';

async function withPage(browser, run) {
    const page = await browser.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    try {
        await run(page);
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
}

export async function runAdminSaveFailureRegressions(browser, origin) {
    for (const codemirror of [false, true]) {
        for (const fault of ['network', '503-html', '409-message', '500-errors', '422-invalid', 'malformed-json', 'missing-revision', 'csrf-503', 'create-network']) {
            await withPage(browser, async page => {
                const creating = fault === 'create-network';
                const requests = holdRequests(page, '**/admin-save*');
                await requests.installed;
                await page.goto(origin + '/admin.html?fetch-wrapper=1' + (creating ? '' : '&id=9')
                    + (codemirror ? '&codemirror=1&toolbar=1' : ''));
                await page.waitForFunction(() => window.adminEditorReady);
                await page.evaluate(() => {
                    window.saveEndCount = 0;
                    document.addEventListener('save_article_end.register', () => window.saveEndCount++);
                });
                const text = 'Keep the unsaved body';
                if (codemirror) await page.evaluate(text => window.adminEditor.setValue(text), text);
                else await page.locator('[name="body"]').fill(text);
                await page.getByRole('button', {name: 'Save', exact: true}).click();
                let request = await requests.next();
                assert.equal((await formData(request)).get('body'), text);
                if (fault === 'csrf-503') {
                    await request.fulfill({status: 422, json: {invalid_csrf_token: true}});
                    request = await requests.next();
                    assert.equal((await formData(request)).get('body'), text);
                }
                if (fault.endsWith('network')) {
                    await request.abort('failed');
                    if (!creating) await (await requests.next()).abort('failed');
                }
                else if (fault === '409-message') await request.fulfill({status: 409, json: {message: 'Server conflict'}});
                else if (fault === '500-errors') await request.fulfill({status: 500, json: {errors: ['Server rejected save']}});
                else if (fault === '422-invalid') await request.fulfill({status: 422, json: {errors: null}});
                else if (fault === 'missing-revision') await request.fulfill({json: {}});
                else await request.fulfill({status: fault === 'malformed-json' ? 200 : 503, contentType: 'text/html', body: '<h1>Unavailable</h1>'});
                const expected = fault === '409-message' ? 'Server conflict' : fault === '500-errors' ? 'Server rejected save' : fallback;
                await page.waitForFunction(expected => document.getElementById('error').textContent === expected, expected);
                assert.deepEqual(await page.evaluate(() => window.adminMessages), [expected], 'The interceptor and form must not show duplicate errors');
                assert.equal(await page.evaluate(() => window.saveEndCount), 0);
                assert.equal(await page.locator('[name="revision"]').inputValue(), '1');
                assert.equal(await page.evaluate(() => window.onbeforeunload()), 'Unsaved changes');
                assert.equal(await page.evaluate(key => window.readAdminDraft(key), creating ? 'new' : '9'), text);
                assert.equal(await page.locator('form').evaluate(form => form.inert), false);
                const next = text + ' and a retry';
                if (codemirror) {
                    assert.equal(await page.evaluate(() => document.querySelector('.CodeMirror').CodeMirror.getOption('readOnly')), false);
                    await page.evaluate(next => window.adminEditor.setValue(next), next);
                } else await page.locator('[name="body"]').fill(next);
                await page.getByRole('button', {name: 'Save', exact: true}).click();
                const retry = await requests.next();
                assert.equal((await formData(retry)).get('body'), next);
                if (creating) {
                    await retry.continue({url: origin + '/admin-save-redirect'});
                    await page.waitForURL('**/admin.html?id=10');
                    assert.equal(await page.evaluate(() => window.readAdminDraft('new')), null);
                } else {
                    await retry.fulfill({json: {revision: 2, urlStatus: 'ok', urlTitle: '', url: '/post'}});
                    await page.waitForFunction(() => document.querySelector('[name="revision"]').value === '2');
                    assert.equal(await page.locator('#error').textContent(), '');
                    assert.equal(await page.evaluate(() => window.onbeforeunload()), undefined);
                    assert.equal(await page.evaluate(() => window.saveEndCount), 1);
                }
            });
            console.log(`admin save: ${codemirror ? 'CodeMirror' : 'textarea'} ${fault} reports failure and retains a retryable draft`);
        }
    }
}

export async function runSocialPreviewRegressions(browser, origin) {
    for (const codemirror of [false, true]) {
        await withPage(browser, async page => {
            await page.goto(origin + '/admin.html?id=9&social=1&ai=1' + (codemirror ? '&codemirror=1&toolbar=1' : ''));
            await page.waitForFunction(() => window.adminEditorReady);
            const check = async (description, image) => {
                await page.waitForFunction(({description, image}) => document.querySelector('[data-social-preview-description]').textContent === description
                    && document.querySelector('[data-social-preview-image]').style.backgroundImage === `url("${image}")`, {description, image});
            };
            const body = '<p>New description</p><img src="/new.png">';
            if (codemirror) {
                await page.locator('.CodeMirror').click();
                const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
                await page.keyboard.press(`${modifier}+a`);
                await page.keyboard.insertText(body);
            } else await page.locator('[name="body"]').fill(body);
            await check('New description', '/new.png');
            if (codemirror) {
                await page.getByRole('button', {name: 'Undo', exact: true}).click();
                await check('Server body', '/default.png');
                await page.getByRole('button', {name: 'Redo', exact: true}).click();
                await check('New description', '/new.png');
                const ai = holdRequests(page, '**/admin-ai');
                await ai.installed;
                await page.evaluate(() => document.querySelector('.CodeMirror').CodeMirror.setCursor({line: 0, ch: 0}));
                await page.getByRole('button', {name: 'Proofread', exact: true}).click();
                await (await ai.next()).fulfill({json: {success: true, result: '<p>Corrected description</p><img src="/corrected.png">'}});
                await check('Corrected description', '/corrected.png');
            }
            await page.locator('[name="meta_description"]').fill('Explicit description');
            await page.locator('[name="social_image"]').fill('/explicit.png');
            const change = '<p>Changed source</p><img src="/changed.png">';
            if (codemirror) await page.evaluate(change => window.adminEditor.setValue(change), change);
            else await page.locator('[name="body"]').fill(change);
            await check('Explicit description', '/explicit.png');
            await page.locator('[name="meta_description"]').fill('');
            await page.locator('[name="social_image"]').fill('');
            await check('Changed source', '/changed.png');
            await page.locator('[name="title"]').fill('New title');
            assert.equal(await page.locator('[data-social-preview-title]').textContent(), 'New title');
        });
        console.log(`social preview: ${codemirror ? 'CodeMirror' : 'textarea'} changes, undo/redo, AI and explicit overrides stay current`);
        await withPage(browser, async page => {
            await page.addInitScript(() => localStorage.setItem('register:admin-recovery:1:%2F:1:post:seed', JSON.stringify({version: 1, id: 'seed', target: '9', revision: 1, savedAt: Date.now(), snapshot: [['title', 'Server title'], ['body', '<p>Recovered description</p><img src="/recovered.png">'], ['tags', 'old']]})));
            await page.goto(origin + '/admin.html?id=9&social=1' + (codemirror ? '&codemirror=1' : ''));
            await page.waitForFunction(() => window.adminEditorReady);
            await page.getByRole('button', {name: 'Restore draft', exact: true}).first().click();
            assert.equal(await page.locator('[data-social-preview-description]').textContent(), 'Recovered description');
            assert.equal(await page.locator('[data-social-preview-image]').evaluate(image => image.style.backgroundImage), 'url("/recovered.png")');
        });
        console.log(`social preview: ${codemirror ? 'CodeMirror' : 'textarea'} renders a restored draft at initialization`);
    }
}
