import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {formData, holdRequests} from './save-tests.mjs';

const image = '<img class="post-media-image" src="/responsive-image.svg" width="320" height="180" alt="Example image">';
const caption = 'An example caption.';
const picture = '<div class="post-picture post-media-picture">' + image
    + '<div class="post-caption">' + caption + '</div></div>';

async function openEditor(browser, origin, html) {
    const page = await browser.newPage();
    page.setDefaultTimeout(10000);
    await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
    await page.route('**/recovery-fixture.js', async route => {
        const response = await route.fetch();
        await route.fulfill({response, body: (await response.text()).replace(
            /const body = creating \? '' : [^\n]+;/u,
            'const body = creating ? "" : ' + JSON.stringify(html) + ';',
        )});
    });
    const requests = holdRequests(page, '**/_inplace/post/9');
    await requests.installed;
    await page.goto(origin + '/recovery.html');
    await page.getByRole('button', {name: 'Edit', exact: true}).click();
    return {page, requests, body: page.locator('.post-card.is-editing [data-post-inplace-body]'),
        modifier: await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control')};
}

async function savedHtml(page) {
    return page.evaluate(() => editorTest.editableBodyHtml(
        editorTest.editorStates.get(document.querySelector('.post-card.is-editing')),
    ));
}

async function saveAndReopen(page, requests, expected) {
    await page.getByRole('button', {name: 'Save', exact: true}).click();
    const save = await requests.next();
    const data = await formData(save);
    assert.equal(data.get('body'), expected);
    assert.doesNotMatch(expected, /is-inline-caption-entry|is-editing-inline-caption|contenteditable|data-placeholder/u);
    await save.fulfill({json: {
        success: true, action: 'edit', revision: 2, title: data.get('title'),
        body_html: `<div class="post body" data-post-inplace-body>${expected}</div>`,
        published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
        tags: [], scheduled: false, message: 'Saved',
    }});
    await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
    await page.getByRole('button', {name: 'Edit', exact: true}).click();
    assert.equal(await savedHtml(page), expected, 'Saving and reopening must retain the repaired media');
}

export async function runMediaCaptionRecoveryRegressions(browser, origin) {
    for (const mode of ['bare', 'linked', 'responsive', 'nested', 'semantic', 'inline-control']) {
        let visual = image;
        if (mode === 'linked') visual = '<a href="https://example.test/full">' + image + '</a>';
        if (mode === 'responsive') visual = '<picture><source srcset="/responsive-image.svg">' + image + '</picture>';
        if (mode === 'nested') visual = '<em>Emphasized start.' + image + 'Emphasized end.</em>';
        if (mode === 'inline-control') visual = image.replace(' class="post-media-image"', '');
        const detached = mode === 'semantic'
            ? '<div class="post-caption">' + caption + '</div>'
            : '<span data-detached-caption style="font-size:13px">' + caption + '</span>';
        const initial = '<p>Before.' + visual + (mode === 'semantic' ? '</p>' + detached : detached + '</p>') + '<p>After.</p>';
        const {page, body, modifier, requests} = await openEditor(browser, origin, initial);
        try {
            if (mode === 'inline-control') {
                assert.equal(await body.locator('.post-media-picture').count(), 0, 'Authored inline images must not become block media');
                assert.equal(await body.locator('p img').count(), 1);
                continue;
            }
            assert.equal(await body.locator('.post-media-picture').count(), 1, mode + ': an editor-owned image regains its block');
            assert.equal(await body.locator('p .post-media-picture').count(), 0, 'A block image cannot stay nested in a paragraph');
            assert.equal(await body.locator('.post-media-picture > .post-caption').count(), 1, 'The repaired image exposes a caption field');
            if (mode !== 'semantic') {
                const loose = body.locator('[data-detached-caption]');
                assert.equal(await loose.textContent(), caption, 'Ordinary adjacent text must never be guessed to be a caption');
                await loose.evaluate(element => {
                    const range = document.createRange(); range.selectNodeContents(element);
                    element.closest('[data-post-inplace-body]').focus();
                    getSelection().removeAllRanges(); getSelection().addRange(range);
                });
                await page.keyboard.press(`${modifier}+x`);
                await body.locator('.is-inline-caption-entry').click();
                await page.locator('.is-editing-inline-caption').evaluate((element, text) => {
                    const transfer = new DataTransfer(); transfer.setData('text/plain', text);
                    const event = new Event('paste', {bubbles: true, cancelable: true});
                    Object.defineProperty(event, 'clipboardData', {value: transfer});
                    element.dispatchEvent(event);
                }, caption);
                await page.keyboard.press('Enter');
            }
            assert.equal(await body.locator('.post-media-picture > .post-caption').textContent(), caption);
            assert.equal((await body.textContent()).split(caption).length - 1, 1, 'Reattaching cannot duplicate the caption');
            const gap = await body.locator('.post-media-picture').evaluate(media =>
                media.querySelector('.post-caption').getBoundingClientRect().top
                - media.querySelector('img').getBoundingClientRect().bottom);
            assert.ok(gap >= 0 && gap < 12, mode + ': the caption must stay visually attached to the image');
            if (mode === 'linked') assert.equal(await body.locator('.post-media-picture a').getAttribute('href'), 'https://example.test/full');
            if (mode === 'responsive') assert.equal(await body.locator('.post-media-picture picture source').count(), 1);
            const expected = await savedHtml(page);
            if (mode !== 'semantic') {
                await body.press(`${modifier}+z`);
                assert.equal(await body.locator('.post-media-picture > .post-caption').textContent(), '');
                await body.press(`${modifier}+Shift+z`);
                assert.equal(await savedHtml(page), expected);
            } else {
                // Normalizing the editor alone must not force an unchanged save.
                await body.locator('p').last().click();
                await page.keyboard.insertText(' More text.');
            }
            await saveAndReopen(page, requests, await savedHtml(page));
            assert.equal(await body.locator('.post-media-picture > .post-caption').textContent(), caption);
        } finally {
            await page.close();
        }
    }
    console.log('caption recovery: editor-owned bare/linked/responsive/nested media regain editable captions; ordinary inline images and adjacent prose remain intact through history and save/reopen');

    for (const mode of ['contents', 'partial-caption', 'copy-with-prose', 'cut', 'text-only']) {
        const {page, body, modifier, requests} = await openEditor(browser, origin,
            '<p>Before.</p>' + picture + '<p>Destination.</p>');
        try {
            await body.evaluate((body, mode) => {
                document.addEventListener(mode === 'cut' ? 'cut' : 'copy', event => {
                    window.captionClipboard = {handled: event.defaultPrevented, html: event.clipboardData.getData('text/html')};
                }, {once: true});
                const media = body.querySelector('.post-media-picture');
                const range = document.createRange();
                if (mode === 'text-only') range.setStart(media.querySelector('.post-caption').firstChild, 0);
                else if (mode === 'copy-with-prose') range.setStart(body.querySelector('p').firstChild, 0);
                else range.setStart(media, 0);
                const text = media.querySelector('.post-caption').firstChild;
                if (mode === 'contents' || mode === 'cut') range.setEnd(media, media.childNodes.length);
                else range.setEnd(text, mode === 'partial-caption' ? 3 : text.length);
                body.focus(); getSelection().removeAllRanges(); getSelection().addRange(range);
            }, mode);
            await page.keyboard.press(`${modifier}+${mode === 'cut' ? 'x' : 'c'}`);
            const packet = await page.evaluate(() => window.captionClipboard);
            if (mode === 'text-only') {
                assert.equal(packet.handled, false, 'Copying only caption text must not copy its image');
                continue;
            }
            assert.equal(packet.handled, true, mode + ': copying complete media must preserve semantic HTML instead of native computed styles');
            assert.match(packet.html, /class="post-picture post-media-picture"/u);
            assert.match(packet.html, /An example caption\./u, 'A selected image always retains its complete caption');
            await body.evaluate(body => {
                const range = document.createRange();range.selectNodeContents(body.lastElementChild);range.collapse(false);
                body.focus();getSelection().removeAllRanges();getSelection().addRange(range);
            });
            await page.keyboard.press(`${modifier}+v`);
            const count = mode === 'cut' ? 1 : 2;
            // GTK WebKit can read the system clipboard asynchronously.
            try {
                await body.locator('.post-media-picture').nth(count - 1).waitFor({state: 'attached'});
            } catch (error) {
                throw new Error(browser.browserType().name() + ' ' + mode
                    + ': pasted media did not appear: ' + await body.innerHTML(), {cause: error});
            }
            assert.equal(await body.locator('.post-media-picture').count(), count);
            assert.equal(await body.locator('.post-media-picture > .post-caption').count(), count);
            assert.deepEqual(await body.locator('.post-media-picture > .post-caption').allTextContents(), Array(count).fill(caption));
            assert.equal(await body.locator('p .post-media-picture').count(), 0);
            const expected = await savedHtml(page);
            await body.press(`${modifier}+z`);
            assert.equal(await body.locator('.post-media-picture').count(), mode === 'cut' ? 0 : 1);
            await body.press(`${modifier}+Shift+z`);
            assert.equal(await savedHtml(page), expected);
            await saveAndReopen(page, requests, expected);
        } finally {
            await page.close();
        }
    }
    console.log('caption clipboard: real copy/cut/paste retain the complete media wrapper and caption, including partial selection endpoints, undo/redo and saving');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit]) {
            if (process.env.EDITOR_TEST_BROWSER && process.env.EDITOR_TEST_BROWSER !== engine.name()) continue;
            const browser = await engine.launch();
            try { await runMediaCaptionRecoveryRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
