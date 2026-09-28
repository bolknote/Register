import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const image = '<img src="/caption-image.svg" alt="Existing">';
const ordinary = '<p>Body text</p><div class="post-picture post-media-picture">'
    + image + '<div class="post-caption">Saved caption</div></div><p>After image</p>';
const overlay = attributes => '<p>Body text</p><span class="post-media-overlay" data-post-media-overlay role="figure">'
    + image + `<span class="post-media-overlay-caption"${attributes}>Saved overlay</span></span><p>After image</p>`;

async function openOverlay(page, index = 0) {
    await page.locator('.post-card.is-editing img').nth(index).click({button: 'right'});
    await page.locator('[data-context-action="edit-image-caption"]').click();
    await page.waitForFunction(() => document.activeElement?.classList.contains('is-editing-caption'));
}

function editorState(page) {
    return page.evaluate(() => {
        const state = window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
        return {
            dirty: state.bodyDirty, history: state.history.length,
            html: window.editorTest.editableBodyHtml(state),
        };
    });
}

async function completeSave(route) {
    const data = await formData(route);
    assert.equal(data.get('inplace_action'), 'edit');
    await route.fulfill({json: {
        success: true, action: 'edit', revision: 2, title: data.get('title'),
        body_html: `<div class="post body" data-post-inplace-body>${data.get('body')}</div>`,
        published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
        tags: [], scheduled: false, message: 'Saved',
    }});
}

export async function runCaptionInputRegressions(browser, origin) {
    async function withPage(body, ai, run) {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        try {
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            await page.route('**/caption-image.svg', route => route.fulfill({contentType: 'image/svg+xml', body:
                '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="100"><rect width="160" height="100" fill="gray"/></svg>',
            }));
            await page.route('**/recovery-fixture.js', async route => {
                const response = await route.fetch();
                await route.fulfill({response, body: (await response.text())
                    .replace('aiAltEnabled: false', `aiAltEnabled: ${ai}`)
                    .replace("const body = creating ? '' : `<p>Server body ${revision}</p>`;",
                        "const body = creating ? '' : " + JSON.stringify(body) + ';'),
                });
            });
            const requests = holdRequests(page, '**/_inplace/post/9');
            await requests.installed;
            await page.goto(origin + '/recovery.html');
            await page.waitForFunction(() => Array.from(document.querySelectorAll('.post-card img'))
                .every(image => image.complete && image.naturalWidth > 0));
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            await run(page, requests);
            assert.deepEqual(errors, []);
        } finally {
            await page.close();
        }
    }

    for (const finish of ['Escape', 'cancel', 'commit-unchanged']) {
        const body = '<p>' + image.replace('alt="Existing"', 'alt=""') + '</p><p>' + image + '</p><p>Body text</p>';
        await withPage(body, true, async (page, requests) => {
            const ai = await requests.next();
            assert.equal((await formData(ai)).get('inplace_action'), 'ai_alt');
            const before = await editorState(page);
            assert.equal(before.dirty, false);
            await openOverlay(page, 1);
            if (finish !== 'commit-unchanged') {
                await page.locator('.is-editing-caption').fill('Discard this caption');
                await page.locator('[data-caption-font="serif"]').click();
            }
            await ai.fulfill({json: {success: true, action: 'ai_alt', result: 'Generated first description'}});
            await page.waitForFunction(() => document.querySelector('.post-card.is-editing img')?.alt === 'Generated first description');
            if (finish === 'Escape') await page.locator('.is-editing-caption').press('Escape');
            else await page.locator(`[data-caption-action="${finish === 'cancel' ? 'cancel' : 'commit'}"]`).click();
            const after = await editorState(page);
            assert.equal(after.dirty, true, 'Finishing the caption must retain the unrelated AI change');
            assert.equal(after.html, before.html.replace('alt=""', 'alt="Generated first description"'));
            assert.equal(after.history, before.history + 1, 'The generated alt must enter the suspended history');
            await page.waitForFunction(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                .list().some(copy => copy.snapshot.body.includes('Generated first description')
                    && !copy.snapshot.body.includes('Discard this caption')));

            // The cancelled caption adds no separate undo step and never returns on redo.
            const editable = page.locator('.post-card.is-editing [data-post-inplace-body]');
            await editable.press('Control+z');
            assert.equal((await editorState(page)).html, before.html);
            await editable.press('Control+Shift+z');
            assert.equal((await editorState(page)).html, after.html);
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            const save = await requests.next();
            assert.equal((await formData(save)).get('body'), after.html);
            await completeSave(save);
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            assert.equal(await page.locator('.post-card img').first().getAttribute('alt'), 'Generated first description');
            assert.equal(await page.evaluate(() => localStorage.length), 0);
        });
    }
    console.log('caption: cancellation and unchanged commits retain concurrent AI alt in history, recovery and saving');

    for (const body of [ordinary, overlay(''), overlay(' data-caption-font="sans" data-caption-background="dark"')]) {
        for (const dirtyBefore of [false, true]) {
            await withPage(body, false, async (page, requests) => {
                if (dirtyBefore) {
                    await page.locator('.post-card.is-editing [data-post-inplace-body] > p').first().fill('Earlier body edit');
                }
                const before = await editorState(page);
                assert.equal(before.dirty, dirtyBefore);
                await openOverlay(page);
                await page.locator('.is-editing-caption').fill('Cancelled caption');
                await page.locator('.post-media-caption-toolbar [data-caption-font="serif"]').click();
                await page.locator('.post-media-caption-toolbar [data-caption-background="light"]').click();
                await page.locator('[data-caption-action="cancel"]').click();
                assert.deepEqual(await editorState(page), before, 'Cancelling restores caption text and styles without dirtying the body or history');
                await page.getByRole('button', {name: 'Save', exact: true}).click();
                if (dirtyBefore) {
                    const save = await requests.next();
                    assert.equal((await formData(save)).get('body'), before.html);
                    await completeSave(save);
                }
                await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
                assert.equal(requests.count, 0, 'A clean cancellation needs no save');
                assert.equal(await page.evaluate(() => localStorage.length), 0);
            });
        }
    }
    console.log('caption: clean cancellation adds no edit, and earlier body edits survive cancellation of new or existing overlays');

    for (const shortcut of ['Control+s', 'Meta+s']) {
        for (const surface of ['overlay', 'inline', 'alt']) {
            await withPage(ordinary, false, async (page, requests) => {
                await page.evaluate(() => {
                    document.addEventListener('keydown', event => {
                        if (event.code === 'KeyS') window.saveKeyEvent = event;
                    }, true);
                });
                const editField = async text => {
                    if (surface === 'overlay') {
                        await openOverlay(page);
                        await page.locator('.is-editing-caption').fill(text);
                    } else if (surface === 'inline') {
                        await page.locator('.post-card.is-editing .post-caption').click();
                        await page.locator('.is-editing-inline-caption').fill(text);
                    } else {
                        await page.locator('.post-card.is-editing img').click({button: 'right'});
                        await page.locator('[data-context-image-alt-input]').fill(text);
                    }
                };
                await editField('First field edit');
                await page.keyboard.press(shortcut);
                const save = await requests.next();
                const sent = (await formData(save)).get('body');
                assert.match(sent, /First field edit/u);
                assert.doesNotMatch(sent, /is-editing-caption|is-editing-inline-caption|post-media-caption-toolbar|contenteditable/u);
                assert.equal(await page.evaluate(() => window.saveKeyEvent.defaultPrevented), true, `${surface}: ${shortcut} must prevent the browser shortcut`);
                assert.equal(await page.locator('.post-editor-context-menu, .is-editing-caption, .is-editing-inline-caption').count(), 0);
                await save.fulfill({status: 503, json: {success: false, message: 'Save failed'}});
                await page.waitForFunction(() => !document.querySelector('.post-edit-save').disabled);
                assert.equal(await page.locator('.post-inplace-edit-error').textContent(), 'Save failed');
                assert.equal((await editorState(page)).html, sent);
                await editField('Retried field edit');
                await page.keyboard.press(shortcut);
                const retry = await requests.next();
                assert.match((await formData(retry)).get('body'), /Retried field edit/u);
                await completeSave(retry);
                await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
                assert.equal(await page.evaluate(() => localStorage.length), 0);
            });
        }
    }
    console.log('caption input: Ctrl/Cmd+S commits overlay, inline and alt fields, preserves failed saves and permits retry');

    for (const surface of ['alt', 'link']) {
        const linked = ordinary.replace(image, `<a href="/existing">${image}</a>`);
        await withPage(linked, false, async page => {
            const text = 'First middle last';
            const selection = locator => locator.evaluate(input => ({
                focused: document.activeElement === input, start: input.selectionStart, end: input.selectionEnd,
            }));
            await page.locator('.post-card.is-editing img').click({button: 'right'});
            if (surface === 'link') await page.locator('[data-context-action="open-link"]').click();
            const input = page.locator(surface === 'alt' ? '[data-context-image-alt-input]' : '[data-context-link-input]');
            await input.evaluate(input => input.addEventListener('keydown', event => { window.inputKeyEvent = event; }));
            await input.fill(text);
            // Home/End have platform-dependent caret/scroll behavior. Leave
            // their default action intact and keep focus in the text field.
            for (const key of ['Home', 'End', 'ArrowUp', 'ArrowDown']) {
                await input.press(key);
                assert.equal((await selection(input)).focused, true, `${surface}: ${key} must stay in the field`);
                assert.equal(await page.evaluate(() => window.inputKeyEvent.defaultPrevented), false, `${surface}: ${key} must retain its native action`);
            }
            await input.fill(text);
            await input.press('Shift+Home');
            assert.deepEqual(await selection(input), {focused: true, start: 0, end: text.length});
            assert.equal(await page.evaluate(() => window.inputKeyEvent.defaultPrevented), false);
            await input.press('ArrowLeft');
            await input.press('Shift+End');
            assert.deepEqual(await selection(input), {focused: true, start: 0, end: text.length});
            assert.equal(await page.evaluate(() => window.inputKeyEvent.defaultPrevented), false);
            await input.fill(surface === 'link' ? '/updated-link' : 'Updated description');
            await input.press('Enter');
            assert.equal(await page.locator('.post-editor-context-menu').count(), 0);
            assert.equal(await page.locator('.post-card.is-editing img').getAttribute('alt'), surface === 'alt' ? 'Updated description' : 'Existing');
            if (surface === 'link') assert.equal(await page.locator('.post-card.is-editing a[href="/updated-link"]').count(), 1);

            await page.locator('.post-card.is-editing img').click({button: 'right'});
            const buttons = page.locator('.post-editor-image-panel button');
            await page.keyboard.press('End');
            assert.equal(await buttons.last().evaluate(button => document.activeElement === button), true);
            await page.keyboard.press('ArrowDown');
            assert.equal(await buttons.first().evaluate(button => document.activeElement === button), true);
            await page.keyboard.press('ArrowUp');
            assert.equal(await buttons.last().evaluate(button => document.activeElement === button), true);
            await page.keyboard.press('Home');
            assert.equal(await buttons.first().evaluate(button => document.activeElement === button), true);
            if (surface === 'link') {
                await page.locator('[data-context-action="open-link"]').click();
                await input.fill('/cancelled-link');
                await input.press('Escape');
                assert.equal(await page.locator('.post-editor-link-panel').isHidden(), true);
                assert.equal(await page.locator('.post-card.is-editing a[href="/updated-link"]').count(), 1);
            } else {
                await input.focus();
            }
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('.post-editor-context-menu').count(), 0);
            assert.equal(await page.locator('.post-card.is-editing').count(), 1, 'Escape in the menu must leave the post open');
        });
    }
    console.log('context inputs: Home/End, selection and arrows edit text; buttons, Enter and Escape retain menu navigation');
}
