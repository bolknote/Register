import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

export async function runAdminInputRegressions(browser, origin) {
    const errors = [];
    async function withPage(params, run) {
        const context = await browser.newContext();
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        page.on('pageerror', error => errors.push(String(error)));
        await page.goto(origin + '/admin.html?id=9&codemirror=1&toolbar=1' + params);
        await page.waitForFunction(() => window.adminEditorReady);
        try { await run(page); }
        finally { await context.close(); }
    }
    const dirty = page => page.evaluate(() => Boolean(window.onbeforeunload()));
    const value = page => page.evaluate(() => window.adminEditor.getValue());
    const saved = revision => ({success: true, revision, urlStatus: 'ok', urlTitle: '', url: '/post'});

    for (const save of ['button', 'Meta+s', 'Control+s', 'csrf-retry']) {
        await withPage('&tags=1', async page => {
            const requests = holdRequests(page, '**/admin-save?id=9');
            await requests.installed;
            const input = page.locator('.editor-tags-text-input');
            await input.fill('New tag');
            assert.equal(await page.locator('[name="tags"]').inputValue(), 'old, New tag');
            assert.equal(await dirty(page), true, 'An unfinished tag must participate in unsaved-change detection');
            if (save === 'button') await page.getByRole('button', {name: 'Save', exact: true}).click();
            else await input.press(save === 'csrf-retry' ? 'Meta+s' : save);
            let request = await requests.next();
            assert.equal((await formData(request)).get('tags'), 'old, New tag');
            assert.equal(await input.inputValue(), '', 'All save paths commit the tag field');
            await input.fill('Later tag');
            if (save === 'csrf-retry') {
                await request.fulfill({status: 422, json: {invalid_csrf_token: true, errors: []}});
                request = await requests.next();
                assert.equal((await formData(request)).get('tags'), 'old, New tag', 'A CSRF retry keeps the submitted snapshot');
            }
            await request.fulfill({json: saved(2)});
            await page.waitForFunction(() => document.querySelector('[name="revision"]').value === '2');
            assert.equal(await input.inputValue(), 'Later tag');
            assert.equal(await dirty(page), true, 'Typing during a save remains unsaved');
            await input.press('Meta+s');
            const retry = await requests.next();
            assert.equal((await formData(retry)).get('tags'), 'old, New tag, Later tag');
            await retry.fulfill({json: saved(3)});
            await page.waitForFunction(() => document.querySelector('[name="revision"]').value === '3');
            assert.equal(await dirty(page), false);
            assert.deepEqual(await page.locator('.editor-tag-chip-label').allTextContents(), ['old', 'New tag', 'Later tag']);
        });
        console.log(`admin tags: ${save} submits in-progress text and keeps later typing unsaved`);
    }

    await withPage('&tags=1', async page => {
        const requests = holdRequests(page, '**/admin-save?id=9');
        await requests.installed;
        const input = page.locator('.editor-tags-text-input');
        await input.fill('Unsaved tag');
        await input.press('Meta+s');
        await (await requests.next()).fulfill({status: 422, json: {errors: ['Cannot save']}});
        await page.waitForFunction(() => document.getElementById('error').textContent === 'Cannot save');
        assert.equal(await page.locator('[name="tags"]').inputValue(), 'old, Unsaved tag');
        assert.equal(await dirty(page), true);
        await input.press('Meta+s');
        const retry = await requests.next();
        assert.equal((await formData(retry)).get('tags'), 'old, Unsaved tag');
        await retry.fulfill({json: saved(2)});
        await page.waitForFunction(() => document.querySelector('[name="revision"]').value === '2');
        assert.equal(await dirty(page), false);
    });
    console.log('admin tags: failed saves preserve committed input for a complete retry');

    await withPage('&tags=1', async page => {
        const input = page.locator('.editor-tags-text-input');
        const source = page.locator('[name="tags"]');
        await input.fill('Ca');
        assert.equal(await source.inputValue(), 'old, Ca');
        await page.getByRole('option', {name: 'Cameras', exact: true}).click();
        assert.equal(await source.inputValue(), 'old, Cameras', 'Selecting a suggestion must remove the unfinished prefix');
        assert.equal(await input.inputValue(), '');
        await input.fill('cameras');
        await input.press('Enter');
        assert.equal(await source.inputValue(), 'old, Cameras', 'Duplicate input does not create another tag');
        await input.fill('Later');
        await page.getByRole('button', {name: 'Remove tag: old', exact: true}).click();
        assert.equal(await source.inputValue(), 'Cameras, Later');
        await input.fill('');
        assert.equal(await source.inputValue(), 'Cameras');
        await input.evaluate(input => {
            const data = new DataTransfer();
            data.setData('text/plain', 'Travel; Notes');
            const event = new Event('paste', {bubbles: true, cancelable: true});
            Object.defineProperty(event, 'clipboardData', {value: data});
            input.dispatchEvent(event);
        });
        assert.equal(await source.inputValue(), 'Cameras, Travel, Notes');
        await input.fill('Discard on reset');
        await page.evaluate(() => document.querySelector('form').reset());
        await page.waitForFunction(() => document.querySelector('.editor-tags-text-input').value === '');
        assert.equal(await source.inputValue(), 'old');
        assert.deepEqual(await page.locator('.editor-tag-chip-label').allTextContents(), ['old']);
    });
    console.log('admin tags: suggestions, duplicate input, removal, paste and reset keep the form value consistent');

    await withPage('&tags=1&ai=1', async page => {
        const ai = holdRequests(page, '**/admin-ai');
        await ai.installed;
        const input = page.locator('.editor-tags-text-input');
        await page.locator('[data-ai-action="tags"]').click();
        const first = await ai.next();
        await input.fill('My unfinished tag');
        await first.fulfill({json: {success: true, result: 'Stale suggestion'}});
        await page.waitForFunction(() => document.getElementById('content-editor-ai-tools').getAttribute('aria-busy') === 'false');
        assert.equal(await input.inputValue(), 'My unfinished tag');
        assert.equal(await page.locator('[name="tags"]').inputValue(), 'old, My unfinished tag');
        assert.equal(await page.locator('#ai-tools-status').textContent(), 'The source text has changed.');
        await page.locator('[data-ai-action="tags"]').click();
        await (await ai.next()).fulfill({json: {success: true, result: 'Fresh suggestion'}});
        await page.waitForFunction(() => document.getElementById('content-editor-ai-tools').getAttribute('aria-busy') === 'false');
        assert.equal(await input.inputValue(), '');
        assert.equal(await page.locator('[name="tags"]').inputValue(), 'Fresh suggestion');
        assert.deepEqual(await page.locator('.editor-tag-chip-label').allTextContents(), ['Fresh suggestion']);
        assert.equal(await page.locator('#ai-tools-status').textContent(), '');
    });
    console.log('admin tags: a delayed AI result preserves unfinished input and a fresh request still applies');

    for (const [label, tag] of [['Heading 2', 'h2'], ['Heading 3', 'h3'], ['Heading 4', 'h4'],
        ['Quote', 'blockquote'], ['Preformatted', 'pre'], ['Paragraph', 'p']]) {
        await withPage('', async page => {
            const original = '<p>alpha</p> tail\nbravo tail';
            await page.evaluate(text => {
                const cm = document.querySelector('.CodeMirror').CodeMirror;
                cm.setValue(text);
                cm.setSelections([
                    {anchor: cm.posFromIndex(0), head: cm.posFromIndex(12)},
                    {anchor: cm.posFromIndex(23), head: cm.posFromIndex(18)},
                ]);
                cm.clearHistory();
            }, original);
            await page.getByRole('button', {name: label, exact: true}).click();
            const expected = `<${tag}>alpha</${tag}> tail\n<${tag}>bravo</${tag}> tail`;
            assert.equal(await value(page), expected);
            assert.deepEqual(await page.evaluate(() => document.querySelector('.CodeMirror').CodeMirror.getSelections()),
                [`<${tag}>alpha</${tag}>`, `<${tag}>bravo</${tag}>`]);
            await page.getByRole('button', {name: 'Undo', exact: true}).click();
            assert.equal(await value(page), original);
            await page.getByRole('button', {name: 'Redo', exact: true}).click();
            assert.equal(await value(page), expected);
            if (tag !== 'pre') {
                await page.getByRole('button', {name: 'Heading 2', exact: true}).click();
                assert.equal(await value(page), '<h2>alpha</h2> tail\n<h2>bravo</h2> tail');
            }
        });
        console.log(`admin blocks: ${label} transforms each range independently with undo/redo`);
    }
    for (const emptyCaret of [false, true]) {
        await withPage('', async page => {
            await page.evaluate(emptyCaret => {
                const cm = document.querySelector('.CodeMirror').CodeMirror;
                cm.setValue('alpha\nbravo tail\ncharlie\ndelta tail');
                cm.setSelections([
                    {anchor: cm.posFromIndex(0), head: cm.posFromIndex(emptyCaret ? 0 : 11)},
                    {anchor: cm.posFromIndex(30), head: cm.posFromIndex(17)},
                ]);
            }, emptyCaret);
            await page.getByRole('button', {name: 'Heading 2', exact: true}).click();
            assert.equal(await value(page), emptyCaret
                ? '<h2></h2>alpha\nbravo tail\n<h2>charlie\ndelta</h2> tail'
                : '<h2>alpha\nbravo</h2> tail\n<h2>charlie\ndelta</h2> tail');
        });
        console.log(`admin blocks: multiline ranges${emptyCaret ? ' and an empty caret' : ''} do not duplicate text`);
    }

    const images = '<img src="/first.png" alt="">\n<p>Between</p>\n<img src="/second.png" alt="">';
    async function beginAltRace(page) {
        const ai = holdRequests(page, '**/admin-ai-alt');
        await ai.installed;
        await page.evaluate(text => {
            const cm = document.querySelector('.CodeMirror').CodeMirror;
            cm.setValue(text);
            cm.setCursor({line: 0, ch: 5});
            cm.clearHistory();
            cm.focus();
        }, images);
        await page.locator('.ai-image-alt-regenerate').click();
        const request = await ai.next();
        await page.evaluate(() => document.querySelector('.CodeMirror').CodeMirror.setCursor({line: 2, ch: 5}));
        await page.locator('.ai-image-alt-text').click();
        const input = page.locator('.ai-image-alt-input');
        await input.fill('My second description');
        return {request, input};
    }
    for (const failure of [false, true]) {
        for (const finish of ['Enter', 'Escape', 'blur']) {
            await withPage('&alt=1', async page => {
                const {request, input} = await beginAltRace(page);
                await input.evaluate(input => { input.setSelectionRange(3, 9); window.activeAltInput = input; });
                await request.fulfill(failure
                    ? {status: 500, json: {success: false}}
                    : {json: {success: true, result: 'Generated first description'}});
                if (!failure) await page.waitForFunction(() => window.adminEditor.getValue().includes('Generated first description'));
                // Let the error handler and queued cursor/change handlers settle too.
                await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
                assert.deepEqual(await input.evaluate(input => ({
                    same: input === window.activeAltInput, focused: document.activeElement === input,
                    text: input.value, start: input.selectionStart, end: input.selectionEnd,
                })), {same: true, focused: true, text: 'My second description', start: 3, end: 9});
                if (finish === 'blur') await page.locator('[name="title"]').click();
                else await input.press(finish);
                await page.waitForFunction(() => !document.querySelector('.ai-image-alt-input'));
                const expected = images.replace('alt=""', `alt="${failure ? '' : 'Generated first description'}"`)
                    .replace('src="/second.png" alt=""', `src="/second.png" alt="${finish === 'Escape' ? '' : 'My second description'}"`);
                assert.equal(await value(page), expected);
                if (!failure && finish === 'Enter') {
                    await page.getByRole('button', {name: 'Undo', exact: true}).click();
                    assert.equal(await value(page), images.replace('alt=""', 'alt="Generated first description"'));
                    await page.getByRole('button', {name: 'Redo', exact: true}).click();
                    assert.equal(await value(page), expected);
                }
            });
            console.log(`admin alt: a delayed ${failure ? 'error' : 'reply'} preserves the active field until ${finish}`);
        }
    }
    await withPage('&alt=1', async page => {
        const saves = holdRequests(page, '**/admin-save?id=9');
        await saves.installed;
        const {request, input} = await beginAltRace(page);
        await input.press('Meta+s');
        const save = await saves.next();
        const expected = images.replace('src="/second.png" alt=""', 'src="/second.png" alt="My second description"');
        assert.equal((await formData(save)).get('body').replace(/\r\n/g, '\n'), expected);
        await request.fulfill({json: {success: true, result: 'Cancelled response'}});
        await save.fulfill({json: saved(2)});
        await page.waitForFunction(() => document.querySelector('[name="revision"]').value === '2');
        assert.equal(await value(page), expected);
        assert.equal(await dirty(page), false);
    });
    console.log('admin alt: the save shortcut commits the open field and cancels pending generation');
    assert.deepEqual(errors, []);
}
