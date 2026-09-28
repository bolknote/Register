import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

export async function runConcurrencyRegressions(browser, origin) {
    const errors = [];
    async function withContext(run) {
        const context = await browser.newContext({timezoneId: 'Europe/Moscow'});
        context.on('page', page => {
            page.setDefaultTimeout(10000);
            page.on('pageerror', error => errors.push(String(error)));
        });
        await context.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        try { await run(context); }
        finally { await context.close(); }
    }

    await withContext(async context => {
        await context.addInitScript(() => {
            const setItem = Storage.prototype.setItem;
            Storage.prototype.setItem = function (key, value) {
                if (window.blockDraftStorage && key.startsWith('register:post-recovery:')) {
                    throw new DOMException('Storage full', 'QuotaExceededError');
                }
                return setItem.call(this, key, value);
            };
        });
        for (let index = 1; index <= 10; index++) {
            const page = await context.newPage();
            await page.goto(origin + '/recovery.html');
            await page.getByRole('button', {name: 'New post', exact: true}).click();
            await page.locator('.post-card.is-editing [data-post-inplace-body]').fill(`Unfinished draft ${index}.`);
            await page.waitForFunction(index => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1).list()
                .some(copy => copy.snapshot.body.includes(`Unfinished draft ${index}.`)), index);
            await page.close();
        }
        const page = await context.newPage();
        await page.goto(origin + '/recovery.html');
        const copies = () => page.evaluate(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1).list());
        const before = await copies();
        assert.equal(before.length, 10);
        await page.getByRole('button', {name: 'New post', exact: true}).click();
        await page.evaluate(() => { window.blockDraftStorage = true; });
        const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
        await body.fill('Latest unsaved draft 11.');
        await page.waitForFunction(() => document.querySelector('.post-inplace-status.is-error:not([hidden])'));
        assert.deepEqual(await copies(), before, 'A failed new copy must not evict any older copy');

        await page.evaluate(() => { window.blockDraftStorage = false; });
        await body.press('End');
        await page.keyboard.type(' Continued after storage recovers.');
        await page.waitForFunction(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1).list()
            .some(copy => copy.snapshot.body.includes('Continued after storage recovers.')));
        const after = await copies();
        assert.equal(after.length, 10);
        assert.ok(!after.some(copy => copy.snapshot.body.includes('Unfinished draft 1.')));
    });
    console.log('recovery: a failed eleventh draft keeps every older copy; retention resumes after a successful write');

    for (const action of ['title', 'tags']) {
        for (const change of ['replace', 'append', 'unchanged', 'outside-selection']) {
            await withContext(async context => {
                const page = await context.newPage();
                const ai = holdRequests(page, '**/admin-ai');
                await ai.installed;
                await page.goto(origin + '/admin.html?id=9&codemirror=1&ai=1');
                await page.waitForFunction(() => window.adminEditorReady);
                if (change === 'outside-selection') {
                    await page.locator('.CodeMirror [contenteditable="true"]').click();
                    await page.keyboard.press('Home');
                    for (let index = 0; index < 6; index++) await page.keyboard.press('Shift+ArrowRight');
                }
                const target = page.locator(`[name="${action}"]`);
                const original = await target.inputValue();
                await page.locator(`[data-ai-action="${action}"]`).click();
                const pending = await ai.next();
                assert.equal((await formData(pending)).get('text'), change === 'outside-selection' ? 'Server' : 'Server body');
                if (change !== 'unchanged') {
                    await page.locator('.CodeMirror [contenteditable="true"]').click();
                    await page.keyboard.press(change === 'replace' ? 'Meta+a' : 'End');
                    await page.keyboard.type(change === 'replace' ? 'An unrelated article' : ' Added context');
                }
                const editedBody = await page.evaluate(() => window.adminEditor.getValue());
                await pending.fulfill({json: {success: true, result: 'Generated suggestion'}});
                await page.waitForFunction(() => document.getElementById('content-editor-ai-tools').getAttribute('aria-busy') === 'false');
                const applies = change === 'unchanged' || change === 'outside-selection';
                assert.equal(await target.inputValue(), applies ? 'Generated suggestion' : original);
                assert.equal(await page.locator('#ai-tools-status').textContent(), applies ? '' : 'The source text has changed.');
                assert.equal(await page.evaluate(() => window.adminEditor.getValue()), editedBody);
                if (!applies) {
                    await page.locator(`[data-ai-action="${action}"]`).click();
                    await (await ai.next()).fulfill({json: {success: true, result: 'Fresh suggestion'}});
                    await page.waitForFunction(() => document.getElementById('content-editor-ai-tools').getAttribute('aria-busy') === 'false');
                    assert.equal(await target.inputValue(), 'Fresh suggestion');
                }
            });
            console.log(`AI: admin ${action} validates its source (${change}) and allows another request`);
        }
    }

    for (const creating of [false, true]) {
        await withContext(async context => {
            let currentUrl = '/media/2026.09.06.wav';
            await context.route('**/media/*.wav', route => route.fulfill({
                status: new URL(route.request().url()).pathname === currentUrl ? 200 : 404,
                contentType: 'audio/wav', body: 'RIFF',
            }));
            const first = await context.newPage();
            const firstRequests = holdRequests(first, '**/_inplace/post/*');
            await firstRequests.installed;
            await first.goto(origin + '/recovery.html');
            await first.getByRole('button', {name: creating ? 'New post' : 'Edit', exact: true}).click();
            if (creating) await first.keyboard.type('Shared note');
            await first.locator('.post-card.is-editing [data-post-inplace-body]').fill('Unsaved text with an attachment.');
            await first.evaluate(() => {
                const state = window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
                const range = document.createRange();
                range.selectNodeContents(state.body);
                range.collapse(false);
                window.editorTest.insertMediaFiles(state, [new File(['RIFF'], 'clip.wav', {type: 'audio/wav'})], range);
            });
            await (await firstRequests.next()).fulfill({json: {success: true, action: 'media', kind: 'audio',
                media_id: 1, url: currentUrl, name: 'Recording'}});
            await first.waitForFunction(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1).list()
                .some(copy => copy.snapshot.mediaIds.includes(1)));
            const second = await context.newPage();
            const secondRequests = holdRequests(second, '**/_inplace/post/*');
            await secondRequests.installed;
            await second.goto(origin + '/recovery.html');
            await second.getByRole('button', {name: 'Restore text', exact: true}).click();
            await first.locator('.post-card.is-editing .post-inplace-datetime').fill('2026-09-07T15:00');
            await second.locator('.post-card.is-editing .post-inplace-datetime').fill('2026-09-08T15:00');

            const renameResponse = async (requests, url) => {
                const request = await requests.next();
                assert.equal((await formData(request)).get('inplace_action'), 'media_redate');
                currentUrl = url;
                await request.fulfill({json: {success: true, action: 'media_redate', media: [{media_id: 1, url, name: 'Recording'}]}});
            };
            await first.getByRole('button', {name: 'Save', exact: true}).click();
            await renameResponse(firstRequests, '/media/2026.09.07.wav');
            const firstSave = await firstRequests.next();
            const initial = await formData(firstSave);
            assert.equal(initial.get('inplace_action'), creating ? 'create' : 'edit');
            assert.equal(initial.get('uploaded_media_ids'), '');
            assert.ok(initial.get('body').includes(currentUrl));

            // The second redate overtakes the first edit/create request.
            await second.getByRole('button', {name: 'Save', exact: true}).click();
            await renameResponse(secondRequests, '/media/2026.09.08.wav');
            const secondSave = await secondRequests.next();
            // The actual controller rollback and file retention are checked by PostInplaceCest.
            await firstSave.fulfill({status: 409, json: {success: false,
                message: 'An attachment has changed in another window. Try saving again.'}});
            await first.waitForFunction(() => document.querySelector('.post-inplace-edit-error:not([hidden])'));
            assert.match(await first.locator('.post-card.is-editing [data-post-inplace-body]').textContent(), /Unsaved text with an attachment/u);
            assert.equal(await first.locator('.post-card.is-editing [name="revision"]').inputValue(), creating ? '0' : '1');
            await first.getByRole('button', {name: 'Save', exact: true}).click();
            await renameResponse(firstRequests, '/media/2026.09.07.1.wav');
            const retry = await firstRequests.next();
            const retried = await formData(retry);
            assert.ok(retried.get('body').includes(currentUrl));
            assert.ok(!retried.get('body').includes('/media/2026.09.07.wav'));
            assert.ok(retried.get('body').includes('Unsaved text with an attachment.'));
            await retry.fulfill({json: {success: true, action: creating ? 'create' : 'edit', title: 'Shared note', revision: creating ? 1 : 2,
                body_html: `<div class="post body" data-post-inplace-body>${retried.get('body')}</div>`,
                published_at: 1788782400, datetime: '2026-09-07T12:00:00Z', time: '7 September',
                tags: [], scheduled: false, message: 'Saved',
                ...(creating ? {id: 10, url: '/created', action_url: '/_inplace/post/10', token: 'fixture'} : {})}});
            await first.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            await secondSave.fulfill({status: 409, json: {success: false, message: 'Save conflict'}});
            await second.waitForFunction(() => document.querySelector('.post-inplace-edit-error:not([hidden])'));
            assert.equal(await first.locator('[data-post-inplace-body] audio').getAttribute('src'), currentUrl);
            assert.equal(await first.evaluate(async url => (await fetch(url)).status, currentUrl), 200);
        });
        console.log(`media: a concurrent rename rejects ${creating ? 'creation' : 'an edit'} without losing text, and retry reconciles the attachment`);
    }
    assert.deepEqual(errors, [], 'Concurrency scenarios must not cause uncaught browser errors');
}
