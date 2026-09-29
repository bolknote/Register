import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const original = '<img src="/photo.png" alt="Original">';
const value = page => page.evaluate(() => window.adminEditor.getValue());

async function withEditor(browser, origin, run) {
    const page = await browser.newPage();
    const errors = [];
    page.setDefaultTimeout(10000);
    page.on('pageerror', error => errors.push(String(error)));
    try {
        await page.goto(origin + '/admin.html?id=9&codemirror=1&toolbar=1&tags=1&alt=1');
        await page.waitForFunction(() => window.adminEditorReady);
        await page.evaluate(original => {
            window.adminEditor.setValue(original, true);
            const cm = document.querySelector('.CodeMirror').CodeMirror;
            cm.setCursor({line: 0, ch: 5});
            cm.clearHistory();
            cm.focus();
        }, original);
        const saves = holdRequests(page, '**/admin-save?id=9');
        await saves.installed;
        await run(page, saves);
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
}

async function checkSave(page, saves, body, tags) {
    await page.keyboard.press('Control+s');
    const save = await saves.next();
    const data = await formData(save);
    assert.equal(data.get('body'), body);
    assert.equal(data.get('tags'), tags);
    await save.fulfill({json: {revision: 2, urlStatus: 'ok', urlTitle: '', url: '/post'}});
    await page.waitForFunction(() => document.querySelector('[name="revision"]').value === '2');
}

export async function runAdminCompositionRegressions(browser, origin) {
    for (const field of ['tags', 'alt']) {
        await withEditor(browser, origin, async (page, saves) => {
            if (field === 'alt') await page.locator('.ai-image-alt-text').click();
            const input = page.locator(field === 'tags' ? '.editor-tags-text-input' : '.ai-image-alt-input');
            const keys = field === 'tags' ? ['Enter', 'Escape', ',', ';', 'ArrowUp', 'ArrowDown', 'Backspace'] : ['Enter', 'Escape'];
            for (const flags of [{isComposing: true}, {keyCode: 229}]) {
                for (const key of keys) {
                    const text = key === 'Backspace' ? '' : 'にほん';
                    await input.fill(text);
                    const result = await input.evaluate((input, {key, flags}) => {
                        const event = new KeyboardEvent('keydown', {key, bubbles: true, cancelable: true, ...flags});
                        input.dispatchEvent(event);
                        return {prevented: event.defaultPrevented, text: input.value};
                    }, {key, flags});
                    assert.deepEqual(result, {prevented: false, text}, 'The IME must keep ownership of candidate and confirmation keys');
                    assert.equal(await input.count(), 1, 'Composition must not close the field');
                    assert.equal(await value(page), original, 'Intermediate composition text must not reach the body');
                    assert.deepEqual(await page.locator('.editor-tag-chip-label').allTextContents(), ['old']);
                    assert.equal(saves.count, 0);
                }
            }
            await input.fill('日本');
            await input.press('Enter');
            const expected = field === 'alt' ? original.replace('Original', '日本') : original;
            if (field === 'alt') {
                await page.waitForFunction(() => !document.querySelector('.ai-image-alt-input'));
                assert.equal(await value(page), expected);
                await page.getByRole('button', {name: 'Undo', exact: true}).click();
                assert.equal(await value(page), original);
                await page.getByRole('button', {name: 'Redo', exact: true}).click();
                assert.equal(await value(page), expected);
                await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide')));
                assert.equal(await page.evaluate(() => localStorage.getItem('register_content_draft:post:9')), expected);
                await page.locator('.ai-image-alt-text').click();
                await page.locator('.ai-image-alt-input').fill('Discard this');
                await page.locator('.ai-image-alt-input').press('Escape');
                await page.waitForFunction(() => !document.querySelector('.ai-image-alt-input'));
                assert.equal(await value(page), expected, 'Escape outside composition still cancels the edit');
            } else {
                assert.deepEqual(await page.locator('.editor-tag-chip-label').allTextContents(), ['old', '日本']);
            }
            await checkSave(page, saves, expected, field === 'tags' ? 'old, 日本' : 'old');
        });
    }
    console.log('admin composition: tag and alt fields retain candidate keys and commit only final text, with history and saving');
}

export async function runAdminNativeCompositionRegressions(browser, origin) {
    if (browser.browserType().name() !== 'chromium') return;
    for (const field of ['tags', 'alt']) {
        await withEditor(browser, origin, async (page, saves) => {
            if (field === 'alt') await page.locator('.ai-image-alt-text').click();
            const input = page.locator(field === 'tags' ? '.editor-tags-text-input' : '.ai-image-alt-input');
            await input.fill('');
            const cdp = await page.context().newCDPSession(page);
            await cdp.send('Input.imeSetComposition', {text: 'にほん', selectionStart: 3, selectionEnd: 3});
            // IME confirmation is a process key, with no ordinary Enter text
            // event that would submit the surrounding form.
            for (const type of ['rawKeyDown', 'keyUp']) {
                await cdp.send('Input.dispatchKeyEvent', {
                    type, key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 229, nativeVirtualKeyCode: 229,
                });
            }
            assert.equal(await input.inputValue(), 'にほん');
            assert.equal(await value(page), original);
            assert.deepEqual(await page.locator('.editor-tag-chip-label').allTextContents(), ['old']);
            assert.equal(saves.count, 0);
            await cdp.send('Input.insertText', {text: '日本'});
            assert.equal(await input.inputValue(), '日本');
            await input.press('Enter');
            const expected = field === 'alt' ? original.replace('Original', '日本') : original;
            await checkSave(page, saves, expected, field === 'tags' ? 'old, 日本' : 'old');
        });
    }
    console.log('admin native composition: Chromium IME conversion retains one final tag or alt description');
}

export async function runAdminTagNavigationRegressions(browser, origin) {
    await withEditor(browser, origin, async (page, saves) => {
        const input = page.locator('.editor-tags-text-input');
        await input.focus();
        for (const [key, expected] of [['ArrowUp', 'Travel'], ['ArrowDown', 'Cameras'], ['ArrowUp', 'Travel'], ['ArrowUp', 'Cameras']]) {
            await input.press(key);
            assert.deepEqual(await page.locator('[role="option"][aria-selected="true"]').allTextContents(), [expected]);
        }
        await input.press('Escape');
        assert.equal(await input.getAttribute('aria-expanded'), 'false');
        await input.press('ArrowUp');
        assert.deepEqual(await page.locator('[role="option"][aria-selected="true"]').allTextContents(), ['Travel']);
        await input.press('Enter');
        await input.press('ArrowUp');
        assert.deepEqual(await page.locator('[role="option"][aria-selected="true"]').allTextContents(), ['Cameras']);
        await input.press('Enter');
        await input.press('ArrowUp');
        assert.equal(await input.getAttribute('aria-expanded'), 'false');
        assert.deepEqual(await page.locator('.editor-tag-chip-label').allTextContents(), ['old', 'Travel', 'Cameras']);
        await checkSave(page, saves, original, 'old, Travel, Cameras');
    });
    console.log('admin tag navigation: ArrowUp enters at the last option, wraps, reopens and handles one or no remaining options');
}
