import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const saved = {revision: 2, urlStatus: 'ok', urlTitle: '', url: '/page'};
const fieldErrors = {
    title: ['This value is too long.'],
    tags: ['Tags must contain only letters, numbers and spaces.'],
    body: [],
};
const messages = ['Title: This value is too long.', 'Tags: Tags must contain only letters, numbers and spaces.'];
const warning = page => page.evaluate(() => window.onbeforeunload());
const popup = page => page.locator('#popup_message .message');

async function withPage(browser, origin, codemirror, run) {
    const page = await browser.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    try {
        await page.goto(origin + '/admin.html?id=9&popup=1&fetch-wrapper=1&activitypub=1'
            + (codemirror ? '&codemirror=1&toolbar=1' : ''));
        await page.waitForFunction(() => window.adminEditorReady);
        await page.addStyleTag({url: '/admin-editor.css'});
        await page.locator('[data-activitypub-preview-status]').evaluate(element => {
            element.classList.add('activitypub-editor-preview-status');
        });
        if (codemirror) {
            // The real title/tags controls have accessible names but no visible labels.
            await page.evaluate(() => {
                for (const name of ['title', 'tags']) {
                    const input = document.querySelector(`[name="${name}"]`);
                    input.setAttribute('aria-label', input.labels[0].textContent.trim());
                    input.parentElement.replaceWith(input);
                }
            });
        }
        await page.locator('[name="title"]').fill('T'.repeat(256));
        await page.locator('[name="tags"]').fill('invalid+tag');
        if (codemirror) await page.evaluate(() => window.adminEditor.setValue('Unsaved body'));
        else await page.locator('[name="body"]').fill('Unsaved body');
        await run(page);
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
}

async function submit(page, requests) {
    await page.getByRole('button', {name: 'Save', exact: true}).click();
    return requests.next();
}

async function checkPopup(page, expected) {
    await popup(page).waitFor();
    assert.deepEqual(await popup(page).allTextContents(), [expected], 'Every current error must be visible in one notification');
    assert.equal(await page.locator('[name="revision"]').inputValue(), '1');
    assert.equal(await warning(page), 'Unsaved changes');
    assert.equal(await page.evaluate(() => window.readAdminDraft('9')), 'Unsaved body');
    assert.equal(await page.locator('form').evaluate(form => form.inert), false);
}

async function retry(page, requests) {
    await page.locator('[name="title"]').fill('Corrected title');
    await page.locator('[name="tags"]').fill('valid');
    const request = await submit(page, requests);
    const data = await formData(request);
    assert.equal(data.get('title'), 'Corrected title');
    assert.equal(data.get('tags'), 'valid');
    assert.equal(data.get('body'), 'Unsaved body');
    await request.fulfill({json: saved});
    await page.waitForFunction(() => document.querySelector('[name="revision"]').value === '2');
    assert.equal(await popup(page).count(), 0, 'Success clears the complete previous error notification');
    assert.equal(await warning(page), undefined);
    assert.equal(await page.evaluate(() => window.readAdminDraft('9')), null);
}

export async function runAdminFieldErrorRegressions(browser, origin) {
    for (const codemirror of [false, true]) {
        for (const mode of ['fields', 'mixed', 'csrf-retry', 'malformed-fields']) {
            await withPage(browser, origin, codemirror, async page => {
                const requests = holdRequests(page, '**/admin-save?id=9');
                await requests.installed;
                let request = await submit(page, requests);
                if (mode === 'csrf-retry') {
                    await request.fulfill({status: 422, json: {invalid_csrf_token: true}});
                    request = await requests.next();
                    assert.equal((await formData(request)).get('title'), 'T'.repeat(256));
                }
                const literal = 'Rejected <strong>value</strong> & details.';
                const expected = mode === 'mixed' ? [literal, ...messages].join('\n')
                    : mode === 'malformed-fields' ? 'Useful fallback' : messages.join('\n');
                await request.fulfill({status: 422, json: mode === 'malformed-fields'
                    ? {errors: [null, {}, ' '], field_errors: {title: null, tags: [''], body: [' ', 42, {}]}, message: 'Useful fallback'}
                    : {success: false, errors: mode === 'mixed' ? [literal, null, ''] : [], field_errors: fieldErrors}});
                await checkPopup(page, expected);
                assert.equal(await popup(page).locator('strong').count(), 0, 'Server messages remain literal text');
                if (mode !== 'malformed-fields') {
                    assert.equal(await popup(page).evaluate(element => getComputedStyle(element).whiteSpace), 'pre-line');
                }
                await retry(page, requests);
            });
            console.log(`admin validation: ${codemirror ? 'CodeMirror' : 'textarea'} ${mode} shows field errors and retains a complete retry`);
        }
    }
}

export async function runAdminErrorRefreshRegressions(browser, origin) {
    for (const codemirror of [false, true]) {
        await withPage(browser, origin, codemirror, async page => {
            const requests = holdRequests(page, '**/admin-save?id=9');
            await requests.installed;
            await (await submit(page, requests)).fulfill({status: 409, json: {message: 'First failure'}});
            await checkPopup(page, 'First failure');
            await (await submit(page, requests)).fulfill({status: 401, json: {message: 'Sign in again.'}});
            await page.waitForFunction(() => document.querySelector('#popup_message')?.textContent.includes('Sign in again.'));
            await checkPopup(page, 'Sign in again.');
            await (await submit(page, requests)).fulfill({status: 422, json: {errors: ['Correct the date.', 'Choose another address.']}});
            await page.waitForFunction(() => document.querySelector('#popup_message .message')?.textContent === 'Correct the date.\nChoose another address.');
            await checkPopup(page, 'Correct the date.\nChoose another address.');
            await (await submit(page, requests)).fulfill({status: 503, json: {message: 'Try later.'}});
            await page.waitForFunction(() => document.querySelector('#popup_message .message')?.textContent === 'Try later.');
            await checkPopup(page, 'Try later.');
            await (await submit(page, requests)).fulfill({status: 401, json: {message: 'Sign in again.'}});
            await page.waitForFunction(() => document.querySelector('#popup_message')?.textContent.includes('Sign in again.'));
            await checkPopup(page, 'Sign in again.');
            await retry(page, requests);
        });
        console.log(`admin validation: ${codemirror ? 'CodeMirror' : 'textarea'} repeated failures replace stale messages and keep every current error`);
    }
}

export async function runActivityPubFieldErrorRegressions(browser, origin) {
    for (const codemirror of [false, true]) {
        await withPage(browser, origin, codemirror, async page => {
            const requests = holdRequests(page, '**/admin-activitypub-preview');
            await requests.installed;
            const button = page.getByRole('button', {name: 'Build ActivityPub preview', exact: true});
            const status = page.locator('[data-activitypub-preview-status]');
            await button.click();
            await (await requests.next()).fulfill({status: 422, json: {
                success: false, message: 'The preview form contains errors.', errors: [], field_errors: fieldErrors,
            }});
            await page.waitForFunction(() => document.querySelector('[data-activitypub-preview-status]').classList.contains('is-error'));
            assert.equal(await status.textContent(), messages.join('\n'));
            assert.equal(await status.evaluate(element => getComputedStyle(element).whiteSpace), 'pre-line');
            assert.equal(await popup(page).count(), 0, 'The fetch interceptor must not duplicate inline errors');
            assert.equal(await warning(page), 'Unsaved changes');
            await page.locator('[name="title"]').fill('Corrected title');
            await page.locator('[name="tags"]').fill('valid');
            await button.click();
            const retry = await requests.next();
            assert.equal((await formData(retry)).get('title'), 'Corrected title');
            await retry.fulfill({json: {success: true, message: 'Current preview', content_html: '<p>Preview</p>'}});
            await page.waitForFunction(() => !document.querySelector('[data-activitypub-preview-result]').hidden);
            assert.equal(await status.textContent(), 'Current preview');
            assert.equal(await status.evaluate(element => element.classList.contains('is-error')), false);
            assert.equal(await warning(page), 'Unsaved changes');
        });
        console.log(`ActivityPub validation: ${codemirror ? 'CodeMirror' : 'textarea'} identifies rejected fields and permits a corrected preview`);
    }
}
