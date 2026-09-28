import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==';
const image = '<img src="/insertion-old.svg" width="160" height="100" alt="">';

async function withEditor(browser, origin, html, ai, run) {
    const page = await browser.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    try {
        await page.emulateMedia({reducedMotion: 'reduce'});
        await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        await page.route('**/insertion.png', route => route.fulfill({contentType: 'image/png', body: Buffer.from(png, 'base64')}));
        await page.route('**/insertion-old.svg', route => route.fulfill({contentType: 'image/svg+xml', body:
            '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="100"><rect width="160" height="100" fill="gray"/></svg>',
        }));
        await page.route('**/recovery-fixture.js', async route => {
            const response = await route.fetch();
            await route.fulfill({response, body: (await response.text())
                .replace('aiAltEnabled: false', `aiAltEnabled: ${ai}`)
                .replace("const body = creating ? '' : `<p>Server body ${revision}</p>`;",
                    "const body = creating ? '' : " + JSON.stringify(html) + ';'),
            });
        });
        const requests = holdRequests(page, '**/_inplace/post/9');
        await requests.installed;
        await page.goto(origin + '/recovery.html');
        await page.waitForFunction(() => Array.from(document.querySelectorAll('.post-card img'))
            .every(image => image.complete && image.naturalWidth > 0));
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
        const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
        await run({page, body, requests, modifier});
        assert.deepEqual(errors, []);
    } finally {
        await page.close();
    }
}

function snapshot(page) {
    return page.evaluate(() => {
        const state = window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
        return {html: window.editorTest.editableBodyHtml(state), history: state.history.length};
    });
}

async function selectText(body, text, start, end = start) {
    await body.evaluate((body, {text, start, end}) => {
        body.focus();
        const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT);
        let node;
        while ((node = walker.nextNode()) && node.textContent !== text) {}
        if (!node) throw new Error('Missing selection text: ' + text);
        const range = document.createRange();
        range.setStart(node, start);
        range.setEnd(node, end);
        getSelection().removeAllRanges();
        getSelection().addRange(range);
    }, {text, start, end});
}

async function transferFiles(body, kinds, method = 'paste') {
    await body.evaluate((body, {kinds, method, png}) => {
        const transfer = new DataTransfer();
        kinds.forEach(kind => transfer.items.add(kind === 'image'
            ? new File([Uint8Array.from(atob(png), c => c.charCodeAt(0))], 'image.png', {type: 'image/png'})
            : kind === 'audio' ? new File(['RIFF'], 'clip.wav', {type: 'audio/wav'})
                : new File(['PDF'], 'document.pdf', {type: 'application/pdf'})));
        if (method === 'drop') {
            const rect = getSelection().getRangeAt(0).getBoundingClientRect();
            body.dispatchEvent(new DragEvent('drop', {
                bubbles: true, cancelable: true, dataTransfer: transfer,
                clientX: rect.x + 0.5, clientY: rect.y + rect.height / 2,
            }));
        } else {
            const event = new Event('paste', {bubbles: true, cancelable: true});
            Object.defineProperty(event, 'clipboardData', {value: transfer});
            body.dispatchEvent(event);
        }
    }, {kinds, method, png});
}

async function completeUpload(request, kind) {
    assert.equal((await formData(request)).get('inplace_action'), 'media');
    await request.fulfill({json: {
        success: true, action: 'media', kind, media_id: 42,
        url: kind === 'image' ? '/insertion.png' : '/insertion.wav', width: 160, height: 100, name: 'Clip',
    }});
}

async function waitForMedia(page) {
    await page.waitForFunction(() => {
        const state = window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
        return state.mediaUploads.size === 0 && state.aiAltTasks.size === 0;
    });
}

async function save(page, requests) {
    const expected = (await snapshot(page)).html;
    await page.waitForFunction(html => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
        .list().some(copy => copy.snapshot.body === html), expected);
    await page.getByRole('button', {name: 'Save', exact: true}).click();
    let request = await requests.next();
    let data = await formData(request);
    if (data.get('inplace_action') === 'media_redate') {
        const audio = expected.includes('<audio');
        await request.fulfill({json: {success: true, media: [{
            media_id: 42, url: audio ? '/insertion.wav' : '/insertion.png', name: 'Clip',
        }]}});
        request = await requests.next();
        data = await formData(request);
    }
    assert.equal(data.get('inplace_action'), 'edit');
    assert.equal(data.get('body'), expected, 'Saving must keep the same content as the editor and recovery copy');
    await request.fulfill({json: {
        success: true, action: 'edit', revision: 2, title: data.get('title'),
        body_html: `<div class="post body" data-post-inplace-body>${expected}</div>`,
        published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
        tags: [], scheduled: false, message: 'Saved',
    }});
    await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
}

export async function runMediaInsertionAltRegressions(browser, origin) {
    for (const mode of ['prefix', 'suffix', 'wrapped', 'manual']) {
        const html = mode === 'wrapped'
            ? `<p><a href="/target"><strong>${image}Between${image}</strong></a></p><p>Other paragraph</p>`
            : `<p>${mode === 'suffix' ? '' : image}Between${mode === 'suffix' ? image : ''}</p><p>Other paragraph</p>`;
        await withEditor(browser, origin, html, true, async ({page, body, requests, modifier}) => {
            const firstAlt = await requests.next();
            assert.equal((await formData(firstAlt)).get('inplace_action'), 'ai_alt');
            await body.evaluate(body => { window.insertionOriginalImages = Array.from(body.querySelectorAll('img')); });
            await selectText(body, 'Between', 3);
            await transferFiles(body, ['audio']);
            await completeUpload(await requests.next(), 'audio');
            await page.waitForFunction(() => window.editorTest.editorStates
                .get(document.querySelector('.post-card.is-editing')).mediaUploads.size === 0);
            assert.equal(await body.evaluate(body => Array.from(body.querySelectorAll('img'))
                .every((image, index) => image === window.insertionOriginalImages[index])), true,
            'Splitting either side of the caret must retain each image and its pending description');
            if (mode === 'suffix' || mode === 'wrapped') {
                await body.press(`${modifier}+z`);
                assert.equal((await snapshot(page)).html, html);
                await body.press(`${modifier}+Shift+z`);
            }
            if (mode === 'manual') {
                await body.locator('p').last().click();
                await body.locator('img').click({button: 'right'});
                await page.locator('[data-context-image-alt-input]').fill('Manual description');
                await page.locator('[data-context-image-alt-input]').press('Enter');
            }
            await firstAlt.fulfill({json: {success: true, action: 'ai_alt', result: 'First description'}});
            if (mode === 'wrapped') {
                const secondAlt = await requests.next();
                assert.equal((await formData(secondAlt)).get('inplace_action'), 'ai_alt');
                await secondAlt.fulfill({json: {success: true, action: 'ai_alt', result: 'Second description'}});
            }
            await waitForMedia(page);
            assert.deepEqual(await body.locator('img').evaluateAll(images => images.map(image => image.alt)),
                mode === 'wrapped' ? ['First description', 'Second description']
                    : [mode === 'manual' ? 'Manual description' : 'First description']);
            assert.equal(requests.count, 0, 'The original description requests must not be restarted');
            assert.equal(await body.locator('audio').count(), 1);
            await save(page, requests);
        });
    }
    console.log('media insertion: splitting paragraphs preserves individual images, delayed alt replies, manual overrides, undo/redo, recovery and saving');
}

export async function runMediaInsertionCaretRegressions(browser, origin) {
    for (const block of ['h2', 'quote', 'pre', 'list', 'nested-quote', 'paragraph']) {
        for (const offset of block === 'paragraph' ? [7] : [0, 7, 12]) {
            const html = block === 'pre' ? '<pre id="insertion-block"><code>Before after</code></pre>'
                : block === 'nested-quote' ? '<blockquote><p id="insertion-block">Before after</p></blockquote>' : '<p>Before after</p>';
            await withEditor(browser, origin, html, false, async ({page, body, requests, modifier}) => {
                await selectText(body, 'Before after', offset);
                if (block === 'h2') await page.keyboard.press(`${modifier}+Alt+Digit2`);
                if (block === 'quote') await page.keyboard.press(`${modifier}+Shift+Digit9`);
                if (block === 'list') await page.keyboard.press(`${modifier}+Shift+Digit8`);
                // Native list formatting may reset its caret. Place it at the
                // intended insertion point after changing the block style.
                await selectText(body, 'Before after', offset);
                await transferFiles(body, ['image']);
                const upload = await requests.next();
                await page.keyboard.insertText('During');
                await completeUpload(upload, 'image');
                await waitForMedia(page);
                await page.keyboard.insertText('After');
                const after = (await snapshot(page)).html;
                assert.ok(after.indexOf('DuringAfter') > after.indexOf('<img'),
                    `${block} at ${offset}: continued typing must follow the inserted image: ${after}`);
                assert.equal((await body.textContent()).replace(/\s/gu, ''),
                    ('Before after'.slice(0, offset) + 'DuringAfter' + 'Before after'.slice(offset)).replace(/\s/gu, ''),
                    `${block} at ${offset}: insertion must retain the surrounding text in order: ${after}`);
                assert.equal(await body.locator('p .post-media-picture, h2 .post-media-picture, pre .post-media-picture').count(), 0,
                    'Media must be beside paragraphs, headings and code blocks, including inside quotes');
                assert.equal(await body.locator('#insertion-block').count(), ['pre', 'nested-quote'].includes(block) ? 1 : 0,
                    'Splitting a block must keep its anchor exactly once, including an insertion before all text');
                assert.equal(await body.locator('.post-media-picture').textContent(), '', 'Body typing must not enter the caption');
                await body.press(`${modifier}+z`);
                assert.equal(await body.locator('img').count(), 1, 'Undoing continued typing must retain the image');
                await body.press(`${modifier}+Shift+z`);
                assert.equal((await snapshot(page)).html, after);
                await save(page, requests);
            });
        }
    }
    console.log('media insertion: typing follows images at the start, middle and end of headings, quotes, code and lists, through completion, history and saving');
}

export async function runRejectedMediaRegressions(browser, origin) {
    for (const mode of ['paste-caret', 'paste-selection', 'drop-caret', 'empty', 'caption', 'mixed-paste', 'mixed-drop']) {
        const caption = mode === 'caption';
        const html = caption
            ? '<div class="post-picture post-media-picture">' + image.replace('alt=""', 'alt="Existing"')
                + '<div class="post-caption">Caption</div></div><p>Other paragraph</p>'
            : mode === 'empty' ? '<p><br></p>' : '<p>Original text</p>';
        await withEditor(browser, origin, html, false, async ({page, body, requests, modifier}) => {
            if (caption) {
                await body.locator('.post-caption').click();
                await page.keyboard.insertText(' edited');
            } else if (mode === 'empty') {
                await body.focus();
                await body.evaluate(body => {
                    const range = document.createRange();
                    range.setStart(body.querySelector('p'), 0);
                    range.collapse(true);
                    getSelection().removeAllRanges(); getSelection().addRange(range);
                });
            } else {
                await body.locator('p').click({clickCount: 3});
                await page.keyboard.insertText('Before after');
                await selectText(body, 'Before after', 7, mode === 'paste-selection' ? 12 : 7);
            }
            const before = await snapshot(page);
            await page.evaluate(() => {
                const selection = getSelection();
                window.rejectedMediaSelection = [selection.anchorNode, selection.anchorOffset, selection.focusNode, selection.focusOffset];
            });
            const mixed = mode.startsWith('mixed');
            const method = mode.includes('drop') || caption ? 'drop' : 'paste';
            await transferFiles(body, mixed ? ['pdf', 'image'] : ['pdf'], method);
            assert.match(await page.locator('.post-inplace-edit-error').textContent(), /document\.pdf.*not supported/u);
            if (mixed) {
                await completeUpload(await requests.next(), 'image');
                await waitForMedia(page);
                assert.equal(await body.locator('img').count(), 1);
                assert.equal(await body.textContent(), 'Before after');
            } else {
                assert.equal(requests.count, 0);
                assert.deepEqual(await snapshot(page), before, 'Rejected files must not change content or add a history step');
                assert.equal(await page.evaluate(() => {
                    const selection = getSelection();
                    return [selection.anchorNode, selection.anchorOffset, selection.focusNode, selection.focusOffset]
                        .every((value, index) => value === window.rejectedMediaSelection[index]);
                }), true, 'Rejected files must retain the exact text selection');
                if (caption) {
                    assert.equal(await body.locator('.is-editing-inline-caption').count(), 1);
                    await page.keyboard.press('Enter');
                } else if (mode === 'empty') {
                    await page.keyboard.insertText('New text');
                } else {
                    await body.press(`${modifier}+z`);
                    assert.equal((await snapshot(page)).html, html, 'Undo must still target the earlier author edit');
                    await body.press(`${modifier}+Shift+z`);
                    assert.equal((await snapshot(page)).html, before.html);
                }
            }
            assert.equal(requests.count, 0);
            await save(page, requests);
        });
    }
    console.log('media insertion: rejected paste/drop preserves text, selection, empty paragraphs, captions and history; mixed files retain supported uploads');
}
