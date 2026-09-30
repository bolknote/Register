import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==';
const temporaryMarkup = /blob:|post-media-upload|is-processing|data-post-(?:history|clipboard)-upload/u;

function snapshot(page) {
    return page.evaluate(() => {
        const state = window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
        return {
            html: window.editorTest.editableBodyHtml(state),
            media: state.body.querySelectorAll('[data-post-media-id]').length,
            pending: state.body.querySelectorAll('[data-post-history-upload]').length,
        };
    });
}

export async function runClipboardUploadRegressions(browser, origin) {
    for (const kind of ['image', 'audio']) {
        const modes = ['move', 'replace', 'duplicate', 'mid-paragraph', 'late-paste', 'failure', 'closed-session', 'menu-cut'];
        if (kind === 'image') modes.push('alt-move', 'alt-late-paste');
        for (const mode of modes) {
            const flow = mode.replace(/^alt-/u, '');
            const alt = mode.startsWith('alt-');
            const page = await browser.newPage();
            page.setDefaultTimeout(10000);
            const errors = [];
            page.on('pageerror', error => errors.push(String(error)));
            try {
                await page.emulateMedia({reducedMotion: 'reduce'});
                await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
                await page.route('**/clipboard-upload.png', route => route.fulfill({contentType: 'image/png', body: Buffer.from(png, 'base64')}));
                if (alt) await page.route('**/recovery-fixture.js', async route => {
                    const response = await route.fetch();
                    await route.fulfill({response, body: (await response.text()).replace('aiAltEnabled: false', 'aiAltEnabled: true')});
                });
                await page.route('**/recovery.html', async route => {
                    const response = await route.fetch();
                    await route.fulfill({response, body: (await response.text()).replace(
                        '<div class="post-editor-context-main">',
                        '<div class="post-editor-context-main"><button type="button" data-context-action="cut">Cut</button>',
                    )});
                });
                const requests = holdRequests(page, '**/_inplace/post/9');
                await requests.installed;
                await page.goto(origin + '/recovery.html');
                await page.getByRole('button', {name: 'Edit', exact: true}).click();
                const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
                const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
                await body.evaluate((body, {kind, png}) => {
                    const transfer = new DataTransfer();
                    transfer.items.add(kind === 'image'
                        ? new File([Uint8Array.from(atob(png), c => c.charCodeAt(0))], 'image.png', {type: 'image/png'})
                        : new File(['RIFF'], 'clip.wav', {type: 'audio/wav'}));
                    const rect = body.getBoundingClientRect();
                    body.dispatchEvent(new DragEvent('drop', {
                        bubbles: true, cancelable: true, dataTransfer: transfer,
                        clientX: rect.right - 3, clientY: rect.bottom - 2,
                    }));
                }, {kind, png});
                const upload = await requests.next();
                assert.equal((await formData(upload)).get('inplace_action'), 'media');
                await body.focus();
                await page.keyboard.press(`${modifier}+a`);
                // Chromium's native Select All can exclude a trailing
                // noneditable audio slot. Include it explicitly, as the editor's
                // Select All menu or a selection across the whole body does.
                if (kind === 'audio') await body.evaluate(body => {
                    const range = document.createRange();
                    range.selectNodeContents(body);
                    getSelection().removeAllRanges();
                    getSelection().addRange(range);
                });
                const cut = ['move', 'late-paste', 'menu-cut'].includes(flow);
                if (mode === 'menu-cut') {
                    await body.locator('p').first().click({button: 'right'});
                    await page.locator('[data-context-action="cut"]').click();
                } else {
                    await page.keyboard.press(`${modifier}+${cut ? 'x' : 'c'}`);
                }
                if (cut) assert.equal((await snapshot(page)).pending, 0, 'Cut must remove the selected upload');
                const duplicate = ['duplicate', 'mid-paragraph', 'failure'].includes(mode);
                if (duplicate) await body.evaluate((body, mode) => {
                    const range = document.createRange();
                    if (mode === 'mid-paragraph') range.setStart(body.querySelector('p').firstChild, 6);
                    else range.selectNodeContents(body);
                    range.collapse(mode === 'mid-paragraph');
                    getSelection().removeAllRanges();
                    getSelection().addRange(range);
                }, mode);
                if (!['late-paste', 'closed-session'].includes(flow)) {
                    await page.keyboard.press(`${modifier}+v`);
                    const expectedPending = duplicate ? 2 : 1;
                    await page.waitForFunction(expected => {
                        const state = window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
                        return state?.body.querySelectorAll('[data-post-history-upload]').length === expected;
                    }, expectedPending);
                    assert.equal((await snapshot(page)).pending, expectedPending);
                    if (duplicate || mode === 'menu-cut') {
                        await body.press(`${modifier}+z`);
                        assert.equal((await snapshot(page)).pending, duplicate ? 1 : 0);
                        await body.press(`${modifier}+Shift+z`);
                        assert.equal((await snapshot(page)).pending, duplicate ? 2 : 1);
                    }
                    if (mode === 'move') {
                        await page.keyboard.insertText('After pasted upload');
                        assert.equal(await body.evaluate(body => {
                            const after = document.createRange();
                            after.setStartAfter(body.querySelector('[data-post-history-upload]'));
                            after.setEnd(body, body.childNodes.length);
                            return after.toString();
                        }), 'After pasted upload', (await snapshot(page)).html);
                        await body.press(`${modifier}+z`);
                        assert.equal((await snapshot(page)).pending, 1);
                    }
                }
                assert.equal(requests.count, 0, 'Moving/copying a placeholder must not start another upload');
                const url = kind === 'image' ? '/clipboard-upload.png' : '/clip.wav';
                await upload.fulfill(mode === 'failure'
                    ? {status: 503, json: {success: false, message: 'Upload failed'}}
                    : {json: {success: true, action: 'media', kind, media_id: 42, url, name: 'Clip', width: 1, height: 1}});
                const replyAlt = async () => {
                    const ai = await requests.next();
                    assert.equal((await formData(ai)).get('inplace_action'), 'ai_alt');
                    await ai.fulfill({json: {success: true, action: 'ai_alt', result: 'Generated description'}});
                    await page.waitForFunction(() => window.editorTest.editorStates
                        .get(document.querySelector('.post-card.is-editing')).aiAltTasks.size === 0);
                };
                if (alt && flow !== 'late-paste') await replyAlt();
                await page.waitForFunction(() => window.editorTest.editorStates
                    .get(document.querySelector('.post-card.is-editing')).mediaUploads.size === 0);
                if (flow === 'late-paste') {
                    await page.keyboard.press(`${modifier}+v`);
                    if (alt) await replyAlt();
                }
                if (mode === 'closed-session') {
                    page.once('dialog', dialog => dialog.accept());
                    await page.getByRole('button', {name: 'Cancel', exact: true}).click();
                    await page.getByRole('button', {name: 'Edit', exact: true}).click();
                    await body.focus();
                    await page.keyboard.press(`${modifier}+a`);
                    const before = await snapshot(page);
                    await page.keyboard.press(`${modifier}+v`);
                    assert.deepEqual(await snapshot(page), before, 'An expired clipboard must not replace selected text');
                    assert.match(await page.locator('.post-inplace-status').textContent(), /Copy the attachment again/u);
                    assert.equal(await body.evaluate(body => {
                        const data = new DataTransfer();
                        const text = 'document.querySelector("[data-post-history-upload]")';
                        data.setData('text/plain', text);
                        data.setData('text/html', `<pre>${text}</pre>`);
                        const event = new Event('paste', {bubbles: true, cancelable: true});
                        Object.defineProperty(event, 'clipboardData', {value: data});
                        body.dispatchEvent(event);
                        return event.defaultPrevented;
                    }), false, 'Literal code mentioning an upload attribute is ordinary clipboard content');
                } else if (mode === 'failure') {
                    assert.equal((await snapshot(page)).pending, 0);
                    await body.press(`${modifier}+z`);
                    await body.press(`${modifier}+Shift+z`);
                    const before = await snapshot(page);
                    assert.doesNotMatch(before.html, temporaryMarkup);
                    await page.keyboard.press(`${modifier}+v`);
                    assert.deepEqual(await snapshot(page), before, 'A failed upload must not be resurrected from clipboard or history');
                    assert.match(await page.locator('.post-inplace-status').textContent(), /Copy the attachment again/u);
                } else {
                    const expectedCount = duplicate ? 2 : 1;
                    const done = await snapshot(page);
                    assert.equal(done.media, expectedCount);
                    assert.doesNotMatch(done.html, temporaryMarkup);
                    if (alt) assert.match(done.html, /alt="Generated description"/u);
                    assert.equal(await body.locator('p .post-media-picture, p p').count(), 0, 'Pasted block media must not create invalid nested paragraphs');
                    if (cut || mode === 'replace') assert.doesNotMatch(done.html, /<p(?: class="")?>(?:<br>)*<\/p>/u,
                        'Moving the body must not save extra empty paragraphs');
                    if (!alt && (duplicate || cut)) {
                        await body.press(`${modifier}+z`);
                        assert.equal((await snapshot(page)).media, duplicate ? 1 : 0);
                        await body.press(`${modifier}+Shift+z`);
                        assert.equal((await snapshot(page)).media, expectedCount, 'Redo must retain every distinct occurrence');
                    }
                    const html = (await snapshot(page)).html;
                    await page.waitForFunction(html => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                        .list().some(record => record.snapshot.body === html), html);
                    await page.getByRole('button', {name: 'Save', exact: true}).click();
                    const redate = await requests.next();
                    assert.equal((await formData(redate)).get('inplace_action'), 'media_redate');
                    await redate.fulfill({json: {success: true, media: [{media_id: 42, url, name: 'Clip'}]}});
                    const save = await requests.next();
                    const data = await formData(save);
                    assert.equal(data.get('inplace_action'), 'edit');
                    assert.doesNotMatch(data.get('body'), temporaryMarkup);
                    assert.equal((data.get('body').match(/data-post-media-id="42"/gu) || []).length, expectedCount);
                    await save.fulfill({json: {
                        success: true, action: 'edit', revision: 2, title: data.get('title'),
                        body_html: `<div class="post body" data-post-inplace-body>${data.get('body')}</div>`,
                        published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
                        tags: [], scheduled: false, message: 'Saved',
                    }});
                    await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
                }
                assert.deepEqual(errors, []);
            } finally {
                await page.close();
            }
            console.log(`clipboard uploads: ${kind} ${mode} preserves attachments, history and saved content`);
        }
    }
}
