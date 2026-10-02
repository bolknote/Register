import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const image = '<img src="/caption-boundary.svg" alt="Existing">';
const ordinary = '<p>Body text</p><div class="post-picture post-media-picture">'
    + image + '<div class="post-caption">Saved caption</div></div><p>After image</p>';
const overlay = '<p>Body text</p><span class="post-media-overlay" data-post-media-overlay="" role="figure">'
    + image + '<span class="post-media-overlay-caption" data-caption-font="sans" data-caption-background="dark">'
    + 'Saved overlay</span></span><p>After image</p>';

function editorState(page) {
    return page.evaluate(() => {
        const state = window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
        return {
            html: window.editorTest.editableBodyHtml(state), history: state.history.length,
            editable: state.body.contentEditable, focused: document.activeElement === state.body,
            inline: state.mediaCaptionEditors.size, overlay: Boolean(state.imageCaptionEditor),
        };
    });
}

async function openCaption(page, surface) {
    if (surface === 'inline') {
        await page.locator('.post-card.is-editing .post-caption').click();
        return page.locator('.is-editing-inline-caption');
    }
    const image = page.locator('.post-card.is-editing img');
    // Dismiss any earlier text selection before opening image tools.
    await page.locator('.post-card.is-editing [data-post-inplace-body] > p').last().click();
    await image.click({button: 'right'});
    await page.locator('[data-context-action="edit-image-caption"]').click();
    await page.waitForFunction(() => document.activeElement?.classList.contains('is-editing-caption'));
    return page.locator('.is-editing-caption');
}

async function recoverAndSave(page, requests, html) {
    await page.waitForFunction(html => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
        .list().some(copy => copy.snapshot.body === html), html);
    await page.getByRole('button', {name: 'Save', exact: true}).click();
    const save = await requests.next();
    const data = await formData(save);
    assert.equal(data.get('inplace_action'), 'edit');
    assert.equal(data.get('body'), html);
    await save.fulfill({json: {
        success: true, action: 'edit', revision: 2, title: data.get('title'),
        body_html: `<div class="post body" data-post-inplace-body>${html}</div>`,
        published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
        tags: [], scheduled: false, message: 'Saved',
    }});
    await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
    assert.equal(await page.evaluate(() => localStorage.length), 0);
}

export async function runCaptionBoundaryRegressions(browser, origin) {
    async function withPage(body, run) {
        const page = await browser.newPage();
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        try {
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            await page.route('**/caption-boundary.svg', route => route.fulfill({contentType: 'image/svg+xml', body:
                '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="360"><rect width="640" height="360" fill="gray"/></svg>',
            }));
            await page.route('**/recovery.html', async route => {
                const response = await route.fetch();
                await route.fulfill({response, body: (await response.text()).replace(
                    '<div class="post-editor-context-main">',
                    '<div class="post-editor-context-main">'
                        + '<button type="button" data-context-action="cut" data-context-selection-only>Cut</button>',
                )});
            });
            await page.route('**/recovery-fixture.js', async route => {
                const response = await route.fetch();
                await route.fulfill({response, body: (await response.text()).replace(
                    "const body = creating ? '' : `<p>Server body ${revision}</p>`;",
                    "const body = creating ? '' : " + JSON.stringify(body) + ';',
                )});
            });
            const requests = holdRequests(page, '**/_inplace/post/9');
            await requests.installed;
            await page.goto(origin + '/recovery.html');
            await page.waitForFunction(() => Array.from(document.querySelectorAll('.post-card img'))
                .every(image => image.complete && image.naturalWidth > 0));
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
            await run(page, requests, modifier);
            assert.deepEqual(errors, []);
        } finally {
            await page.close();
        }
    }

    for (const operation of ['replace', 'Backspace', 'cut', 'menu-cut']) {
        await withPage(ordinary, async (page, requests, modifier) => {
            const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
            const before = await editorState(page);
            const paragraph = body.locator(':scope > p').first();
            await paragraph.click({clickCount: 3});
            assert.equal(await page.evaluate(() => getSelection().getRangeAt(0).toString()), 'Body text');
            if (operation === 'replace') await page.keyboard.insertText('Replacement paragraph');
            else if (operation === 'Backspace') await page.keyboard.press('Backspace');
            else if (operation === 'cut') await page.keyboard.press(`${modifier}+x`);
            else {
                await paragraph.click({button: 'right'});
                await page.locator('[data-context-action="cut"]').click();
            }
            assert.equal(await body.locator('.post-media-picture img').count(), 1, `${operation}: media must keep its wrapper`);
            assert.equal(await body.locator('.post-media-picture .post-caption').textContent(), 'Saved caption');
            const after = await editorState(page);
            assert.match(after.html, /Saved caption/u);
            assert.doesNotMatch(after.html, /Body text/u);
            if (operation === 'replace') assert.match(after.html, /Replacement paragraph/u);
            await body.press(`${modifier}+z`);
            assert.equal((await editorState(page)).html, before.html);
            await body.press(`${modifier}+Shift+z`);
            assert.equal((await editorState(page)).html, after.html);
            await recoverAndSave(page, requests, after.html);
        });
    }
    console.log('media boundary: replacing or cutting a preceding paragraph retains the image and caption through undo, recovery and saving');

    await withPage(ordinary.replace('</p><div', '</p>\n<div'), async (page, requests, modifier) => {
        const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
        await body.focus();
        // A backwards selection ending at the next media block has the same
        // boundary, including inter-block whitespace. A selection that actually
        // includes the image must still cut it.
        await body.evaluate(body => {
            getSelection().setBaseAndExtent(body.querySelector('.post-media-picture'), 0, body.firstChild.firstChild, 0);
        });
        await page.keyboard.insertText('Backwards replacement');
        assert.equal(await body.locator('.post-media-picture img').count(), 1);
        assert.match((await editorState(page)).html, /Saved caption/u);
        await body.press(`${modifier}+z`);
        await body.evaluate(body => {
            const range = document.createRange();
            range.setStart(body, 0);
            range.setEndAfter(body.querySelector('.post-media-picture'));
            getSelection().removeAllRanges();
            getSelection().addRange(range);
        });
        await page.keyboard.press('Backspace');
        assert.equal(await body.locator('img').count(), 0);
        assert.doesNotMatch((await editorState(page)).html, /Saved caption/u);
        await recoverAndSave(page, requests, (await editorState(page)).html);
    });
    console.log('media boundary: backwards text selections preserve media, while explicitly selected media can still be deleted');

    for (const initial of [ordinary, overlay]) {
        for (const finish of ['cancel', 'commit']) {
            await withPage(initial, async (page, requests, modifier) => {
                const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
                await body.locator(':scope > p').first().fill('Earlier body edit');
                const before = await editorState(page);
                const caption = await openCaption(page, 'overlay');
                const original = await caption.textContent();
                await page.keyboard.press(`${modifier}+a`);
                await page.keyboard.insertText('Replacement overlay');
                await page.keyboard.press(`${modifier}+z`);
                assert.equal(await caption.textContent(), original);
                await page.keyboard.press(`${modifier}+z`);
                await page.keyboard.press(`${modifier}+z`);
                assert.equal(await body.locator(':scope > p').first().textContent(), 'Earlier body edit');
                await page.keyboard.press(`${modifier}+Shift+z`);
                assert.equal(await caption.textContent(), 'Replacement overlay');
                // Native Edit-menu undo/redo arrives through beforeinput.
                for (const [inputType, expected] of [
                    ['historyUndo', original], ['historyUndo', original], ['historyRedo', 'Replacement overlay'],
                ]) {
                    assert.equal(await caption.evaluate((caption, inputType) => {
                        const event = new InputEvent('beforeinput', {inputType, bubbles: true, cancelable: true});
                        caption.dispatchEvent(event);
                        return event.defaultPrevented;
                    }, inputType), true);
                    assert.equal(await caption.textContent(), expected);
                    assert.equal(await body.locator(':scope > p').first().textContent(), 'Earlier body edit');
                }
                if (finish === 'cancel') await page.keyboard.press('Escape');
                else await page.keyboard.press(`${modifier}+Enter`);
                const after = await editorState(page);
                assert.equal(after.editable, 'true');
                if (finish === 'cancel') {
                    assert.equal(after.html, before.html);
                    assert.equal(after.history, before.history);
                } else {
                    assert.equal(after.history, before.history + 1);
                    await body.press(`${modifier}+z`);
                    assert.equal((await editorState(page)).html, before.html);
                    await body.press(`${modifier}+Shift+z`);
                    assert.equal((await editorState(page)).html, after.html);
                }
                await recoverAndSave(page, requests, after.html);
            });
        }
    }
    console.log('overlay caption: keyboard and native-menu undo stay local, cancellation retains body edits, and commit adds one history step');

    for (const surface of ['inline', 'overlay']) {
        for (const action of ['whole', 'selection', 'stale']) {
            await withPage(surface === 'inline' ? ordinary : overlay, async (page, requests, modifier) => {
                const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
                const paragraph = body.locator(':scope > p').first();
                await paragraph.click();
                if (action === 'selection') {
                    await paragraph.evaluate(paragraph => {
                        const range = document.createRange();
                        range.selectNodeContents(paragraph);
                        getSelection().removeAllRanges();
                        getSelection().addRange(range);
                    });
                }
                await paragraph.click({button: 'right'});
                await page.locator('[data-context-ai-action="proofread"]').click();
                const ai = await requests.next();
                const data = await formData(ai);
                assert.equal(data.get('ai_action'), 'proofread');
                const source = data.get('text');
                if (action === 'selection') assert.equal(source, 'Body text');
                else assert.match(source, /<img /u);
                const caption = await openCaption(page, surface);
                if (action !== 'whole') await caption.fill('Concurrent caption edit');
                await ai.fulfill({json: {
                    success: true, action: 'ai', ai_action: 'proofread', result: source.replace('Body text', 'Corrected body'),
                }});
                await page.waitForFunction(() => !document.querySelector('.post-card.is-ai-working'));
                if (action === 'stale') {
                    assert.equal(await caption.textContent(), 'Concurrent caption edit');
                    assert.equal(await paragraph.textContent(), 'Body text');
                    await page.keyboard.press(surface === 'inline' ? 'Enter' : `${modifier}+Enter`);
                } else {
                    const applied = await editorState(page);
                    assert.equal(applied.inline, 0);
                    assert.equal(applied.overlay, false);
                    assert.equal(applied.editable, 'true');
                    assert.equal(applied.focused, true);
                    assert.equal(await paragraph.textContent(), 'Corrected body');
                    if (action === 'selection') assert.match(applied.html, /Concurrent caption edit/u);
                    await page.keyboard.press(`${modifier}+z`);
                    assert.equal(await paragraph.textContent(), 'Body text');
                    if (action === 'selection') assert.match((await editorState(page)).html, /Concurrent caption edit/u);
                    await page.keyboard.press(`${modifier}+Shift+z`);
                    assert.equal((await editorState(page)).html, applied.html);
                    await page.keyboard.insertText('Continued typing');
                    assert.match((await editorState(page)).html, /Continued typing/u);
                }
                await recoverAndSave(page, requests, (await editorState(page)).html);
            });
        }
    }
    console.log('caption AI: whole and selected replies finish active sessions and restore typing/history; stale replies preserve edited captions');

    for (const surface of ['inline', 'overlay']) {
        await withPage(surface === 'inline' ? ordinary : overlay, async (page, requests, modifier) => {
            const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
            await body.locator(':scope > p').first().click({button: 'right'});
            assert.equal(await page.locator('.post-editor-context-menu').count(), 1, 'Body text retains the post menu');
            await page.keyboard.press('Escape');
            const caption = await openCaption(page, surface);
            const original = await caption.textContent();
            await page.keyboard.press(`${modifier}+a`);
            await page.evaluate(() => document.addEventListener('contextmenu', event => { window.captionMenuEvent = event; }, true));
            await caption.click({button: 'right'});
            assert.equal(await page.locator('.post-editor-context-menu').count(), 0);
            assert.deepEqual(await caption.evaluate(caption => ({
                prevented: window.captionMenuEvent.defaultPrevented,
                selected: getSelection().toString(), focused: document.activeElement === caption,
            })), {prevented: false, selected: original, focused: true});
            // Headless browsers do not expose their OS menu to Playwright. The
            // same native Cut command is available through its keyboard shortcut.
            await page.keyboard.press(`${modifier}+x`);
            assert.equal(await caption.textContent(), '');
            await page.keyboard.press(`${modifier}+z`);
            assert.equal(await caption.textContent(), original);
            await page.keyboard.press(`${modifier}+a`);
            await page.keyboard.insertText('Caption after menu');
            await page.keyboard.press(surface === 'inline' ? 'Enter' : `${modifier}+Enter`);
            assert.equal(await body.locator(':scope > p').first().textContent(), 'Body text');
            await recoverAndSave(page, requests, (await editorState(page)).html);
        });
    }
    console.log('caption menu: native clipboard menus preserve the selection and session in both caption types');
}
