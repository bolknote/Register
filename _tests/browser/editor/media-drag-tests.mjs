import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const image = '<img src="/drag-source.svg" width="160" height="100" alt="Existing">';
const caption = '<div class="post-caption">Saved caption</div>';
const picture = `<div class="post-picture post-media-picture">${image}${caption}</div>`;
const temporary = /blob:|post-media-upload|is-processing|data-post-(?:history|clipboard)-upload/u;

function snapshot(page) {
    return page.evaluate(() => {
        const state = editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
        return {html: editorTest.editableBodyHtml(state), history: state.history.length};
    });
}

async function uploadImage(body) {
    await body.evaluate(async body => {
        body.focus();
        const range = document.createRange();
        range.setStart(body, 1);
        range.collapse(true);
        getSelection().removeAllRanges();
        getSelection().addRange(range);
        // A real rendered image is required for a native mouse drag.
        const canvas = document.createElement('canvas');
        canvas.width = 160;
        canvas.height = 100;
        canvas.getContext('2d').fillRect(0, 0, 160, 100);
        const png = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
        const transfer = new DataTransfer();
        transfer.items.add(new File([png], 'image.png', {type: 'image/png'}));
        const event = new Event('paste', {bubbles: true, cancelable: true});
        Object.defineProperty(event, 'clipboardData', {value: transfer});
        body.dispatchEvent(event);
    });
}

async function completeUpload(request, failure = false) {
    assert.equal((await formData(request)).get('inplace_action'), 'media');
    await request.fulfill(failure ? {status: 503, json: {success: false, message: 'Upload failed'}} : {json: {
        success: true, action: 'media', kind: 'image', media_id: 42,
        url: '/drag-upload.svg', width: 160, height: 100,
    }});
}

export async function runMediaDragRegressions(browser, origin) {
    for (const mode of ['completed', 'backward', 'figure', 'overlay', 'inline', 'self', 'outside',
        'pending', 'pending-undo', 'pending-alt', 'alt', 'failure']) {
        const page = await browser.newPage();
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        try {
            const pending = mode.startsWith('pending') || mode === 'failure';
            const alt = mode === 'alt' || mode === 'pending-alt';
            const noMove = mode === 'self' || mode === 'outside';
            let media = picture;
            if (mode === 'figure') media = `<figure><a href="/original-link"><picture>${image}</picture></a><figcaption>Saved caption</figcaption></figure>`;
            if (mode === 'overlay') media = `<span class="post-media-overlay" data-post-media-overlay="" role="figure">${image}<span class="post-media-overlay-caption">Saved caption</span></span>`;
            if (mode === 'inline') media = `<p>Leading<a href="/original-link">${image}</a>Trailing</p>`;
            const initial = '<p>First paragraph</p>' + (pending ? '' : media.replace('alt="Existing"', alt ? 'alt=""' : 'alt="Existing"')) + '<p>Drop target here</p>';
            await page.emulateMedia({reducedMotion: 'reduce'});
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            await page.route(/\/drag-(?:source|upload)\.svg$/u, route => route.fulfill({contentType: 'image/svg+xml', body:
                '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="100"><rect width="160" height="100" fill="gray"/></svg>',
            }));
            await page.route('**/recovery-fixture.js', async route => {
                const response = await route.fetch();
                await route.fulfill({response, body: (await response.text())
                    .replace('aiAltEnabled: false', `aiAltEnabled: ${alt}`)
                    .replace("const body = creating ? '' : `<p>Server body ${revision}</p>`;", `const body = creating ? '' : ${JSON.stringify(initial)};`),
                });
            });
            const requests = holdRequests(page, '**/_inplace/post/9');
            await requests.installed;
            await page.goto(origin + '/recovery.html');
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
            const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
            let upload, description;
            if (pending) {
                await uploadImage(body);
                upload = await requests.next();
                if (mode === 'pending-alt') {
                    await completeUpload(upload);
                    upload = null;
                }
            }
            if (alt) {
                description = await requests.next();
                assert.equal((await formData(description)).get('inplace_action'), 'ai_alt');
            }
            await page.waitForFunction(() => Array.from(document.querySelectorAll('.post-card.is-editing img'))
                .every(image => image.complete && image.naturalWidth > 0));
            await body.evaluate(body => { window.dragOriginalImage = body.querySelector('img'); });
            const before = await snapshot(page);
            const target = mode === 'outside' ? page.locator('.post-card.is-editing [data-post-inplace-title]')
                : mode === 'self' ? body.locator('img')
                    : mode === 'backward' ? body.locator(':scope > p').first() : body.locator(':scope > p').last();
            await body.locator('img').dragTo(target, {sourcePosition: {x: 80, y: 45}, targetPosition: {x: 50, y: 12}});
            const moved = await snapshot(page);
            assert.equal(await body.locator('img').count(), 1, `${mode}: native dragging must not delete or duplicate the image`);
            assert.equal(await body.evaluate(body => body.querySelector('img') === window.dragOriginalImage), true,
                `${mode}: moving must retain the live image and its pending callbacks`);
            assert.equal(await body.locator('a[href^="blob:"]').count(), 0);
            assert.equal(await body.evaluate(body => body.classList.contains('is-media-dragover')), false);
            if (noMove) {
                assert.deepEqual(moved, before, `${mode}: an invalid/self drop must not change content or history`);
                assert.equal(await page.locator('[data-post-inplace-title]').textContent(), 'Server title 1');
            } else {
                assert.equal(moved.history, before.history + 1, `${mode}: a move must add one undo step`);
                assert.ok(mode === 'backward'
                    ? moved.html.indexOf('<img') < moved.html.indexOf('paragraph')
                    : moved.html.indexOf('<img') > moved.html.indexOf('Drop '), `${mode}: image must reach the drop location: ${moved.html}`);
                if (!pending && mode !== 'inline') assert.equal(await body.locator('.post-caption, figcaption, .post-media-overlay-caption').textContent(), 'Saved caption');
                if (mode === 'figure' || mode === 'inline') assert.equal(await body.locator('a[href="/original-link"] img').count(), 1);
                if (mode === 'inline') assert.match(moved.html, /LeadingTrailing/u);
                await body.press(`${modifier}+z`);
                assert.equal((await snapshot(page)).html, before.html, `${mode}: undo must restore the original block and position`);
                if (mode !== 'pending-undo') {
                    await body.press(`${modifier}+Shift+z`);
                    assert.equal((await snapshot(page)).html, moved.html);
                }
            }
            assert.equal(requests.count, 0, 'Dragging must not start another upload or description request');
            if (upload) await completeUpload(upload, mode === 'failure');
            if (description) await description.fulfill({json: {success: true, action: 'ai_alt', result: 'Generated description'}});
            await page.waitForFunction(() => {
                const state = editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
                return state.mediaUploads.size === 0 && state.aiAltTasks.size === 0;
            });
            if (mode === 'pending-undo') {
                const undone = (await snapshot(page)).html;
                assert.ok(undone.indexOf('<img') < undone.indexOf('Drop '));
                await body.press(`${modifier}+Shift+z`);
                const redone = (await snapshot(page)).html;
                assert.ok(redone.indexOf('<img') > redone.indexOf('Drop '));
            }
            assert.equal(await body.locator('img').count(), mode === 'failure' ? 0 : 1);
            if (alt) assert.equal(await body.locator('img').getAttribute('alt'), 'Generated description');
            if (!noMove && mode !== 'failure') {
                await page.keyboard.insertText('After move.');
                const typed = (await snapshot(page)).html;
                assert.ok(typed.indexOf('After move.') > typed.indexOf('<img'), `${mode}: typing must follow the moved image: ${typed}`);
                await body.press(`${modifier}+z`);
                assert.doesNotMatch((await snapshot(page)).html, /After move\./u);
                await body.press(`${modifier}+Shift+z`);
                assert.equal((await snapshot(page)).html, typed);
            }
            const expected = (await snapshot(page)).html;
            assert.doesNotMatch(expected, temporary, `${mode}: completed content cannot contain temporary upload references`);
            if (!noMove) await page.waitForFunction(html => RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                .list().some(copy => copy.snapshot.body === html), expected);
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            if (noMove) {
                await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
                assert.equal(requests.count, 0, 'A rejected drop must not create an edit to save');
                assert.deepEqual(errors, []);
                continue;
            }
            let save = await requests.next();
            let data = await formData(save);
            if (data.get('inplace_action') === 'media_redate') {
                await save.fulfill({json: {success: true, media: [{media_id: 42, url: '/drag-upload.svg'}]}});
                save = await requests.next();
                data = await formData(save);
            }
            assert.equal(data.get('inplace_action'), 'edit');
            assert.equal(data.get('body'), expected, `${mode}: saving must preserve the moved media and caption`);
            await save.fulfill({json: {
                success: true, action: 'edit', revision: 2, title: data.get('title'),
                body_html: `<div class="post body" data-post-inplace-body>${expected}</div>`,
                published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
                tags: [], scheduled: false, message: 'Saved',
            }});
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            assert.equal(await page.locator('[data-post-inplace-body] img').count(), mode === 'failure' ? 0 : 1);
            assert.deepEqual(errors, []);
        } catch (error) {
            throw new Error(`image drag ${mode}: ${error.message}`, {cause: error});
        } finally {
            await page.close();
        }
    }
    console.log('image drag: native moves preserve captions, links, image identity, pending uploads and descriptions, undo/redo, recovery and saving; invalid drops and failed uploads stay safe');
}
