import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const recoveredBody = '<p>Retained opening</p><img src="/expired-media.png" width="32" height="32" '
    + 'alt="Expired upload" data-post-media-id="501"><p>Retained ending</p>';
const expiredMessage = 'This uploaded image is no longer available. Remove it or upload it again.';
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==', 'base64');

export async function runExpiredMediaRecoveryRegressions(browser, origin) {
    for (const replacement of [false, true]) {
        const page = await browser.newPage();
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        try {
            await page.emulateMedia({reducedMotion: 'reduce'});
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            await page.route('**/replacement-media.png', route => route.fulfill({contentType: 'image/png', body: png}));
            const requests = holdRequests(page, '**/_inplace/post/9');
            await requests.installed;
            await page.goto(origin + '/recovery.html');
            await page.waitForFunction(() => window.RegisterPostRecovery && window.editorTest);
            await page.evaluate(body => {
                const store = window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1);
                if (!store.save({version: 1, id: 'expired-media-copy', target: '9', revision: 1, savedAt: Date.now(),
                    snapshot: {
                        title: 'Recovered title', body, tags: 'old', date: '2026-09-06T15:00:00',
                        slug: 'server-slug', slugChanged: false, mediaIds: [501], pendingMedia: false,
                    }})) throw new Error('Unable to seed the recovery copy');
            }, recoveredBody);
            await page.reload();
            await page.getByRole('button', {name: 'Restore text', exact: true}).click();
            const card = page.locator('.post-card.is-editing');
            const body = card.locator('[data-post-inplace-body]');
            await card.getByRole('button', {name: 'Save', exact: true}).click();
            const failed = await requests.next();
            const failedData = await formData(failed);
            assert.equal(failedData.get('inplace_action'), 'media_redate');
            assert.equal(failedData.get('media_ids'), '501');
            assert.equal(failedData.get('legacy_media_ids'), '501');
            await failed.fulfill({status: 409, json: {success: false, message: expiredMessage}});
            await page.getByText(expiredMessage, {exact: true}).waitFor();
            assert.equal(await card.evaluate(element => element.inert), false, 'Expired media must not lock the editor');
            assert.match(await body.textContent(), /Retained opening/u);
            assert.match(await body.textContent(), /Retained ending/u);
            assert.equal(await body.locator('img[data-post-media-id="501"]').count(), 1);
            assert.equal(await page.evaluate(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                .list().some(copy => copy.snapshot.body.includes('Retained opening') && copy.snapshot.body.includes('data-post-media-id="501"'))), true,
            'A rejected save must retain the complete recovery copy');

            await body.focus();
            await body.evaluate(element => {
                const range = document.createRange();
                const image = element.querySelector('img');
                range.selectNode(image.closest('.post-media-picture') || image);
                getSelection().removeAllRanges();
                getSelection().addRange(range);
            });
            await page.keyboard.press('Backspace');
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing img[data-post-media-id="501"]'));
            assert.equal(await card.evaluate(element => window.editorTest.editorStates.get(element).uploadedMediaIds.has(501)), true,
                'Removing the image must keep its id available for eventual cleanup');

            if (replacement) {
                await body.evaluate(element => {
                    const range = document.createRange();
                    range.selectNodeContents(element);
                    range.collapse(false);
                    getSelection().removeAllRanges();
                    getSelection().addRange(range);
                    const data = new DataTransfer();
                    data.items.add(new File(['replacement'], 'replacement.png', {type: 'image/png'}));
                    const event = new Event('paste', {bubbles: true, cancelable: true});
                    Object.defineProperty(event, 'clipboardData', {value: data});
                    element.dispatchEvent(event);
                });
                const upload = await requests.next();
                assert.equal((await formData(upload)).get('inplace_action'), 'media');
                await upload.fulfill({json: {
                    success: true, action: 'media', kind: 'image', media_id: 502, persistent_identity: true,
                    url: '/replacement-media.png', width: 1, height: 1,
                }});
                await page.waitForFunction(() => window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing')).mediaUploads.size === 0);
            }
            const correctedBody = await card.evaluate(element => window.editorTest.editableBodyHtml(window.editorTest.editorStates.get(element)));
            assert.match(correctedBody, /Retained opening/u);
            assert.match(correctedBody, /Retained ending/u);
            assert.doesNotMatch(correctedBody, /data-post-media-id="501"/u);
            await page.waitForFunction(html => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                .list().some(copy => copy.snapshot.body === html), correctedBody);
            await card.getByRole('button', {name: 'Save', exact: true}).click();
            if (replacement) {
                const redate = await requests.next();
                const redateData = await formData(redate);
                assert.equal(redateData.get('inplace_action'), 'media_redate');
                assert.equal(redateData.get('media_ids'), '502', 'Redating must exclude the removed expired image');
                assert.equal(redateData.get('legacy_media_ids'), '', 'A fresh upload already carries a persistent identity');
                await redate.fulfill({json: {success: true, media: [{media_id: 502, url: '/replacement-media.png'}]}});
            }
            const save = await requests.next();
            const sent = await formData(save);
            assert.equal(sent.get('inplace_action'), 'edit', 'Without used uploads the retry must proceed directly to saving');
            assert.equal(sent.get('body'), correctedBody);
            await save.fulfill({json: {
                success: true, action: 'edit', revision: 2, title: sent.get('title'),
                body_html: `<div class="post body" data-post-inplace-body>${sent.get('body')}</div>`,
                published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
                tags: [], scheduled: false, message: 'Saved',
            }});
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            assert.match(await page.locator('[data-post-inplace-body]').textContent(), /Retained opening/u);
            assert.match(await page.locator('[data-post-inplace-body]').textContent(), /Retained ending/u);
            assert.equal(await page.locator('img[data-post-media-id="501"]').count(), 0);
            assert.equal(await page.locator('img[data-post-media-id="502"]').count(), replacement ? 1 : 0);
            assert.equal(await page.evaluate(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1).list().length), 0,
                'Only a successful save clears the recovery copy');
            assert.deepEqual(errors, []);
        } catch (error) {
            throw new Error(`expired recovery media ${replacement ? 'replacement' : 'removal'}: ${error.message}`, {cause: error});
        } finally {
            await page.close();
        }
        console.log(`expired recovery media: a rejected save retains the draft; ${replacement ? 'replacing' : 'removing'} the image permits saving`);
    }
    await runPersistentMediaRecoveryRegression(browser, origin);
}

async function runPersistentMediaRecoveryRegression(browser, origin) {
    const page = await browser.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    page.on('dialog', dialog => dialog.accept());
    try {
        await page.emulateMedia({reducedMotion: 'reduce'});
        await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        await page.route('**/persistent-media.png', route => route.fulfill({contentType: 'image/png', body: png}));
        const requests = holdRequests(page, '**/_inplace/post/9');
        await requests.installed;
        await page.goto(origin + '/recovery.html');
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        let card = page.locator('.post-card.is-editing');
        let body = card.locator('[data-post-inplace-body]');
        await body.fill('Fresh draft text');
        await body.evaluate(element => {
            const range = document.createRange();
            range.selectNodeContents(element);
            range.collapse(false);
            getSelection().removeAllRanges();
            getSelection().addRange(range);
            const data = new DataTransfer();
            data.items.add(new File(['fixture'], 'fresh.png', {type: 'image/png'}));
            const event = new Event('paste', {bubbles: true, cancelable: true});
            Object.defineProperty(event, 'clipboardData', {value: data});
            element.dispatchEvent(event);
        });
        const upload = await requests.next();
        assert.equal((await formData(upload)).get('inplace_action'), 'media');
        await upload.fulfill({json: {
            success: true, action: 'media', kind: 'image', media_id: 503, persistent_identity: true,
            url: '/persistent-media.png', width: 1, height: 1,
        }});
        await page.waitForFunction(() => window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing')).mediaUploads.size === 0);
        await page.waitForFunction(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1).list()
            .some(copy => copy.snapshot.body.includes('data-post-media-id="503"')
                && copy.snapshot.body.includes('data-post-media-identity="1"') && !copy.snapshot.pendingMedia));
        await page.reload();
        await page.getByRole('button', {name: 'Restore text', exact: true}).click();
        card = page.locator('.post-card.is-editing');
        body = card.locator('[data-post-inplace-body]');
        assert.match(await body.textContent(), /Fresh draft text/u);
        assert.equal(await body.locator('img[data-post-media-id="503"]').getAttribute('data-post-media-identity'), '1',
            'Recovery sanitation must preserve the identity of a newly uploaded attachment');
        await card.getByRole('button', {name: 'Save', exact: true}).click();
        const redate = await requests.next();
        const redateData = await formData(redate);
        assert.equal(redateData.get('inplace_action'), 'media_redate');
        assert.equal(redateData.get('media_ids'), '503');
        assert.equal(redateData.get('legacy_media_ids'), '', 'A restored fresh upload must not be treated as a legacy id');
        await redate.fulfill({json: {success: true, media: [{media_id: 503, persistent_identity: true, url: '/persistent-media.png'}]}});
        const save = await requests.next();
        const sent = await formData(save);
        assert.equal(sent.get('inplace_action'), 'edit');
        assert.equal(sent.get('legacy_media_ids'), '');
        assert.match(sent.get('body'), /data-post-media-identity="1"/u);
        await save.fulfill({json: {
            success: true, action: 'edit', revision: 2, title: sent.get('title'),
            body_html: `<div class="post body" data-post-inplace-body>${sent.get('body')}</div>`,
            published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
            tags: [], scheduled: false, message: 'Saved',
        }});
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        assert.equal(await page.locator('img[data-post-media-id="503"]').count(), 1);
        assert.match(await page.locator('[data-post-inplace-body]').textContent(), /Fresh draft text/u);
        assert.equal(await page.evaluate(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1).list().length), 0,
            'A successful restored-media save clears the local recovery copy');
        assert.deepEqual(errors, []);
    } catch (error) {
        throw new Error(`persistent recovery media: ${error.message}`, {cause: error});
    } finally {
        await page.close();
    }
    console.log('persistent recovery media: a fresh upload retains its identity through automatic snapshot, reload, restoration and save');
}
