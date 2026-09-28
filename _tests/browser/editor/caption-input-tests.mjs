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

    for (const original of ['Saved caption', '']) {
        await withPage(ordinary.replace('Saved caption', original), false, async (page, requests) => {
            const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
            await body.locator(':scope > p').first().fill('Earlier body edit');
            const before = await editorState(page);
            await page.locator('.post-card.is-editing .post-caption').click();
            const caption = page.locator('.is-editing-inline-caption');
            const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
            await page.keyboard.press(`${modifier}+a`);
            assert.deepEqual(await caption.evaluate(caption => ({
                text: getSelection().toString(),
                contained: caption.contains(getSelection().anchorNode) && caption.contains(getSelection().focusNode),
            })), {text: original, contained: true}, 'Select All must stay inside the caption');
            await page.keyboard.insertText('Replacement caption');
            assert.equal(await caption.textContent(), 'Replacement caption');
            assert.equal(await body.locator('img').count(), 1);
            assert.equal(await body.locator(':scope > p').first().textContent(), 'Earlier body edit');
            await page.keyboard.press(`${modifier}+z`);
            assert.equal(await caption.textContent(), original, 'Undo must restore the caption');
            await page.keyboard.press(`${modifier}+Shift+z`);
            assert.equal(await caption.textContent(), 'Replacement caption');
            await page.keyboard.press(`${modifier}+z`);
            await page.keyboard.press(`${modifier}+z`);
            assert.equal(await body.locator(':scope > p').first().textContent(), 'Earlier body edit', 'Caption undo must not change prior body edits');
            await page.keyboard.press(`${modifier}+a`);
            await page.keyboard.insertText('Replacement caption');
            await page.keyboard.press('Enter');
            assert.equal(await body.getAttribute('contenteditable'), 'true');
            assert.equal((await editorState(page)).history, before.history + 1, 'Caption typing is one body history step');
            await body.press(`${modifier}+z`);
            assert.equal((await editorState(page)).html, before.html);
            await body.press(`${modifier}+Shift+z`);
            const after = await editorState(page);
            assert.match(after.html, /Earlier body edit/u);
            assert.match(after.html, /Replacement caption/u);
            assert.match(after.html, /<img /u);
            await body.press(`${modifier}+s`);
            const save = await requests.next();
            assert.equal((await formData(save)).get('body'), after.html);
            await completeSave(save);
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        });
    }
    console.log('inline caption: Select All and keyboard undo/redo stay local, retain body edits and save the complete post');

    await withPage(ordinary, false, async page => {
        const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
        await body.locator(':scope > p').first().fill('Earlier body edit');
        await body.locator('.post-caption').click();
        const caption = page.locator('.is-editing-inline-caption');
        await caption.fill('Replacement caption');
        for (const [inputType, expected] of [
            ['historyUndo', 'Saved caption'],
            ['historyUndo', 'Saved caption'],
            ['historyRedo', 'Replacement caption'],
        ]) {
            // Browser Edit-menu actions can arrive without a keyboard shortcut.
            assert.equal(await caption.evaluate((caption, inputType) => {
                const event = new InputEvent('beforeinput', {inputType, bubbles: true, cancelable: true});
                caption.dispatchEvent(event);
                return event.defaultPrevented;
            }, inputType), true);
            assert.equal(await caption.textContent(), expected);
            assert.equal(await body.locator(':scope > p').first().textContent(), 'Earlier body edit');
            assert.equal(await caption.evaluate(caption => caption.contains(getSelection().anchorNode)), true);
        }
    });
    console.log('inline caption: beforeinput undo/redo is local and restores the caption selection');

    for (const finish of ['Enter', 'Escape', 'Tab']) {
        const body = ordinary.replace('<p>Body text</p>', '<p>Body text</p><p>' + image.replace('alt="Existing"', 'alt=""') + '</p>');
        await withPage(body, true, async (page, requests) => {
            const ai = await requests.next();
            assert.equal((await formData(ai)).get('inplace_action'), 'ai_alt');
            const editable = page.locator('.post-card.is-editing [data-post-inplace-body]');
            await editable.locator(':scope > p').first().fill('Earlier body edit');
            const before = await editorState(page);
            await page.locator('.post-card.is-editing .post-caption').last().click();
            await ai.fulfill({json: {success: true, action: 'ai_alt', result: 'Generated first description'}});
            await page.waitForFunction(() => document.querySelector('.post-card.is-editing img')?.alt === 'Generated first description');
            await page.keyboard.press(finish);
            await page.waitForFunction(() => !document.querySelector('.is-editing-inline-caption'));
            assert.equal(await editable.getAttribute('contenteditable'), 'true');
            const after = await editorState(page);
            assert.equal(after.history, before.history + 1, 'An unchanged caption must flush concurrent changes into history');
            assert.equal(after.html, before.html.replace('alt=""', 'alt="Generated first description"'));
            await editable.press('Control+z');
            assert.equal((await editorState(page)).html, before.html, 'Only the generated description is undone');
            await editable.press('Control+Shift+z');
            assert.equal((await editorState(page)).html, after.html, 'Redo must restore the description');
            await page.waitForFunction(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                .list().some(copy => copy.snapshot.body.includes('Generated first description')
                    && copy.snapshot.body.includes('Earlier body edit')));
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            const save = await requests.next();
            assert.equal((await formData(save)).get('body'), after.html);
            await completeSave(save);
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        });
    }
    console.log('inline caption: unchanged Enter, Escape and Tab commits retain concurrent AI in undo, redo, recovery and saving');

    for (const exit of ['Tab', 'paragraph', 'image-side']) {
        await withPage(ordinary, false, async page => {
            await page.locator('.post-card.is-editing .post-caption').click();
            await page.locator('.is-editing-inline-caption').fill('Changed caption');
            if (exit === 'Tab') {
                await page.keyboard.press('Tab');
                await page.waitForFunction(() => !document.querySelector('.is-editing-inline-caption'));
                await page.locator('.post-card.is-editing [data-post-inplace-body] > p').last().click();
            } else if (exit === 'paragraph') {
                await page.locator('.post-card.is-editing [data-post-inplace-body] > p').last().click();
            } else {
                const point = await page.evaluate(() => {
                    const body = document.querySelector('.post-card.is-editing [data-post-inplace-body]');
                    const image = body.querySelector('img').getBoundingClientRect();
                    const x = body.getBoundingClientRect().right - 8;
                    const y = image.top + 12;
                    return {x, y, targetsBody: document.elementFromPoint(x, y) === body};
                });
                assert.equal(point.targetsBody, true);
                await page.mouse.click(point.x, point.y);
            }
            assert.equal(await page.locator('.is-editing-inline-caption').count(), 0);
            const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
            assert.equal(await body.getAttribute('contenteditable'), 'true');
            await page.keyboard.insertText('Text after caption');
            assert.match(await body.locator(':scope > p').last().textContent(), /Text after caption/u);
            assert.equal(await body.locator('.post-caption').textContent(), 'Changed caption');
        });
    }
    console.log('inline caption: Tab, body clicks and image-side clicks commit the caption and restore body typing');

    await withPage(ordinary + ordinary, false, async page => {
        await page.locator('.post-card.is-editing .post-caption').first().click();
        await page.locator('.is-editing-inline-caption').fill('First caption edit');
        await page.locator('.post-card.is-editing .post-caption').last().click();
        assert.equal(await page.locator('.is-editing-inline-caption').count(), 1);
        await page.locator('.is-editing-inline-caption').fill('Second caption edit');
        await page.keyboard.press('Enter');
        const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
        assert.equal(await body.getAttribute('contenteditable'), 'true');
        await body.press('Control+z');
        assert.deepEqual(await body.locator('.post-caption').allTextContents(), ['First caption edit', 'Saved caption']);
        await body.press('Control+z');
        assert.deepEqual(await body.locator('.post-caption').allTextContents(), ['Saved caption', 'Saved caption']);
    });
    console.log('inline caption: switching captions commits each independently and restores body editability');

    await withPage(ordinary, false, async (page, requests) => {
        await page.locator('.post-card.is-editing .post-caption').click();
        await page.locator('.is-editing-inline-caption').fill('Caption before upload');
        await page.locator('.post-card.is-editing img').evaluate(image => {
            const transfer = new DataTransfer();
            transfer.items.add(new File(['fixture'], 'dropped.png', {type: 'image/png'}));
            const rect = image.getBoundingClientRect();
            image.dispatchEvent(new DragEvent('drop', {
                bubbles: true, cancelable: true, dataTransfer: transfer,
                clientX: rect.left + 5, clientY: rect.top + 5,
            }));
        });
        const upload = await requests.next();
        assert.equal((await formData(upload)).get('inplace_action'), 'media');
        await upload.fulfill({json: {
            success: true, action: 'media', kind: 'image', media_id: 42,
            url: '/caption-image.svg', width: 160, height: 100,
        }});
        await page.waitForFunction(() => !document.querySelector('.is-processing'));
        const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
        assert.equal(await body.getAttribute('contenteditable'), 'true');
        assert.equal(await page.locator('.is-editing-inline-caption').count(), 0);
        assert.equal(await body.locator('img').count(), 2);
        assert.equal(await body.locator('.post-caption').first().textContent(), 'Caption before upload');
        await page.keyboard.insertText('Text after upload');
        assert.match(await body.textContent(), /Text after upload/u);
    });
    console.log('inline caption: dropping media commits the caption and restores body typing');

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
