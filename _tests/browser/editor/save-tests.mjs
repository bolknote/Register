import assert from 'node:assert/strict';

async function formData(route) {
    const request = route.request();
    return new Request(request.url(), {
        method: 'POST', headers: request.headers(), body: request.postDataBuffer(),
    }).formData();
}

function holdRequests(page, pattern) {
    const requests = [];
    let notify;
    const installed = page.route(pattern, route => {
        requests.push(route);
        notify?.();
    });
    return {
        installed,
        async next() {
            if (requests.length === 0) {
                let timer;
                try {
                    await new Promise((resolve, reject) => {
                        notify = resolve;
                        timer = setTimeout(() => reject(new Error(`Missing request: ${pattern}`)), 5000);
                    });
                } finally {
                    clearTimeout(timer);
                    notify = null;
                }
            }
            return requests.shift();
        },
        get count() { return requests.length; },
    };
}

function savedPost(body, creating = false) {
    return {
        success: true, action: creating ? 'create' : 'edit', title: 'Saved title', revision: 2,
        body_html: `<div class="post body" data-post-inplace-body>${body}</div>`,
        published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
        tags: [], scheduled: false, message: 'Saved',
        ...(creating ? {id: 10, url: '/created', action_url: '/_inplace/post/10', token: 'fixture'} : {}),
    };
}

const savedAdmin = {revision: 2, urlStatus: 'ok', urlTitle: '', url: '/post'};

export async function runSaveRegressions(browser, origin) {
    const errors = [];
    async function newPage() {
        const page = await browser.newPage();
        page.on('pageerror', error => errors.push(String(error)));
        return page;
    }
    // Each scenario uses a fresh storage context and the real editor events.
    for (const creating of [false, true]) {
        const page = await newPage();
        try {
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            const requests = holdRequests(page, '**/_inplace/post/*');
            await requests.installed;
            await page.goto(origin + '/recovery.html');
            await page.getByRole('button', {name: creating ? 'New post' : 'Edit', exact: true}).click();
            const card = page.locator('.post-card.is-editing');
            const body = card.locator('[data-post-inplace-body]');
            if (creating) await page.keyboard.type('Saved title');
            else await card.locator('[data-post-inplace-title]').fill('Saved title');
            await body.fill('Sent text');
            await body.press('Meta+s');
            const request = await requests.next();
            assert.match((await formData(request)).get('body'), /Sent text/u);
            await body.focus();
            await page.keyboard.insertText('Typing during save');
            assert.equal(await body.textContent(), 'Sent text');
            await card.locator('[data-post-inplace-title]').focus();
            await page.keyboard.insertText('Changed title');
            assert.equal(await card.locator('[data-post-inplace-title]').textContent(), 'Saved title');
            await card.locator('.post-tags-text-input').focus();
            await page.keyboard.insertText('late tag');
            assert.equal(await card.locator('.post-tags-text-input').inputValue(), '');
            if (!creating) {
                await page.getByRole('button', {name: 'New post', exact: true}).click();
                assert.equal(await page.locator('[data-post-creating]').count(), 0);
            }
            await request.fulfill({json: savedPost('Sent text', creating)});
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            const saved = page.locator(`.post-card[data-post-id="${creating ? 10 : 9}"]`);
            assert.equal(await saved.locator('[data-post-inplace-body]').textContent(), 'Sent text');
            assert.equal(await page.evaluate(() => localStorage.length), 0);
            await saved.getByRole('button', {name: 'Edit', exact: true}).click();
            await saved.locator('[data-post-inplace-body]').fill('Can edit again');
            assert.equal(await saved.locator('[data-post-inplace-body]').textContent(), 'Can edit again');
        } finally {
            await page.close();
        }
    }
    console.log('save: existing and new public posts cannot accept edits during a pending save');

    {
        const page = await newPage();
        try {
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            const requests = holdRequests(page, '**/_inplace/post/*');
            await requests.installed;
            await page.goto(origin + '/recovery.html');
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            const body = page.locator('[data-post-inplace-body]');
            await body.fill('Keep this draft');
            await body.press('Meta+s');
            await (await requests.next()).fulfill({status: 503, json: {success: false, message: 'Save failed'}});
            await page.waitForFunction(() => !document.querySelector('.post-edit-save').disabled);
            assert.equal(await body.textContent(), 'Keep this draft');
            assert.equal(await page.locator('.post-inplace-edit-error').textContent(), 'Save failed');
            await body.fill('Retry this draft');
            await body.press('Meta+s');
            const retry = await requests.next();
            assert.match((await formData(retry)).get('body'), /Retry this draft/u);
            await retry.fulfill({json: savedPost('Retry this draft')});
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        } finally {
            await page.close();
        }
    }
    console.log('save: failed public saves retain text and unlock editing for a retry');

    for (const kind of ['audio', 'image']) {
        const page = await newPage();
        try {
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            const requests = holdRequests(page, '**/_inplace/post/*');
            await requests.installed;
            await page.goto(origin + '/recovery.html');
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            await page.locator('[data-post-inplace-body]').fill('Text with attachment');
            const upload = () => page.evaluate(kind => {
                const card = document.querySelector('.post-card.is-editing');
                const state = window.editorTest.editorStates.get(card);
                const file = kind === 'audio' ? new File(['RIFF'], 'clip.wav', {type: 'audio/wav'})
                    : new File(['fixture'], 'image.png', {type: 'image/png'});
                const range = document.createRange();
                range.selectNodeContents(state.body);
                range.collapse(false);
                window.editorTest.insertMediaFiles(state, [file], range);
            }, kind);
            await upload();
            const failedUpload = await requests.next();
            assert.equal((await formData(failedUpload)).get('inplace_action'), 'media');
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            await failedUpload.fulfill({status: 503, json: {success: false, message: 'Upload failed'}});
            await page.waitForFunction(() => !document.querySelector('.post-edit-save').disabled);
            assert.equal(await page.locator('.post-card.is-editing').count(), 1);
            assert.equal(await page.locator('.post-inplace-edit-error').textContent(), 'Upload failed');
            assert.equal(requests.count, 0, 'Upload failure must not send edit or media_redate');
            assert.equal(await page.locator('[data-post-inplace-body]').textContent(), 'Text with attachment');
            await page.waitForFunction(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                .list().some(copy => copy.snapshot.body.includes('Text with attachment')));

            await upload();
            const retriedUpload = await requests.next();
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            const url = kind === 'audio' ? '/clip.wav' : '/image.png';
            await retriedUpload.fulfill({json: {
                success: true, action: 'media', kind, media_id: 42, url, width: 1, height: 1,
            }});
            const redate = await requests.next();
            assert.equal((await formData(redate)).get('inplace_action'), 'media_redate');
            await redate.fulfill({json: {success: true, media: [{media_id: 42, url}]}});
            const save = await requests.next();
            const data = await formData(save);
            assert.equal(data.get('inplace_action'), 'edit');
            assert.match(data.get('body'), /data-post-media-id="42"/u);
            await save.fulfill({json: savedPost(data.get('body'))});
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            assert.equal(await page.evaluate(() => localStorage.length), 0);
        } finally {
            await page.close();
        }
    }
    console.log('save: image and audio failures stop saving; re-upload and save include the attachment');

    for (const csrfRetry of [false, true]) {
        const page = await newPage();
        try {
            const requests = holdRequests(page, '**/admin-save?id=9');
            await requests.installed;
            await page.goto(origin + '/admin.html?id=9' + (csrfRetry ? '&codemirror=1' : ''));
            await page.waitForFunction(() => window.adminEditorReady);
            const setText = text => csrfRetry
                ? page.evaluate(text => window.adminEditor.setValue(text), text)
                : page.locator('textarea').fill(text);
            await setText('Sent text');
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            let save = await requests.next();
            assert.equal((await formData(save)).get('body'), 'Sent text');
            await setText('Typed during save');
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            if (csrfRetry) {
                await save.fulfill({status: 422, json: {invalid_csrf_token: true, errors: []}});
                save = await requests.next();
                assert.equal((await formData(save)).get('body'), 'Sent text');
            }
            await save.fulfill({json: savedAdmin});
            await page.waitForFunction(() => document.querySelector('[name="revision"]').value === '2');
            assert.equal(requests.count, 0, 'Only one save may run at a time');
            assert.deepEqual(await page.evaluate(() => ({
                body: document.querySelector('textarea').value,
                draft: localStorage.getItem('register_content_draft:post:9'),
                warning: window.onbeforeunload(),
            })), {body: 'Typed during save', draft: 'Typed during save', warning: 'Unsaved changes'});
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            const retry = await requests.next();
            const data = await formData(retry);
            assert.equal(data.get('body'), 'Typed during save');
            assert.equal(data.get('revision'), '2');
            await retry.fulfill({json: {...savedAdmin, revision: 3}});
            await page.waitForFunction(() => document.querySelector('[name="revision"]').value === '3');
            assert.equal(await page.evaluate(() => localStorage.length), 0);
            assert.equal(await page.evaluate(() => window.onbeforeunload()), undefined);
        } finally {
            await page.close();
        }
    }
    console.log('save: admin preserves unsent edits and drafts, including after a CSRF retry');

    for (const codeMirror of [false, true]) {
        const page = await newPage();
        try {
            const requests = holdRequests(page, '**/admin-save');
            await requests.installed;
            await page.goto(origin + '/admin.html' + (codeMirror ? '?codemirror=1' : ''));
            await page.waitForFunction(() => window.adminEditorReady);
            const setText = text => codeMirror
                ? page.evaluate(text => window.adminEditor.setValue(text), text)
                : page.locator('textarea').fill(text);
            await setText('New post text');
            const input = codeMirror ? page.locator('.CodeMirror [contenteditable="true"]') : page.locator('textarea');
            await input.focus();
            await page.evaluate(() => document.dispatchEvent(new Event('save_form.register')));
            const failedSave = await requests.next();
            await page.keyboard.insertText('Typing during creation');
            assert.equal(await page.evaluate(codeMirror => codeMirror
                ? window.adminEditor.getValue() : document.querySelector('textarea').value, codeMirror), 'New post text');
            await failedSave.fulfill({status: 422, json: {errors: ['Invalid post']}});
            await page.waitForFunction(() => document.getElementById('error').textContent === 'Invalid post');
            await setText('Corrected new post');
            await input.focus();
            await page.keyboard.press('End');
            await page.keyboard.type(' again');
            assert.equal(await page.evaluate(() => localStorage.getItem('register_content_draft:post:new')), 'Corrected new post again');
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            const created = await requests.next();
            assert.equal((await formData(created)).get('body'), 'Corrected new post again');
            await created.fulfill({status: 303, headers: {location: '/admin.html?id=10'}});
            await page.waitForURL('**/admin.html?id=10');
            assert.equal(await page.evaluate(() => localStorage.getItem('register_content_draft:post:new')), null);
        } finally {
            await page.close();
        }
    }
    console.log('save: admin creation unlocks on failure and clears its draft only after success');
    assert.deepEqual(errors, [], 'Saving and upload failures must not cause uncaught errors or rejections');
}
