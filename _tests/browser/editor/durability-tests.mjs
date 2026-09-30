import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

async function withContext(browser, run) {
    const context = await browser.newContext();
    const errors = [];
    context.on('page', page => {
        page.setDefaultTimeout(10000);
        page.on('pageerror', error => errors.push(String(error)));
        page.on('dialog', dialog => dialog.accept());
    });
    try { await run(context); assert.deepEqual(errors, []); }
    finally { await context.close(); }
}

export async function runDurabilityRegressions(browser, origin) {
    for (const codemirror of [false, true]) {
        await withContext(browser, async context => {
            await context.route('**/admin.html?*', async route => {
                const response = await route.fetch();
                const revision = new URL(route.request().url()).searchParams.get('revision') || '1';
                await route.fulfill({response, body: (await response.text())
                    .replace('name="revision" type="hidden" value="1"', `name="revision" type="hidden" value="${revision}"`)
                    .replace('<button type="submit">Save</button>', '<label>Template <input name="template" value="default"></label>'
                        + '<label>Description <input name="meta_description" value="Saved description"></label>'
                        + '<label>Publication date <input name="scheduled_at" type="datetime-local" value="2026-09-30T12:00"></label>'
                        + '<label>Published <input name="published" type="checkbox" checked></label>'
                        + '<input name="csrf_token" type="hidden" value="never-store-this-token"><button type="submit">Save</button>')});
            });
            const first = await context.newPage();
            const url = origin + '/admin.html?id=9' + (codemirror ? '&codemirror=1' : '');
            await first.goto(url);
            await first.waitForFunction(() => window.adminEditorReady);
            await first.getByLabel('Title', {exact: true}).fill('Unfinished title');
            if (codemirror) await first.evaluate(() => window.adminEditor.setValue('Unfinished body'));
            else await first.locator('[name="body"]').fill('Unfinished body');
            await first.getByLabel('Tags', {exact: true}).fill('Unfinished tag');
            await first.getByLabel('Template', {exact: true}).fill('different');
            await first.getByLabel('Description', {exact: true}).fill('Unfinished description');
            await first.getByLabel('Publication date', {exact: true}).fill('2026-10-01T14:15');
            await first.getByLabel('Published', {exact: true}).uncheck();
            const record = await first.evaluate(() => window.adminDrafts.list('9')[0]);
            assert.equal(record.revision, 1);
            assert.equal(JSON.stringify(record).includes('never-store-this-token'), false);
            assert.equal(record.snapshot.some(([name]) => name === 'revision'), false);

            const second = await context.newPage();
            await second.goto(url + '&revision=2');
            await second.waitForFunction(() => window.adminEditorReady);
            assert.equal(await second.getByLabel('Title', {exact: true}).inputValue(), 'Server title');
            assert.equal(await second.locator('[name="body"]').inputValue(), 'Server body');
            assert.match(await second.locator('.editor-recovery').textContent(), /server version changed/iu);
            await second.getByRole('button', {name: 'Restore draft', exact: true}).click();
            assert.equal(await second.getByLabel('Title', {exact: true}).inputValue(), 'Unfinished title');
            assert.equal(await second.locator('[name="body"]').inputValue(), 'Unfinished body');
            assert.equal(await second.getByLabel('Tags', {exact: true}).inputValue(), 'Unfinished tag');
            assert.equal(await second.getByLabel('Template', {exact: true}).inputValue(), 'different');
            assert.equal(await second.getByLabel('Description', {exact: true}).inputValue(), 'Unfinished description');
            assert.equal(await second.getByLabel('Publication date', {exact: true}).inputValue(), '2026-10-01T14:15');
            assert.equal(await second.getByLabel('Published', {exact: true}).isChecked(), false);
            assert.equal(await second.locator('[name="revision"]').inputValue(), '2');
            await first.getByLabel('Title', {exact: true}).fill('Independent first tab');
            await second.getByLabel('Title', {exact: true}).fill('Independent second tab');
            assert.equal(await first.evaluate(() => window.adminDrafts.list('9').length), 2);
            for (const namespace of ['&account=2', '&scope=/another']) {
                const isolated = await context.newPage();
                await isolated.goto(url + namespace);
                await isolated.waitForFunction(() => window.adminEditorReady);
                assert.equal(await isolated.locator('.editor-recovery').count(), 0);
                assert.equal(await isolated.getByLabel('Title', {exact: true}).inputValue(), 'Server title');
                await isolated.close();
            }
        });
        console.log(`durability: complete admin recovery is explicit, revision-aware, per-tab and account/site-scoped (${codemirror ? 'CodeMirror' : 'textarea'})`);

        await withContext(browser, async context => {
            const page = await context.newPage();
            const requests = holdRequests(page, '**/admin-save?id=9');
            await requests.installed;
            await page.goto(origin + '/admin.html?id=9&timeout=200' + (codemirror ? '&codemirror=1' : ''));
            await page.waitForFunction(() => window.adminEditorReady);
            if (codemirror) await page.evaluate(() => window.adminEditor.setValue('Retained after timeout'));
            else await page.locator('[name="body"]').fill('Retained after timeout');
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            await requests.next();
            await requests.next();
            await page.waitForFunction(() => document.getElementById('error').textContent.includes('did not respond in time'));
            assert.equal(await page.locator('form').evaluate(form => form.inert), false);
            assert.equal(await page.evaluate(() => window.readAdminDraft('9')), 'Retained after timeout');
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            await (await requests.next()).fulfill({json: {revision: 2, urlStatus: 'ok', urlTitle: '', url: '/post'}});
            await page.waitForFunction(() => document.querySelector('[name="revision"]').value === '2');
            assert.equal(await page.evaluate(() => window.readAdminDraft('9')), null);
        });
        console.log(`durability: stalled admin save unlocks, keeps the draft and permits a successful retry (${codemirror ? 'CodeMirror' : 'textarea'})`);
    }

    await withContext(browser, async context => {
        const page = await context.newPage();
        await page.emulateMedia({reducedMotion: 'reduce'});
        await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        await page.route('**/durability.png', route => route.fulfill({contentType: 'image/png',
            body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==', 'base64')}));
        const optimizer = holdRequests(page, '**/image-optimizer/js/optimizer.js');
        const requests = holdRequests(page, '**/_inplace/post/9');
        await optimizer.installed;
        await requests.installed;
        await page.goto(origin + '/recovery.html?timeout=200');
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        const card = page.locator('.post-card.is-editing');
        const body = card.locator('[data-post-inplace-body]');
        await body.fill('Text kept while image preparation stalls');
        await body.evaluate(element => {
            const range = document.createRange();
            range.selectNodeContents(element);
            range.collapse(false);
            getSelection().removeAllRanges();
            getSelection().addRange(range);
            const data = new DataTransfer();
            data.items.add(new File(['fixture'], 'image.png', {type: 'image/png'}));
            const event = new Event('paste', {bubbles: true, cancelable: true});
            Object.defineProperty(event, 'clipboardData', {value: data});
            element.dispatchEvent(event);
        });
        const loading = await optimizer.next();
        await card.getByRole('button', {name: 'Save', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.post-inplace-error:not([hidden])')?.textContent.includes('did not respond in time'));
        assert.equal(await card.evaluate(element => element.inert), false);
        assert.equal(requests.count, 0, 'A stalled preparation must not send a partial post');
        assert.equal(await page.evaluate(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
            .list().some(copy => copy.snapshot.body.includes('Text kept while image preparation stalls'))), true);
        await body.press('End');
        await page.keyboard.insertText(' More text');
        assert.match(await body.textContent(), /More text/u);

        // When processing resumes, the author can retry and include the attachment.
        await loading.continue();
        await (await requests.next()).fulfill({json: {
            success: true, action: 'media', kind: 'image', media_id: 42, url: '/durability.png', width: 1, height: 1,
        }});
        await page.waitForFunction(() => window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing')).mediaUploads.size === 0);
        await card.getByRole('button', {name: 'Save', exact: true}).click();
        const redate = await requests.next();
        assert.equal((await formData(redate)).get('inplace_action'), 'media_redate');
        await redate.fulfill({json: {success: true, media: [{media_id: 42, url: '/durability.png'}]}});
        const save = await requests.next();
        const sent = await formData(save);
        assert.match(sent.get('body'), /More text/u);
        assert.match(sent.get('body'), /data-post-media-id="42"/u);
        await save.fulfill({json: {success: true, action: 'edit', revision: 2, title: 'Server title 1', tags: [],
            published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September', scheduled: false,
            body_html: `<div class="post body" data-post-inplace-body>${sent.get('body')}</div>`}});
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
    });
    console.log('durability: stalled image preparation releases the save, keeps text editable and permits a complete later retry');

    await withContext(browser, async context => {
        const page = await context.newPage();
        await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        const requests = holdRequests(page, '**/_inplace/post/new');
        await requests.installed;
        await page.goto(origin + '/recovery.html?timeout=200');
        await page.getByRole('button', {name: 'New post', exact: true}).click();
        const card = page.locator('[data-post-creating]');
        await page.keyboard.insertText('Original title');
        await card.locator('[data-post-inplace-body]').fill('Original creation');
        await card.getByRole('button', {name: 'Save', exact: true}).click();
        const first = await formData(await requests.next());
        const retry = await formData(await requests.next());
        assert.match(first.get('request_id'), /^[a-zA-Z0-9_-]{16,80}$/u);
        assert.equal(retry.get('request_id'), first.get('request_id'));
        await page.waitForFunction(() => document.querySelector('.post-inplace-error:not([hidden])')?.textContent.includes('did not respond in time'));
        assert.equal(await card.evaluate(card => card.inert), false);
        await page.reload();
        await page.getByRole('button', {name: 'Restore text', exact: true}).click();
        await card.locator('[data-post-inplace-body]').fill('Changes after the lost response');
        await card.getByRole('button', {name: 'Save', exact: true}).click();
        const restoredRequest = await requests.next();
        const restored = await formData(restoredRequest);
        assert.equal(restored.get('request_id'), first.get('request_id'));
        // The first save committed before its answer was lost. Later draft changes stay editable.
        await restoredRequest.fulfill({json: {
            success: true, action: 'create', replayed: true, request_matched: false,
            id: 12, revision: 1, title: 'Original title', url: '/created', action_url: '/_inplace/post/12', token: 'fixture',
            published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September', tags: [],
            body_html: '<div class="post body" data-post-inplace-body><p>Original creation</p></div>',
        }});
        await page.waitForFunction(() => document.querySelector('.post-card[data-post-id="12"].is-editing'));
        assert.equal(await page.locator('.post-card[data-post-id="12"] [data-post-inplace-body]').textContent(), 'Changes after the lost response');
        assert.equal(await page.locator('.post-card[data-post-id="12"] form').getAttribute('action'), '/_inplace/post/12');
    });
    console.log('durability: timed-out creation retains its operation across recovery and preserves later edits after server reconciliation');
}
