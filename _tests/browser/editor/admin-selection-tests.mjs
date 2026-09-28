import assert from 'node:assert/strict';
import {holdRequests} from './save-tests.mjs';

export async function runAdminSelectionRegressions(browser, origin) {
    const errors = [];
    async function withPage(run, alt = false) {
        const context = await browser.newContext();
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        page.on('pageerror', error => errors.push(String(error)));
        await page.goto(origin + '/admin.html?id=9&codemirror=1&toolbar=1' + (alt ? '&alt=1' : ''));
        await page.waitForFunction(() => window.adminEditorReady);
        try { await run(page); }
        finally { await context.close(); }
    }

    for (const test of [
        {name: 'single line', text: 'alpha tail', ranges: [[0, 5]],
            formatted: '<strong>alpha</strong> tail'},
        {name: 'multiple lines', text: 'alpha\nbravo tail', ranges: [[0, 11]],
            formatted: '<strong>alpha\nbravo</strong> tail'},
        {name: 'reversed multiple lines', text: 'alpha\nbravo tail', ranges: [[11, 0]],
            formatted: '<strong>alpha\nbravo</strong> tail'},
        {name: 'separate lines', text: 'alpha one\nbravo two three four five', ranges: [[0, 5], [10, 15]],
            formatted: '<strong>alpha</strong> one\n<strong>bravo</strong> two three four five'},
        {name: 'same line', text: 'alpha and bravo tail', ranges: [[0, 5], [10, 15]],
            formatted: '<strong>alpha</strong> and <strong>bravo</strong> tail'},
        {name: 'mixed wrapping and unwrapping', text: '<strong>alpha</strong> tail\nbravo tail', ranges: [[0, 22], [33, 28]],
            formatted: 'alpha tail\n<strong>bravo</strong> tail'},
        {name: 'multiple multiline ranges', text: 'alpha\nbravo tail\ncharlie\ndelta tail', ranges: [[0, 11], [30, 17]],
            formatted: '<strong>alpha\nbravo</strong> tail\n<strong>charlie\ndelta</strong> tail'},
        {name: 'empty carets', text: 'alpha\nbravo', ranges: [[0, 0], [6, 6]],
            formatted: '<strong></strong>alpha\n<strong></strong>bravo'},
    ]) {
        await withPage(async page => {
            await page.evaluate(test => {
                const cm = document.querySelector('.CodeMirror').CodeMirror;
                cm.setValue(test.text);
                cm.setSelections(test.ranges.map(([anchor, head]) => ({
                    anchor: cm.posFromIndex(anchor), head: cm.posFromIndex(head),
                })));
                cm.clearHistory();
            }, test);
            const value = () => page.evaluate(() => window.adminEditor.getValue());
            await page.getByRole('button', {name: 'Bold', exact: true}).click();
            assert.equal(await value(), test.formatted, test.name);
            await page.getByRole('button', {name: 'Undo', exact: true}).click();
            assert.equal(await value(), test.text, 'One undo reverts all ranges');
            await page.getByRole('button', {name: 'Redo', exact: true}).click();
            assert.equal(await value(), test.formatted);
            await page.getByRole('button', {name: 'Bold', exact: true}).click();
            assert.equal(await value(), test.text, 'Toggling preserves surrounding text');
            const ranges = await page.evaluate(() => {
                const cm = document.querySelector('.CodeMirror').CodeMirror;
                return cm.listSelections().map(({anchor, head}) => [cm.indexFromPos(anchor), cm.indexFromPos(head)]);
            });
            assert.deepEqual(ranges, test.ranges, 'Selection endpoints and direction survive both toggles');
        });
        console.log(`admin formatting: ${test.name} preserves ranges, surrounding text and undo/redo`);
    }

    const image = '<img src="/same.png" alt="">';
    const documentWithDuplicates = '<p>Header</p>\n' + image + '\n<p>Middle</p>\n' + image;
    const setBody = (page, text) => page.evaluate(text => {
        const cm = document.querySelector('.CodeMirror').CodeMirror;
        cm.setValue(text);
        cm.setCursor({line: 1, ch: 5});
        cm.clearHistory();
        cm.focus();
    }, text);
    const value = page => page.evaluate(() => window.adminEditor.getValue());

    await withPage(async page => {
        await setBody(page, documentWithDuplicates);
        await page.locator('.ai-image-alt-text').click();
        await page.locator('.ai-image-alt-input').fill('First occurrence');
        await page.locator('.ai-image-alt-input').press('Enter');
        const expected = documentWithDuplicates.replace('alt=""', 'alt="First occurrence"');
        assert.equal(await value(page), expected);
        await page.getByRole('button', {name: 'Undo', exact: true}).click();
        assert.equal(await value(page), documentWithDuplicates);
        await page.getByRole('button', {name: 'Redo', exact: true}).click();
        assert.equal(await value(page), expected);
        await page.evaluate(() => document.querySelector('.CodeMirror').CodeMirror.setCursor({line: 3, ch: 5}));
        assert.equal(await page.locator('.ai-image-alt-text').textContent(), 'Empty alt');
        await page.locator('.ai-image-alt-text').click();
        await page.locator('.ai-image-alt-input').fill('Second occurrence');
        await page.locator('.ai-image-alt-input').press('Enter');
        assert.equal(await value(page), expected.replace('alt=""', 'alt="Second occurrence"'));
    }, true);
    console.log('admin alt: identical images have independent manual descriptions and undo/redo');

    for (const change of ['prefix', 'deleted', 'replaced', 'manual-alt']) {
        await withPage(async page => {
            const ai = holdRequests(page, '**/admin-ai-alt');
            await ai.installed;
            await setBody(page, documentWithDuplicates);
            await page.locator('.ai-image-alt-regenerate').click();
            const pending = await ai.next();
            await page.evaluate(change => {
                const cm = document.querySelector('.CodeMirror').CodeMirror;
                if (change === 'prefix') {
                    cm.replaceRange('New paragraph\n', {line: 0, ch: 0});
                } else if (change === 'manual-alt') {
                    const line = cm.getLine(1);
                    cm.replaceRange('Manual description', {line: 1, ch: line.indexOf('alt="') + 5});
                } else {
                    const old = cm.getLine(1);
                    cm.replaceRange('', {line: 1, ch: 0}, {line: 2, ch: 0});
                    if (change === 'replaced') cm.replaceRange(old + '\n', {line: 1, ch: 0});
                }
            }, change);
            const beforeReply = await value(page);
            await pending.fulfill({json: {success: true, result: 'Generated description'}});
            await page.waitForFunction(() => !document.querySelector('.ai-image-alt-preview[data-state="generating"]'));
            assert.equal(await value(page), change === 'prefix'
                ? beforeReply.replace('alt=""', 'alt="Generated description"') : beforeReply);
        }, true);
        console.log(`admin alt: a delayed reply follows its occurrence after ${change}`);
    }

    await withPage(async page => {
        const ai = holdRequests(page, '**/admin-ai-alt');
        await ai.installed;
        await setBody(page, documentWithDuplicates);
        await page.locator('.ai-image-alt-regenerate').click();
        const first = await ai.next();
        await page.evaluate(() => document.querySelector('.CodeMirror').CodeMirror.setCursor({line: 3, ch: 5}));
        await page.locator('.ai-image-alt-regenerate').click();
        const second = await ai.next();
        await second.fulfill({json: {success: true, result: 'Second response'}});
        await page.waitForFunction(() => window.adminEditor.getValue().includes('Second response'));
        await first.fulfill({json: {success: true, result: 'First response'}});
        await page.waitForFunction(() => window.adminEditor.getValue().includes('First response'));
        assert.equal(await value(page), '<p>Header</p>\n<img src="/same.png" alt="First response">'
            + '\n<p>Middle</p>\n<img src="/same.png" alt="Second response">');
    }, true);
    console.log('admin alt: concurrent generations for identical images update their own occurrences');

    await withPage(async page => {
        const ai = holdRequests(page, '**/admin-ai-alt');
        await ai.installed;
        await setBody(page, '<p>Header</p>\n\n' + image);
        await page.evaluate(() => document.dispatchEvent(new CustomEvent('return_image.register', {
            detail: {file_path: '/same.png', width: 1, height: 1},
        })));
        const pending = await ai.next();
        const inserted = await value(page);
        await pending.fulfill({json: {success: true, result: 'Inserted image'}});
        await page.waitForFunction(() => window.adminEditor.getValue().includes('Inserted image'));
        assert.equal(await value(page), inserted.replace('alt=""', 'alt="Inserted image"'));
        assert.ok((await value(page)).endsWith(image));
    }, true);
    console.log('admin alt: inserting a repeated image before an existing one describes the new occurrence');
    assert.deepEqual(errors, []);
}
