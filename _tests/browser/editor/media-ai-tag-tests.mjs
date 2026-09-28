import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==';

async function withPage(browser, run) {
    const page = await browser.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    try {
        await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        await run(page);
        assert.deepEqual(errors, []);
    } finally {
        await page.close();
    }
}

function savedPost(data) {
    return {
        success: true, action: 'edit', revision: 2, title: data.get('title'),
        body_html: `<div class="post body" data-post-inplace-body>${data.get('body')}</div>`,
        published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
        tags: [], scheduled: false, message: 'Saved',
    };
}

function editorHtml(page) {
    return page.evaluate(() => window.editorTest.editableBodyHtml(window.editorTest.editorStates
        .get(document.querySelector('.post-card.is-editing'))));
}

async function startAi(page, mode) {
    const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
    const paragraph = body.locator(':scope > p').first();
    await paragraph.click();
    if (mode.startsWith('selected-')) {
        await body.evaluate((body, mode) => {
            const range = document.createRange();
            range.selectNodeContents(mode === 'selected-text' ? body.querySelector('p') : body);
            getSelection().removeAllRanges();
            getSelection().addRange(range);
        }, mode);
    }
    await paragraph.click({button: 'right'});
    await page.locator('[data-context-ai-action="proofread"]').click();
}

async function replyAi(page, request, selectedText = false) {
    const data = await formData(request);
    assert.equal(data.get('ai_action'), 'proofread');
    const source = data.get('text');
    assert.doesNotMatch(source, /blob:|post-media-upload|is-processing|data-post-history-upload/u);
    if (selectedText) assert.equal(source, 'Server body 1');
    await request.fulfill({json: {
        success: true, action: 'ai', ai_action: 'proofread', result: source.replace('Server body 1', 'Corrected body'),
    }});
    await page.waitForFunction(() => !document.querySelector('.post-card.is-ai-working'));
}

export async function runMediaAiRegressions(browser, origin) {
    for (const kind of ['image', 'audio']) {
        for (const mode of ['whole', 'selected-media', 'selected-text', 'late-upload']) {
            await withPage(browser, async page => {
                await page.emulateMedia({reducedMotion: 'reduce'});
                await page.route('**/media-ai.png', route => route.fulfill({contentType: 'image/png', body: Buffer.from(png, 'base64')}));
                await page.route('**/recovery.html', async route => {
                    const response = await route.fetch();
                    await route.fulfill({response, body: (await response.text()).replace(
                        '<div class="post-editor-context-main">',
                        '<div class="post-editor-context-main"><button type="button" data-context-ai-action="proofread">Proofread</button>',
                    )});
                });
                const requests = holdRequests(page, '**/_inplace/post/9');
                await requests.installed;
                await page.goto(origin + '/recovery.html');
                await page.getByRole('button', {name: 'Edit', exact: true}).click();
                const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
                const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
                let earlierAi;
                if (mode === 'late-upload') {
                    await startAi(page, 'whole');
                    earlierAi = await requests.next();
                }
                await body.evaluate((body, {kind, png}) => {
                    const transfer = new DataTransfer();
                    transfer.items.add(kind === 'image'
                        ? new File([Uint8Array.from(atob(png), c => c.charCodeAt(0))], 'image.png', {type: 'image/png'})
                        : new File(['RIFF'], 'clip.wav', {type: 'audio/wav'}));
                    const bounds = body.getBoundingClientRect();
                    body.dispatchEvent(new DragEvent('drop', {
                        bubbles: true, cancelable: true, dataTransfer: transfer,
                        clientX: bounds.right - 3, clientY: bounds.bottom - 2,
                    }));
                    window.pendingUploadElement = body.querySelector('[data-post-history-upload]');
                }, {kind, png});
                const upload = await requests.next();
                assert.equal((await formData(upload)).get('inplace_action'), 'media');
                const before = await editorHtml(page);
                if (earlierAi) {
                    await replyAi(page, earlierAi);
                    assert.match(await page.locator('.post-inplace-status').textContent(), /source text has changed/u);
                    assert.equal(await editorHtml(page), before, 'A stale AI reply must preserve an upload started after the request');
                } else {
                    await startAi(page, mode);
                    if (mode === 'selected-text') {
                        await replyAi(page, await requests.next(), true);
                        assert.equal(await body.locator(':scope > p').first().textContent(), 'Corrected body');
                    } else {
                        assert.equal(requests.count, 0, 'AI must not receive the pending upload markup');
                        assert.equal(await page.locator('.post-card.is-ai-working').count(), 0);
                        assert.match(await page.locator('.post-inplace-status').textContent(), /finish uploading/u);
                        assert.equal(await editorHtml(page), before);
                    }
                }
                assert.equal(await body.evaluate(body => body.contains(window.pendingUploadElement)), true);
                const url = kind === 'image' ? '/media-ai.png' : '/clip.wav';
                await upload.fulfill({json: {
                    success: true, action: 'media', kind, media_id: 42, url, name: 'Uploaded clip', width: 1, height: 1,
                }});
                await page.waitForFunction(() => window.editorTest.editorStates
                    .get(document.querySelector('.post-card.is-editing')).mediaUploads.size === 0);
                if (mode !== 'selected-text') {
                    await startAi(page, mode === 'late-upload' ? 'whole' : mode);
                    await replyAi(page, await requests.next());
                }
                const media = body.locator('[data-post-media-id="42"]');
                assert.equal(await media.count(), 1);
                assert.equal(await media.getAttribute('src'), url);
                const after = await editorHtml(page);
                assert.match(after, /Corrected body/u);
                assert.doesNotMatch(after, /blob:|post-media-upload|is-processing|data-post-history-upload/u);
                await body.press(`${modifier}+z`);
                assert.match(await editorHtml(page), /Server body 1/u);
                assert.equal(await media.count(), 1, 'Undoing AI must retain the finished attachment');
                await body.press(`${modifier}+Shift+z`);
                assert.equal(await editorHtml(page), after);
                await page.waitForFunction(html => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                    .list().some(copy => copy.snapshot.body === html), after);
                await page.getByRole('button', {name: 'Save', exact: true}).click();
                const redate = await requests.next();
                assert.equal((await formData(redate)).get('inplace_action'), 'media_redate');
                await redate.fulfill({json: {success: true, media: [{media_id: 42, url, name: 'Uploaded clip'}]}});
                const save = await requests.next();
                const data = await formData(save);
                assert.equal(data.get('inplace_action'), 'edit');
                assert.equal(data.get('body'), after);
                await save.fulfill({json: savedPost(data)});
                await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            });
            console.log(`media AI: ${mode} keeps ${kind} uploads intact through completion, undo/redo, recovery and saving`);
        }
    }
}

async function pasteTags(input, scenario) {
    await input.fill(scenario.value);
    return input.evaluate((input, scenario) => {
        input.setSelectionRange(scenario.start, scenario.end, 'backward');
        const data = new DataTransfer();
        data.setData('text/plain', scenario.paste);
        const event = new Event('paste', {bubbles: true, cancelable: true});
        Object.defineProperty(event, 'clipboardData', {value: data});
        input.dispatchEvent(event);
        return event.defaultPrevented;
    }, scenario);
}

export async function runTagPasteRegressions(browser, origin) {
    const cases = [
        {name: 'append', value: 'alpha', start: 5, end: 5, paste: ', beta', expected: ['old', 'alpha', 'beta']},
        {name: 'prepend', value: 'beta', start: 0, end: 0, paste: 'alpha, ', expected: ['old', 'alpha', 'beta']},
        {name: 'partial selection', value: 'alphaXXXgamma', start: 5, end: 8, paste: '; beta\n', expected: ['old', 'alpha', 'beta', 'gamma']},
        {name: 'whole selection', value: 'discard', start: 0, end: 7, paste: '#alpha; beta\nOLD, alpha', expected: ['old', 'alpha', 'beta']},
    ];
    for (const admin of [false, true]) {
        for (const scenario of cases) {
            await withPage(browser, async page => {
                const requests = holdRequests(page, admin ? '**/admin-save?id=9' : '**/_inplace/post/9');
                await requests.installed;
                await page.goto(origin + (admin ? '/admin.html?id=9&codemirror=1&toolbar=1&tags=1' : '/recovery.html'));
                if (admin) await page.waitForFunction(() => window.adminEditorReady);
                else await page.getByRole('button', {name: 'Edit', exact: true}).click();
                const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
                const input = page.locator(admin ? '.editor-tags-text-input' : '.post-tags-text-input');
                assert.equal(await pasteTags(input, scenario), true);
                assert.equal(await input.inputValue(), '');
                assert.deepEqual(await page.locator(admin ? '.editor-tag-chip-label' : '.post-tag-chip-label').allTextContents(), scenario.expected);
                if (!admin) {
                    await page.waitForFunction(tags => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                        .list().some(copy => copy.snapshot.tags === tags), scenario.expected.join(', '));
                }
                await input.press(`${modifier}+s`);
                let request = await requests.next();
                let data = await formData(request);
                assert.equal(data.get('tags'), scenario.expected.join(', '));
                if (scenario.name === 'append') {
                    await request.fulfill(admin
                        ? {status: 422, json: {errors: ['Cannot save']}}
                        : {status: 503, json: {success: false, message: 'Cannot save'}});
                    await page.waitForFunction(admin => admin
                        ? document.getElementById('error').textContent === 'Cannot save'
                        : !document.querySelector('.post-edit-save').disabled, admin);
                    await input.press(`${modifier}+s`);
                    request = await requests.next();
                    data = await formData(request);
                    assert.equal(data.get('tags'), scenario.expected.join(', '), 'A failed save must preserve all pasted and preexisting tags');
                }
                await request.fulfill({json: admin
                    ? {success: true, revision: 2, urlStatus: 'ok', urlTitle: '', url: '/post'}
                    : savedPost(data),
                });
                await page.waitForFunction(admin => admin
                    ? document.querySelector('[name="revision"]').value === '2'
                    : !document.querySelector('.post-card.is-editing'), admin);
            });
            console.log(`tag paste: ${admin ? 'admin' : 'public'} ${scenario.name} preserves unselected text and saves the complete list`);
        }
    }
    for (const scenario of [
        {name: 'invalid unfinished text', value: 'bad@tag', start: 7, end: 7, paste: ', valid'},
        {name: 'merged tag limit', value: 'keep', start: 4, end: 4, paste: Array.from({length: 100}, (_, index) => `tag${index}`).join(', ')},
    ]) {
        await withPage(browser, async page => {
            await page.goto(origin + '/recovery.html');
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            const input = page.locator('.post-tags-text-input');
            assert.equal(await pasteTags(input, scenario), false, 'An uncommittable list must fall back to native insertion');
            assert.equal(await input.inputValue(), scenario.value);
            assert.deepEqual(await page.locator('.post-tag-chip-label').allTextContents(), ['old']);
        });
        console.log(`tag paste: ${scenario.name} cannot consume the clipboard or silently replace unfinished text`);
    }
}
