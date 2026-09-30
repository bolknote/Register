import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

export async function runLifecycleRegressions(browser, origin) {
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

    for (const failure of ['quota', 'oversized', 'unavailable']) {
        await withContext(async context => {
            await context.addInitScript(() => {
                const storage = Object.getOwnPropertyDescriptor(window, 'localStorage');
                Object.defineProperty(window, 'localStorage', {get() {
                    if (window.blockStorageReads) throw new DOMException('Storage disabled', 'SecurityError');
                    return storage.get.call(window);
                }});
                const setItem = Storage.prototype.setItem;
                Storage.prototype.setItem = function (key, value) {
                    if (window.blockDraftStorage && key.startsWith('register:post-recovery:')) {
                        throw new DOMException('Storage full', 'QuotaExceededError');
                    }
                    return setItem.call(this, key, value);
                };
            });
            const page = await context.newPage();
            await page.goto(origin + '/recovery.html');
            if (failure === 'unavailable') await page.evaluate(() => { window.blockStorageReads = true; });
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
            if (failure !== 'unavailable') {
                await body.fill('Earlier recoverable body');
                await page.waitForFunction(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                    .list().some(record => record.snapshot.body.includes('Earlier recoverable body')));
            }
            if (failure === 'quota') await page.evaluate(() => { window.blockDraftStorage = true; });
            const latest = failure === 'oversized' ? 'Latest paragraph ' + 'x'.repeat(530000) : 'Latest unsaved paragraph';
            if (failure === 'oversized') {
                // Seed the storage limit payload without timing native editing
                // of half a million unbroken characters in the browser.
                await body.evaluate((element, value) => {
                    element.textContent = value;
                    element.dispatchEvent(new InputEvent('input', {bubbles: true, inputType: 'insertFromPaste'}));
                }, latest);
            } else {
                await body.fill(latest);
            }
            await page.waitForFunction(() => document.querySelector('.post-inplace-status.is-error:not([hidden])'));

            const warned = page.waitForEvent('dialog');
            const reload = page.evaluate(() => { location.reload(); });
            const dialog = await warned;
            assert.equal(dialog.type(), 'beforeunload');
            await dialog.dismiss();
            await reload;
            assert.equal(await body.textContent(), latest, 'Cancelling navigation keeps the newest text editable');

            await page.evaluate(() => { window.blockDraftStorage = window.blockStorageReads = false; });
            await body.fill('Latest text after recovery becomes available');
            const unexpectedDialogs = [];
            page.on('dialog', async dialog => { unexpectedDialogs.push(dialog.type()); await dialog.dismiss(); });
            // Leave immediately: beforeunload must flush changes even before the debounce fires.
            await page.reload();
            assert.deepEqual(unexpectedDialogs, [], 'A successful fresh copy needs no exit warning');
            await page.getByRole('button', {name: 'Restore text', exact: true}).click();
            assert.equal(await body.textContent(), 'Latest text after recovery becomes available');
        });
        console.log(`recovery: native reload protects the latest text after ${failure}, and resumes after storage recovers`);
    }

    for (const kind of ['audio', 'image']) {
        await withContext(async context => {
            const extension = kind === 'audio' ? 'wav' : 'png';
            const oldUrl = `/media/2026.09.06.${extension}`;
            const newUrl = `/media/2026.09.07.${extension}`;
            let serverBody = '<p>Server body 1</p>';
            let saves = 0;
            let reconciliations = 0;
            await context.route('**/recovery-fixture.js', async route => {
                const response = await route.fetch();
                const source = (await response.text()).replace(
                    "const body = creating ? '' : `<p>Server body ${revision}</p>`;",
                    "const body = creating ? '' : " + JSON.stringify(serverBody) + ';',
                );
                await route.fulfill({response, body: source});
            });
            await context.route('**/_inplace/post/9', async route => {
                const data = await formData(route);
                const action = data.get('inplace_action');
                if (action === 'media') {
                    await route.fulfill({json: {success: true, action, kind, media_id: 501,
                        url: oldUrl, name: 'Attachment', width: 1, height: 1}});
                } else if (action === 'media_redate') {
                    assert.equal(data.get('media_ids'), '501');
                    reconciliations++;
                    // The real controller contract is covered by PostInplaceCest:
                    // published uploads return their URL without being renamed again.
                    await route.fulfill({json: {success: true, action, media: [{media_id: 501, url: newUrl, name: 'Attachment'}]}});
                } else {
                    assert.equal(action, 'edit');
                    assert.equal(data.get('uploaded_media_ids'), '');
                    assert.equal(data.get('revision'), String(saves + 1));
                    assert.ok(data.get('body').includes(newUrl));
                    assert.ok(!data.get('body').includes(oldUrl));
                    if (saves > 0) assert.ok(data.get('body').includes('Local addition'));
                    serverBody = data.get('body');
                    saves++;
                    await route.fulfill({json: {success: true, action, title: 'Server title 1', revision: saves + 1,
                        body_html: `<div class="post body" data-post-inplace-body>${serverBody}</div>`,
                        published_at: 1788782400, datetime: '2026-09-07T12:00:00Z', time: '7 September',
                        tags: [], scheduled: false, message: 'Saved'}});
                }
            });
            const first = await context.newPage();
            await first.goto(origin + '/recovery.html');
            await first.getByRole('button', {name: 'Edit', exact: true}).click();
            await first.evaluate(kind => {
                const state = window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
                const range = document.createRange();
                range.selectNodeContents(state.body);
                range.collapse(false);
                const file = kind === 'audio' ? new File(['RIFF'], 'clip.wav', {type: 'audio/wav'})
                    : new File(['fixture'], 'image.png', {type: 'image/png'});
                window.editorTest.insertMediaFiles(state, [file], range);
            }, kind);
            await first.waitForFunction(url => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                .list().some(copy => copy.snapshot.body.includes(url)), oldUrl);
            const second = await context.newPage();
            await second.goto(origin + '/recovery.html');
            await second.getByRole('button', {name: 'Restore text', exact: true}).click();
            await second.evaluate(() => {
                const body = document.querySelector('.post-card.is-editing [data-post-inplace-body]');
                body.focus();
                const range = document.createRange();
                range.selectNodeContents(body.querySelector('p'));
                range.collapse(false);
                getSelection().removeAllRanges();
                getSelection().addRange(range);
            });
            await second.keyboard.type(' Local addition');
            await second.waitForFunction(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                .list().some(copy => copy.snapshot.body.includes('Local addition')));
            await first.locator('.post-inplace-datetime').fill('2026-09-07T15:00');
            await first.getByRole('button', {name: 'Save', exact: true}).click();
            await first.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            await second.goto(origin + '/recovery.html?revision=2');
            await second.getByRole('button', {name: 'Restore text', exact: true}).click();
            const media = second.locator('.post-card [data-post-media-id="501"]');
            assert.equal(await media.getAttribute('src'), oldUrl);
            await second.getByRole('button', {name: 'Save', exact: true}).click();
            await second.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            assert.equal(await media.getAttribute('src'), newUrl);
            assert.equal(saves, 2);
            assert.equal(reconciliations, 2);
        });
        console.log(`recovery: an older ${kind} copy reconciles its URL after another tab renames and publishes the upload`);
    }

    for (const action of ['title', 'tags', 'proofread', 'alt']) {
        await withContext(async context => {
            const page = await context.newPage();
            const ai = holdRequests(page, action === 'alt' ? '**/admin-ai-alt' : '**/admin-ai');
            const saves = holdRequests(page, '**/admin-save');
            await Promise.all([ai.installed, saves.installed]);
            await page.goto(origin + '/admin.html?ai=1&codemirror=1' + (action === 'alt' ? '&alt=1' : ''));
            await page.waitForFunction(() => window.adminEditorReady);
            if (action === 'alt') {
                await page.evaluate(() => window.adminEditor.setValue('<p>Body</p><img src="/image.png" alt="">'));
            }
            const values = () => page.evaluate(() => ({
                title: document.querySelector('[name="title"]').value,
                tags: document.querySelector('[name="tags"]').value,
                body: window.adminEditor.getValue(),
            }));
            const original = await values();
            const dialogs = [];
            page.on('dialog', async dialog => { dialogs.push(dialog.type()); await dialog.dismiss(); });

            for (const succeeds of [false, true]) {
                if (action === 'alt') {
                    await page.evaluate(() => document.dispatchEvent(new CustomEvent('image_inserted.register', {detail: {src: '/image.png'}})));
                } else {
                    await page.locator(`[data-ai-action="${action}"]`).click();
                }
                const pendingAi = await ai.next();
                await page.getByRole('button', {name: 'Save', exact: true}).click();
                const pendingSave = await saves.next();
                const submitted = await formData(pendingSave);
                for (const [field, value] of Object.entries(original)) assert.equal(submitted.get(field), value);
                assert.equal(await page.locator('form').evaluate(form => form.inert), true);
                await pendingAi.fulfill({json: {success: true, result: 'Late generated result'}});
                if (action === 'alt') {
                    await page.waitForFunction(() => !document.querySelector('.ai-image-alt-preview[data-state="generating"]'));
                    assert.equal(await page.getByText('Alt failed', {exact: true}).count(), 0);
                } else {
                    await page.waitForFunction(() => document.getElementById('content-editor-ai-tools').getAttribute('aria-busy') === 'false');
                    assert.equal(await page.locator('#ai-tools-status').textContent(), '');
                }
                assert.deepEqual(await values(), original, 'Late AI cannot dirty a submitted create form');
                if (succeeds) {
                    await pendingSave.continue({url: origin + '/admin-save-redirect'});
                    await page.waitForURL('**/admin.html?id=10');
                } else {
                    await pendingSave.fulfill({status: 422, json: {errors: ['Invalid article']}});
                    await page.waitForFunction(() => !document.querySelector('form').inert);
                    assert.equal(await page.locator('[name="title"]').evaluate(input => input.readOnly), false);
                }
            }
            assert.deepEqual(dialogs, [], 'The saved creation must redirect without an unsaved-changes warning');
            assert.equal(await page.evaluate(() => window.readAdminDraft('new')), null);
        });
        console.log(`save: late admin AI ${action} is cancelled; failed saves unlock and successful creation redirects`);
    }
    assert.deepEqual(errors, [], 'Lifecycle changes must not cause uncaught browser errors');
}
