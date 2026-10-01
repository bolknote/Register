import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const draftTarget = '9';
const savedAdmin = {revision: 2, urlStatus: 'ok', urlTitle: '', url: '/post'};

async function closeTab(page) {
    page.on('dialog', dialog => dialog.accept());
    const closed = page.waitForEvent('close');
    await page.close({runBeforeUnload: true});
    await closed;
}

export async function runReviewRegressions(browser, origin) {
    const errors = [];
    async function withContext(run) {
        const context = await browser.newContext({timezoneId: 'Europe/Moscow'});
        context.on('page', page => {
            page.on('pageerror', error => errors.push(String(error)));
            page.setDefaultTimeout(10000);
        });
        try { await run(context); }
        finally { await context.close(); }
    }

    for (const failure of ['oversized', 'storage']) {
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
            const page = await context.newPage();
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            await page.goto(origin + '/recovery.html');
            const card = page.locator('.post-card[data-post-id="9"]');
            const body = card.locator('[data-post-inplace-body]');
            await card.getByRole('button', {name: 'Edit', exact: true}).click();
            await body.fill('Last recoverable text');
            await page.waitForFunction(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                .list().some(copy => copy.snapshot.body.includes('Last recoverable text')));
            if (failure === 'storage') await page.evaluate(() => { window.blockDraftStorage = true; });
            const text = failure === 'oversized' ? 'x'.repeat(530000) : 'Latest text while storage is full';
            if (failure === 'oversized') {
                // This case exercises the storage size limit, avoiding a slow
                // native insertion of half a million unbroken characters.
                await body.evaluate((element, value) => {
                    element.textContent = value;
                    element.dispatchEvent(new InputEvent('input', {bubbles: true, inputType: 'insertFromPaste'}));
                }, text);
            } else {
                await body.fill(text);
            }
            await page.waitForFunction(() => document.querySelector('.post-inplace-status.is-error:not([hidden])'));
            await page.getByRole('button', {name: 'New post', exact: true}).click();
            assert.equal(await page.locator('.post-card.is-editing').getAttribute('data-post-id'), '9');
            assert.equal(await page.locator('[data-post-creating]').count(), 0);
            assert.equal(await body.textContent(), text);
            assert.match(await card.locator('.post-inplace-status').textContent(), /local copy/u);

            // Explicit discard still works when recovery cannot write anything.
            await card.getByRole('button', {name: 'Cancel', exact: true}).click();
            await page.getByRole('dialog', {name: 'Discard unsaved changes'})
                .getByRole('button', {name: 'Continue editing'}).click();
            assert.equal(await body.textContent(), text);
            await card.getByRole('button', {name: 'Cancel', exact: true}).click();
            await page.getByRole('dialog', {name: 'Discard unsaved changes'})
                .getByRole('button', {name: 'Discard changes'}).click();
            assert.equal(await body.textContent(), 'Server body 1');
            assert.equal(await page.evaluate(() => localStorage.length), 0);

            // A fresh, successfully persisted copy permits switching and restoration.
            await page.evaluate(() => { window.blockDraftStorage = false; });
            await card.getByRole('button', {name: 'Edit', exact: true}).click();
            await body.fill('Text retained while switching');
            await page.getByRole('button', {name: 'New post', exact: true}).click();
            assert.equal(await page.locator('.post-card.is-editing').getAttribute('data-post-id'), '0');
            await page.getByRole('button', {name: 'Restore text', exact: true}).click();
            assert.equal(await page.locator('.post-card.is-editing').getAttribute('data-post-id'), '9');
            assert.equal(await body.textContent(), 'Text retained while switching');
        });
        console.log(`recovery: ${failure} copies block editor switching; explicit discard and ordinary restoration work`);
    }

    for (const codemirror of [false, true]) {
        for (const scenario of ['untouched', 'older', 'saving']) {
            await withContext(async context => {
                const older = await context.newPage();
                const newer = await context.newPage();
                const url = origin + '/admin.html?id=9' + (codemirror ? '&codemirror=1' : '');
                for (const page of [older, newer]) {
                    await page.goto(url);
                    await page.waitForFunction(() => window.adminEditorReady);
                }
                const setText = (page, text) => codemirror
                    ? page.evaluate(text => window.adminEditor.setValue(text), text)
                    : page.locator('textarea').fill(text);
                let requests;
                if (scenario !== 'untouched') await setText(older, 'Earlier text');
                if (scenario === 'saving') {
                    requests = holdRequests(older, '**/admin-save?id=9');
                    await requests.installed;
                    await older.getByRole('button', {name: 'Save', exact: true}).click();
                }
                await setText(newer, 'Latest unsaved text');
                // WebKit can propagate localStorage between page processes
                // after the editing event completes. Observe that propagation
                // before testing whether the older tab can rewind the copy.
                await older.waitForFunction(key => window.readAdminDraft(key) === 'Latest unsaved text', draftTarget);
                if (requests) {
                    await (await requests.next()).fulfill({json: savedAdmin});
                    await older.waitForFunction(() => document.querySelector('[name="revision"]').value === '2');
                }
                assert.equal(await older.evaluate(key => window.readAdminDraft(key), draftTarget), 'Latest unsaved text');
                await closeTab(newer);
                await closeTab(older);
                const reopened = await context.newPage();
                await reopened.goto(url);
                await reopened.waitForFunction(() => window.adminEditorReady);
                assert.equal(await reopened.locator('textarea').inputValue(), 'Server body');
                await reopened.getByRole('button', {name: 'Restore draft', exact: true}).first().click();
                assert.equal(await reopened.locator('textarea').inputValue(), 'Latest unsaved text');
                assert.equal(await reopened.evaluate(key => window.readAdminDraft(key), draftTarget), 'Latest unsaved text');
            });
            console.log(`recovery: ${scenario} admin tab cannot erase or rewind another tab's copy (${codemirror ? 'CodeMirror' : 'textarea'})`);
        }
    }

    for (const admin of [false, true]) {
        for (const action of ['title', 'tags']) {
            await withContext(async context => {
                const page = await context.newPage();
                await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
                const requests = holdRequests(page, admin ? '**/admin-ai' : '**/_inplace/post/9');
                await requests.installed;
                await page.goto(origin + (admin ? '/admin.html?id=9&codemirror=1&ai=1' : '/recovery.html'));
                if (admin) await page.waitForFunction(() => window.adminEditorReady);
                else await page.getByRole('button', {name: 'Edit', exact: true}).click();
                const start = async () => {
                    if (!admin) await page.locator('.post-card.is-editing [data-post-inplace-body]').click({button: 'right'});
                    await page.locator(admin ? `[data-ai-action="${action}"]` : `[data-context-ai-action="${action}"]`).click();
                    return requests.next();
                };
                const target = admin ? page.locator(`[name="${action}"]`)
                    : page.locator(action === 'title' ? '.post-card.is-editing [data-post-inplace-title]' : '.post-tags-text-input');
                const readTarget = () => admin ? target.inputValue()
                    : action === 'title' ? target.textContent()
                        : page.evaluate(() => window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing')).tagEditor.snapshot());
                const settle = () => page.waitForFunction(admin => admin
                    ? document.getElementById('content-editor-ai-tools').getAttribute('aria-busy') === 'false'
                    : !document.querySelector('.post-card.is-ai-working'), admin);
                const response = result => ({json: {success: true, action: 'ai', ai_action: action, result}});
                const pending = await start();
                await target.fill('My manual change');
                const manual = await readTarget();
                await pending.fulfill(response('Obsolete suggestion'));
                await settle();
                assert.equal(await readTarget(), manual);
                assert.match(await page.locator(admin ? '#ai-tools-status' : '.post-inplace-status').textContent(), /source text has changed/u);
                await (await start()).fulfill(response('Accepted suggestion'));
                await settle();
                assert.equal(await readTarget(), 'Accepted suggestion');
            });
            console.log(`AI: ${admin ? 'admin' : 'public'} ${action} preserves later typing and accepts an unchanged target`);
        }
    }

    for (const kind of ['audio', 'image']) {
        await withContext(async context => {
            const page = await context.newPage();
            const oldUrl = kind === 'audio' ? '/2026.09.06.wav' : '/2026.09.06.png';
            const newUrl = kind === 'audio' ? '/2026.09.07.wav' : '/2026.09.07.png';
            const selector = kind === 'audio' ? 'audio' : 'img';
            let renamed = false;
            await page.route('**/2026.*', route => route.fulfill({
                status: route.request().url().endsWith(renamed ? newUrl : oldUrl) ? 200 : 404,
                contentType: kind === 'audio' ? 'audio/wav' : 'image/png',
                body: kind === 'audio' ? 'RIFF' : Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==', 'base64'),
            }));
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            const requests = holdRequests(page, '**/_inplace/post/9');
            await requests.installed;
            await page.goto(origin + '/recovery.html');
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            await page.evaluate(kind => {
                const state = window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
                const range = document.createRange();
                range.selectNodeContents(state.body);
                range.collapse(false);
                const file = kind === 'audio' ? new File(['RIFF'], 'clip.wav', {type: 'audio/wav'})
                    : new File(['fixture'], 'image.png', {type: 'image/png'});
                window.editorTest.insertMediaFiles(state, [file], range);
            }, kind);
            await (await requests.next()).fulfill({json: {
                success: true, action: 'media', kind, media_id: 501, url: oldUrl, name: 'Original name', width: 1, height: 1,
            }});
            await page.waitForFunction(() => window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing')).mediaUploads.size === 0);
            await page.evaluate(() => {
                const body = document.querySelector('.post-card.is-editing [data-post-inplace-body]');
                body.focus();
                const range = document.createRange();
                range.selectNodeContents(body.querySelector('p'));
                range.collapse(false);
                getSelection().removeAllRanges();
                getSelection().addRange(range);
            });
            await page.keyboard.type(' Added text');
            const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
            await body.press('Meta+z');
            assert.ok(!(await body.textContent()).includes('Added text'));
            await page.locator('.post-inplace-datetime').fill('2026-09-07T15:00');
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            const redate = await requests.next();
            assert.equal((await formData(redate)).get('inplace_action'), 'media_redate');
            renamed = true;
            const renamedPayload = {success: true, action: 'media_redate', media: [{media_id: 501, url: newUrl, name: 'New name'}]};
            await redate.fulfill({json: renamedPayload});
            await (await requests.next()).fulfill({status: 409, json: {success: false, message: 'Revision conflict'}});
            await page.waitForFunction(() => document.querySelector('.post-inplace-edit-error:not([hidden])')?.textContent.includes('Revision conflict'));
            for (const redo of [true, false, true]) {
                await body.press(redo ? 'Meta+Shift+z' : 'Meta+z');
                assert.equal((await body.textContent()).includes('Added text'), redo);
                assert.equal(await body.locator(selector).getAttribute('src'), newUrl);
                if (kind === 'audio') assert.equal(await body.locator(selector).getAttribute('data-title'), 'New name');
            }
            await page.waitForFunction(url => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                .list().some(copy => copy.snapshot.body.includes(url)), newUrl);
            assert.equal(await page.evaluate(async selector => (await fetch(document.querySelector('.post-card.is-editing ' + selector).getAttribute('src'))).status, selector), 200);
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            await (await requests.next()).fulfill({json: renamedPayload});
            const retry = await requests.next();
            const data = await formData(retry);
            assert.ok(data.get('body').includes(newUrl));
            assert.ok(!data.get('body').includes(oldUrl));
            await retry.fulfill({json: {
                success: true, action: 'edit', title: 'Server title 1', revision: 2,
                body_html: `<div class="post body" data-post-inplace-body>${data.get('body')}</div>`,
                published_at: 1788782400, datetime: '2026-09-07T12:00:00Z', time: '7 September',
                tags: [], scheduled: false, message: 'Saved',
            }});
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            assert.equal(await page.evaluate(() => localStorage.length), 0);
        });
        console.log(`media: ${kind} renaming updates undo, redo, recovery and a retried save after conflict`);
    }
    assert.deepEqual(errors, []);
}
