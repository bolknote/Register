import assert from 'node:assert/strict';
import {formData} from './save-tests.mjs';

async function upload(page, kind, mediaId) {
    await page.evaluate(kind => {
        const state = window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
        const range = document.createRange();
        range.selectNodeContents(state.body);
        range.collapse(false);
        const file = kind === 'audio' ? new File(['RIFF'], 'clip.wav', {type: 'audio/wav'})
            : new File(['fixture'], 'image.png', {type: 'image/png'});
        window.editorTest.insertMediaFiles(state, [file], range);
    }, kind);
    await page.waitForFunction(mediaId => {
        const state = window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
        return state.mediaUploads.size === 0 && state.body.querySelector(`[data-post-media-id="${mediaId}"]`);
    }, mediaId);
}

async function allowBlockingRecoveryStorage(context) {
    await context.addInitScript(() => {
        const setItem = Storage.prototype.setItem;
        Storage.prototype.setItem = function (key, value) {
            if (window.blockDraftStorage && key.startsWith('register:post-recovery:')) {
                throw new DOMException('Storage full', 'QuotaExceededError');
            }
            return setItem.call(this, key, value);
        };
    });
}

export async function runRecoveryPreviewRegressions(browser, origin) {
    const errors = [];
    async function withContext(run) {
        const context = await browser.newContext();
        context.on('page', page => {
            page.setDefaultTimeout(10000);
            page.on('pageerror', error => errors.push(String(error)));
        });
        await context.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        try { await run(context); }
        finally { await context.close(); }
    }

    for (const creating of [false, true]) {
        await withContext(async context => {
            await allowBlockingRecoveryStorage(context);
            const files = new Set();
            let nextMediaId = 501;
            await context.route('**/clip-*.wav', route => {
                const id = Number(new URL(route.request().url()).pathname.match(/clip-(\d+)\.wav/u)[1]);
                return route.fulfill({status: files.has(id) ? 200 : 404, contentType: 'audio/wav', body: 'RIFF'});
            });
            await context.route('**/_inplace/post/*', async route => {
                const data = await formData(route);
                const action = data.get('inplace_action');
                if (action === 'media') {
                    const mediaId = nextMediaId++;
                    files.add(mediaId);
                    await route.fulfill({json: {success: true, action, kind: 'audio', media_id: mediaId,
                        url: `/clip-${mediaId}.wav`, name: 'Recording'}});
                } else if (action === 'media_redate') {
                    // Recovery protection must not stop pending files being reconciled before save.
                    assert.equal(data.get('media_ids'), '501,502');
                    await route.fulfill({json: {success: true, action, media: [...files].map(id => ({
                        media_id: id, url: `/clip-${id}.wav`, name: 'Recording',
                    }))}});
                } else {
                    assert.equal(action, creating ? 'create' : 'edit');
                    assert.equal(data.get('uploaded_media_ids'), '502');
                    assert.ok(!data.get('body').includes('<audio'));
                    // Match the server's cleanup contract; the PHP integration test exercises it directly.
                    for (const id of data.get('uploaded_media_ids').split(',').filter(Boolean).map(Number)) {
                        files.delete(id);
                    }
                    await route.fulfill({json: {
                        success: true, action, title: data.get('title'), revision: 2,
                        body_html: `<div class="post body" data-post-inplace-body>${data.get('body')}</div>`,
                        published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
                        tags: [], scheduled: false, message: 'Saved',
                        ...(creating ? {id: 10, url: '/created', action_url: '/_inplace/post/10', token: 'fixture'} : {}),
                    }});
                }
            });
            const original = await context.newPage();
            await original.goto(origin + '/recovery.html');
            await original.getByRole('button', {name: creating ? 'New post' : 'Edit', exact: true}).click();
            if (creating) await original.keyboard.type('New draft');
            const body = original.locator('.post-card.is-editing [data-post-inplace-body]');
            await body.fill('Text before uploads');
            await upload(original, 'audio', 501);
            await original.waitForFunction(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                .list().some(copy => copy.snapshot.body.includes('clip-501.wav')));

            const restored = await context.newPage();
            await restored.goto(origin + '/recovery.html');
            await restored.getByRole('button', {name: 'Restore text', exact: true}).click();
            await original.evaluate(() => { window.blockDraftStorage = true; });
            await upload(original, 'audio', 502);
            await body.press('Meta+z');
            await body.press('Meta+z');
            assert.equal(await body.locator('audio').count(), 0);
            await original.locator('.post-card.is-editing [data-post-inplace-title]').fill('Changed title');
            await original.getByRole('button', {name: 'Save', exact: true}).click();
            await original.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            assert.equal(await restored.locator('.post-card.is-editing audio').getAttribute('src'), '/clip-501.wav');
            assert.equal(await restored.evaluate(async () => (await fetch('/clip-501.wav')).status), 200);
            assert.equal(await restored.evaluate(async () => (await fetch('/clip-502.wav')).status), 404);
            assert.ok(await restored.evaluate(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                .list().some(copy => copy.snapshot.body.includes('clip-501.wav'))));
        });
        console.log(`recovery: ${creating ? 'creating' : 'saving'} after undo preserves shared uploads and cleans private uploads`);
    }

    for (const cancelOriginal of [false, true]) {
        for (const deleteCopy of [false, true]) {
            await withContext(async context => {
                await allowBlockingRecoveryStorage(context);
                const files = new Set();
                const releases = [];
                let nextMediaId = 501;
                await context.route('**/clip-*.wav', route => {
                    const id = Number(new URL(route.request().url()).pathname.match(/clip-(\d+)\.wav/u)[1]);
                    return route.fulfill({status: files.has(id) ? 200 : 404, contentType: 'audio/wav', body: 'RIFF'});
                });
                await context.route('**/_inplace/post/9', async route => {
                    const data = await formData(route);
                    const action = data.get('inplace_action');
                    if (action === 'media') {
                        const mediaId = nextMediaId++;
                        files.add(mediaId);
                        await route.fulfill({json: {
                            success: true, action, kind: 'audio', media_id: mediaId,
                            url: `/clip-${mediaId}.wav`, name: 'Recording',
                        }});
                    } else if (action === 'media_release') {
                        const ids = data.get('media_ids').split(',').map(Number);
                        ids.forEach(id => files.delete(id));
                        releases.push(...ids);
                        await route.fulfill({json: {success: true, action}});
                    } else if (action === 'media_redate') {
                        await route.fulfill({json: {success: true, action, media: [...files].map(id => ({
                            media_id: id, url: `/clip-${id}.wav`, name: 'Recording',
                        }))}});
                    } else {
                        assert.equal(action, 'edit');
                        assert.equal(data.get('uploaded_media_ids'), '');
                        assert.ok(data.get('body').includes('/clip-501.wav'));
                        await route.fulfill({json: {
                            success: true, action, title: 'Server title 1', revision: 2,
                            body_html: `<div class="post body" data-post-inplace-body>${data.get('body')}</div>`,
                            published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
                            tags: [], scheduled: false, message: 'Saved',
                        }});
                    }
                });
                const original = await context.newPage();
                await original.goto(origin + '/recovery.html');
                await original.getByRole('button', {name: 'Edit', exact: true}).click();
                await upload(original, 'audio', 501);
                await original.waitForFunction(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                    .list().some(copy => copy.snapshot.mediaIds.includes(501)));

                const restored = await context.newPage();
                await restored.goto(origin + '/recovery.html');
                await restored.getByRole('button', {name: 'Restore text', exact: true}).click();
                assert.equal(await restored.locator('.post-card.is-editing audio').getAttribute('src'), '/clip-501.wav');
                if (deleteCopy) {
                    // A third tab can delete the copy while both editors still use its file.
                    // Block writes so a visibility change cannot recreate it during this check.
                    await original.evaluate(() => { window.blockDraftStorage = true; });
                    await restored.evaluate(() => { window.blockDraftStorage = true; });
                    const third = await context.newPage();
                    await third.goto(origin + '/recovery.html');
                    third.on('dialog', dialog => dialog.accept());
                    const discard = third.getByRole('button', {name: 'Delete local copy', exact: true});
                    while (await discard.count()) await discard.first().click();
                    assert.equal(await third.evaluate(() => localStorage.length), 0);
                    await third.close();
                }
                const cancelled = cancelOriginal ? original : restored;
                const remaining = cancelOriginal ? restored : original;
                // A later upload that cannot enter recovery is private and should still be released.
                await cancelled.evaluate(() => { window.blockDraftStorage = true; });
                await upload(cancelled, 'audio', 502);
                const released = cancelled.waitForResponse(response => response.url().endsWith('/_inplace/post/9')
                    && response.request().postData()?.includes('media_release'));
                cancelled.once('dialog', dialog => dialog.accept());
                await cancelled.getByRole('button', {name: 'Cancel', exact: true}).click();
                await released;
                assert.deepEqual(releases, [502]);
                assert.equal(await remaining.evaluate(async () => (await fetch('/clip-501.wav')).status), 200);
                assert.deepEqual(await remaining.evaluate(() => [...window.editorTest.editorStates
                    .get(document.querySelector('.post-card.is-editing')).uploadedMediaIds]), [501]);
                await remaining.getByRole('button', {name: 'Save', exact: true}).click();
                await remaining.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
                assert.equal(await remaining.locator('[data-post-inplace-body] audio').getAttribute('src'), '/clip-501.wav');
                assert.deepEqual(releases, [502]);
            });
            console.log(`recovery: cancelling the ${cancelOriginal ? 'original' : 'restored'} tab preserves shared media ${deleteCopy ? 'after deleting the copy' : 'with a retained copy'}, releases private uploads and allows saving`);
        }
    }

    await withContext(async context => {
        await context.route('**/image.png', route => route.fulfill({contentType: 'image/svg+xml', body:
            '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300"><rect width="400" height="300" fill="gray"/></svg>',
        }));
        await context.route('**/_inplace/post/9', route => route.fulfill({json: {
            success: true, action: 'media', kind: 'image', media_id: 501, url: '/image.png', width: 400, height: 300,
        }}));
        const page = await context.newPage();
        await page.goto(origin + '/recovery.html');
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        await upload(page, 'image', 501);
        const editCaption = async () => {
            await page.locator('.post-card.is-editing img').click({button: 'right'});
            await page.locator('.post-editor-image-panel [data-context-action="edit-image-caption"]').click();
        };
        const commit = () => page.locator('.post-media-caption-toolbar [data-caption-action="commit"]').click();
        const caption = page.locator('.post-card.is-editing .post-media-overlay-caption');
        await editCaption();
        await page.locator('.is-editing-caption').fill('My overlay caption');
        await page.locator('.post-media-caption-toolbar [data-caption-font="serif"]').click();
        await page.locator('.post-media-caption-toolbar [data-caption-background="light"]').click();
        await commit();
        await page.waitForFunction(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
            .list().some(copy => copy.snapshot.body.includes('data-caption-font="serif"')
                && copy.snapshot.body.includes('data-caption-background="light"') && copy.snapshot.body.includes('My overlay caption')));
        await page.reload();
        await page.getByRole('button', {name: 'Restore text', exact: true}).click();
        assert.equal(await caption.textContent(), 'My overlay caption');
        assert.equal(await caption.getAttribute('data-caption-font'), 'serif');
        assert.equal(await caption.getAttribute('data-caption-background'), 'light');
        assert.equal(await page.locator('.post-card.is-editing [data-post-media-overlay]').getAttribute('role'), 'figure');
        await editCaption();
        assert.equal(await page.locator('.is-editing-caption').textContent(), 'My overlay caption');
        await page.locator('.is-editing-caption').fill('Edited restored caption');
        await commit();
        assert.equal(await caption.textContent(), 'Edited restored caption');
        assert.equal(await page.locator('.post-card.is-editing .post-media-overlay').count(), 1);
        assert.equal(await caption.getAttribute('data-caption-font'), 'serif');
        assert.equal(await caption.getAttribute('data-caption-background'), 'light');
    });
    console.log('recovery: overlay identity, text, font, background and figure role survive reload and further editing');

    for (const codemirror of [false, true]) {
        await withContext(async context => {
            await context.route('**/admin-ajax*', route => route.fulfill({json: {success: true,
                template: '<!doctype html><html><body><!-- register_title --><!-- register_text --></body></html>',
            }}));
            await context.route('**/admin-ai', route => route.fulfill({json: {success: true, result: 'Suggested title'}}));
            const page = await context.newPage();
            await page.clock.install();
            await page.goto(origin + '/admin.html?id=9&preview=1&ai=1' + (codemirror ? '&codemirror=1' : ''));
            await page.waitForFunction(() => window.adminEditorReady);
            const preview = page.frameLocator('#body-preview-frame').locator('#preview-header-wrapper');
            await preview.waitFor();
            assert.equal(await preview.textContent(), 'Server title');
            for (const title of ['Edited title', 'Another title']) {
                await page.locator('[name="title"]').fill(title);
                await page.clock.fastForward(400);
                assert.equal(await preview.textContent(), title);
            }
            // The periodic fallback also detects integrations that change the value without an event.
            await page.locator('[name="title"]').evaluate(input => { input.value = 'Periodic title'; });
            await page.clock.fastForward(5000);
            assert.equal(await preview.textContent(), 'Periodic title');
            if (codemirror) {
                await page.getByRole('button', {name: 'Suggest title', exact: true}).click();
                await page.waitForFunction(() => document.getElementById('content-editor-ai-tools').getAttribute('aria-busy') === 'false');
                await page.clock.fastForward(400);
                assert.equal(await preview.textContent(), 'Suggested title');
            }
            assert.equal(await page.frameLocator('#body-preview-frame').locator('#preview-text-wrapper').textContent(), 'Server body');
            assert.equal(await page.locator('[name="body"]').inputValue(), 'Server body');
            assert.equal(await page.evaluate(() => window.readAdminDraft('9')), 'Server body');
        });
        console.log(`preview: title-only edits update live and on the periodic check without changing the body (${codemirror ? 'CodeMirror and AI' : 'textarea'})`);
    }
    assert.deepEqual(errors, []);
}
