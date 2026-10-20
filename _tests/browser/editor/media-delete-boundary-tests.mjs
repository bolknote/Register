import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {formData, holdRequests} from './save-tests.mjs';

const image = '<img src="/delete-boundary.svg" width="160" height="100" alt="Photo">';
const media = '<div class="post-picture post-media-picture">' + image
    + '<div class="post-caption">Saved caption</div></div>';
const initial = '<p>Before the photograph.</p>' + media + '<p>After the photograph.</p>';

async function snapshot(page) {
    return page.evaluate(() => {
        const state = editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
        const picture = state.body.querySelector('.post-media-picture');
        const image = picture?.querySelector('img');
        const caption = picture?.querySelector('.post-caption');
        const selection = getSelection();
        const range = selection?.rangeCount === 1 ? selection.getRangeAt(0) : null;
        const index = picture ? Array.from(state.body.childNodes).indexOf(picture) : -1;
        return {
            html: editorTest.editableBodyHtml(state), history: state.history.length,
            imageCount: state.body.querySelectorAll('img').length,
            caption: caption?.textContent,
            together: Boolean(image && caption && image.closest('.post-media-picture') === caption.parentElement),
            selectedMedia: Boolean(picture && range && range.startContainer === state.body
                && range.endContainer === state.body && range.startOffset === index && range.endOffset === index + 1),
            captionGap: image && caption ? caption.getBoundingClientRect().top - image.getBoundingClientRect().bottom : null,
        };
    });
}

export async function runMediaDeleteBoundaryRegressions(browser, origin) {
    for (const mode of ['backspace-after', 'backspace-body-after', 'backspace-wrapper-after',
        'delete-before', 'delete-body-before', 'delete-wrapper-before',
        'backspace-caption-start', 'backspace-empty-caption-start',
        'copy-text-and-backspace-after', 'insert-mid-copy-and-backspace-after',
        'delete-character', 'backspace-character', 'delete-whole-picture']) {
        const page = await browser.newPage();
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        try {
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            await page.route('**/delete-boundary.svg', route => route.fulfill({
                contentType: 'image/svg+xml',
                body: '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="100"><rect width="160" height="100" fill="gray"/></svg>',
            }));
            await page.route('**/recovery-fixture.js', async route => {
                const response = await route.fetch();
                let bodyHtml = mode.startsWith('insert-mid')
                    ? '<p>Before the photograph.After the photograph.</p>' : initial;
                if (mode === 'backspace-empty-caption-start') bodyHtml = bodyHtml.replace('Saved caption', '');
                await route.fulfill({response, body: (await response.text()).replace(
                    /const body = creating \? '' : [^\n]+;/u,
                    'const body = creating ? "" : ' + JSON.stringify(bodyHtml) + ';',
                )});
            });
            const requests = holdRequests(page, '**/_inplace/post/9');
            await requests.installed;
            await page.goto(origin + '/recovery.html');
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
            if (mode.startsWith('insert-mid')) {
                await body.evaluate(async body => {
                    const range = document.createRange();
                    range.setStart(body.querySelector('p').firstChild, 'Before the photograph.'.length);
                    range.collapse(true);
                    body.focus();
                    getSelection().removeAllRanges();
                    getSelection().addRange(range);
                    const canvas = document.createElement('canvas');
                    canvas.width = 160;
                    canvas.height = 100;
                    canvas.getContext('2d').fillRect(0, 0, 160, 100);
                    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
                    const transfer = new DataTransfer();
                    transfer.items.add(new File([blob], 'middle.png', {type: 'image/png'}));
                    const paste = new Event('paste', {bubbles: true, cancelable: true});
                    Object.defineProperty(paste, 'clipboardData', {value: transfer});
                    body.dispatchEvent(paste);
                });
                const upload = await requests.next();
                assert.equal((await formData(upload)).get('inplace_action'), 'media');
                await upload.fulfill({json: {
                    success: true, action: 'media', kind: 'image', media_id: 44,
                    url: '/delete-boundary.svg', width: 160, height: 100,
                }});
                await page.waitForFunction(() => editorTest.editorStates.get(document.querySelector('.post-card.is-editing')).mediaUploads.size === 0);
                assert.deepEqual(await body.locator(':scope > p').allTextContents(),
                    ['Before the photograph.', 'After the photograph.']);
                await body.locator('.post-caption').click();
                await body.locator('.is-editing-inline-caption').fill('Saved caption');
                await body.locator(':scope > p').last().click();
            }
            assert.equal(await body.locator('.post-caption').getAttribute('contenteditable'), 'false');
            const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');

            if (mode.includes('copy')) {
                await body.evaluate(body => {
                    const range = document.createRange();
                    range.selectNodeContents(body.querySelector(':scope > p:last-child'));
                    body.focus();
                    getSelection().removeAllRanges();
                    getSelection().addRange(range);
                });
                await body.press(modifier + '+c');
                await body.evaluate(body => {
                    const range = document.createRange();
                    range.selectNodeContents(body.querySelector(':scope > p:first-child'));
                    range.collapse(false);
                    body.focus();
                    getSelection().removeAllRanges();
                    getSelection().addRange(range);
                });
                await body.press(modifier + '+v');
                const copied = (await snapshot(page)).html;
                assert.ok(copied.indexOf('After the photograph.') < copied.indexOf('<img'),
                    'The lower text must actually be copied before the image');
            }
            const before = await snapshot(page);
            if (mode.endsWith('caption-start')) await body.locator('.post-caption').click();
            await body.evaluate((body, mode) => {
                const picture = body.querySelector('.post-media-picture');
                const previous = picture.previousElementSibling;
                const next = picture.nextElementSibling;
                const range = document.createRange();
                if (mode === 'delete-whole-picture') range.selectNode(picture);
                else if (mode.endsWith('caption-start')) range.setStart(picture.querySelector('.post-caption'), 0);
                else if (mode === 'delete-wrapper-before') range.setStart(picture, 0);
                else if (mode === 'backspace-wrapper-after') range.setStart(picture, 1);
                else if (mode === 'delete-body-before') range.setStart(body, Array.from(body.childNodes).indexOf(picture));
                else if (mode === 'backspace-body-after') range.setStart(body, Array.from(body.childNodes).indexOf(picture) + 1);
                else if (mode === 'delete-before') range.setStart(previous, previous.childNodes.length);
                else if (mode === 'delete-character') range.setStart(previous.firstChild, 3);
                else if (mode === 'backspace-character') range.setStart(next.firstChild, 3);
                else range.setStart(next.firstChild, 0);
                if (mode !== 'delete-whole-picture') range.collapse(true);
                if (mode.endsWith('caption-start')) picture.querySelector('.post-caption').focus();
                else body.focus();
                getSelection().removeAllRanges();
                getSelection().addRange(range);
            }, mode);
            await page.keyboard.press(mode.startsWith('delete') ? 'Delete' : 'Backspace');
            const after = await snapshot(page);
            const context = browser.browserType().name() + ': ' + mode;
            if (mode === 'delete-whole-picture') {
                assert.equal(after.imageCount, 0, context + ': explicitly selected media can still be deleted');
                assert.equal(await body.locator('.post-caption').count(), 0, context + ': its caption is deleted with it');
                assert.match(after.html, /Before the photograph\./u);
                assert.match(after.html, /After the photograph\./u);
            } else {
                assert.equal(after.imageCount, 1, context + ': a paragraph boundary must not delete the unselected image');
                assert.equal(after.caption, mode === 'backspace-empty-caption-start' ? '' : 'Saved caption',
                    context + ': a paragraph boundary must not delete the caption');
                assert.ok(after.together, context + ': image and caption must keep their shared wrapper');
                assert.ok(Math.abs(after.captionGap - before.captionGap) < 1, context + ': the caption must not detach visually');
                if (mode.endsWith('character')) {
                    assert.notEqual(after.html, before.html, context + ': ordinary character deletion still works');
                } else {
                    assert.equal(after.html, before.html, context + ': selecting media does not change its content');
                    assert.equal(after.history, before.history, context + ': selecting media creates no history step');
                    assert.equal(after.selectedMedia, true, context + ': the whole block is selected for the next deletion');
                }
            }

            if (!mode.endsWith('character') && mode !== 'delete-whole-picture') {
                await page.keyboard.press(mode.startsWith('delete') ? 'Delete' : 'Backspace');
                const deleted = await snapshot(page);
                assert.equal(deleted.imageCount, 0, context + ': repeated deletion removes the selected image');
                assert.equal(await body.locator('.post-caption').count(), 0, context + ': its caption is removed with it');
                assert.equal(await body.locator('p').first().textContent(), mode.includes('copy')
                    ? 'Before the photograph.After the photograph.' : 'Before the photograph.');
                assert.equal(await body.locator('p').last().textContent(), 'After the photograph.');
            }
            const changed = await snapshot(page);
            await body.press(modifier + '+z');
            assert.equal((await snapshot(page)).html, before.html, context + ': undo preserves the original picture and caption');
            await body.press(modifier + '+Shift+z');
            assert.equal((await snapshot(page)).html, changed.html, context + ': redo preserves the exact edited content');
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            let save = await requests.next();
            let data = await formData(save);
            if (data.get('inplace_action') === 'media_redate') {
                await save.fulfill({json: {success: true, media: [{media_id: 44, url: '/delete-boundary.svg'}]}});
                save = await requests.next();
                data = await formData(save);
            }
            assert.equal(data.get('inplace_action'), 'edit');
            assert.equal(data.get('body'), changed.html, context + ': saving preserves every remaining node');
            await save.fulfill({json: {
                success: true, action: 'edit', revision: 2, title: data.get('title'),
                body_html: '<div class="post body" data-post-inplace-body>' + changed.html + '</div>',
                published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
                tags: [], scheduled: false, message: 'Saved',
            }});
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            assert.equal((await snapshot(page)).html, changed.html, context + ': reopening retains the saved structure');
            assert.deepEqual(errors, []);
        } finally { await page.close(); }
    }
    console.log('media deletion: paragraph/root/wrapper boundaries and captions select whole media for repeated deletion; text, clipboard moves, history, saving and reopening remain correct');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const [name, engine] of Object.entries({chromium, firefox, webkit})) {
            if (process.env.EDITOR_TEST_BROWSER && process.env.EDITOR_TEST_BROWSER !== name) continue;
            const browser = await engine.launch();
            try { await runMediaDeleteBoundaryRegressions(browser, 'http://127.0.0.1:' + server.address().port); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
