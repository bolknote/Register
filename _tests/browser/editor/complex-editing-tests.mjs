import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {formData, holdRequests} from './save-tests.mjs';

const resources = execFileSync(process.env.PHP_BIN || 'php',
    [fileURLToPath(new URL('./context-menu-fixture.php', import.meta.url))], {encoding: 'utf8'});
const media = '<div class="post-picture post-media-picture">'
    + '<img src="/complex-photo.svg" width="160" height="100" alt="Equipment">'
    + '<div class="post-caption">Saved caption</div></div>';
const nested = '<h2>Trip plan</h2><ul><li>Equipment<ul><li>First aid<ol><li>Bandage'
    + media + '<p>Keep this separate paragraph.</p><ul><li>Spare supplies</li></ul></li>'
    + '<li>Scissors</li></ol></li><li>Torch</li></ul></li><li>Water</li></ul>'
    + '<blockquote><p>Check the weather.</p></blockquote><p>Final paragraph.</p>';

async function withEditor(browser, origin, html, run, copySource = '') {
    const page = await browser.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    try {
        await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        await page.route('**/complex-photo.svg', route => route.fulfill({
            contentType: 'image/svg+xml',
            body: '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="100"><rect width="160" height="100" fill="teal"/></svg>',
        }));
        await page.route('**/recovery-fixture.js', async route => {
            const response = await route.fetch();
            await route.fulfill({response, body: (await response.text()).replace(
                /const body = creating \? '' : [^\n]+;/u,
                'const body = creating ? "" : ' + JSON.stringify(html) + ';',
            )});
        });
        if (copySource) await page.route('**/recovery.html', async route => {
            const response = await route.fetch();
            await route.fulfill({response, body: (await response.text()).replace('</html>', copySource + '</html>')});
        });
        const requests = holdRequests(page, '**/_inplace/post/9');
        await requests.installed;
        await page.goto(origin + '/recovery.html');
        await page.evaluate(html => { document.getElementById('post-editor-resources').outerHTML = html; }, resources);
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        await run(page, requests, page.locator('.post-card.is-editing [data-post-inplace-body]'));
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
}

function snapshot(page) {
    return page.evaluate(() => {
        const state = editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
        return editorTest.editableBodyHtml(state).replace(/ class=""/gu, '');
    });
}

async function saveAndReopen(page, requests, expected) {
    await page.getByRole('button', {name: 'Save', exact: true}).click();
    const save = await requests.next();
    const data = await formData(save);
    assert.equal(data.get('inplace_action'), 'edit');
    assert.equal(String(data.get('body')).replace(/\r\n?/gu, '\n').replace(/ class=""/gu, ''), expected,
        'Saving must preserve the complete structure apart from multipart newline normalization');
    await save.fulfill({json: {
        success: true, action: 'edit', revision: 2, title: data.get('title'),
        body_html: '<div class="post body" data-post-inplace-body>' + expected + '</div>',
        published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
        tags: [], scheduled: false, message: 'Saved',
    }});
    await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
    await page.getByRole('button', {name: 'Edit', exact: true}).click();
    assert.equal(await snapshot(page), expected, 'Reopening must preserve saved content');
}

async function multilineCaption(browser, origin) {
    await withEditor(browser, origin, nested, async (page, requests, body) => {
        const before = await snapshot(page);
        await body.locator('.post-caption').click();
        const caption = body.locator('.is-editing-inline-caption');
        const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
        await caption.press(modifier + '+a');
        const text = 'First line\r\nSecond line\r\n\r\n<em title="literal">A & B</em>';
        const expected = text.replace(/\r\n/gu, '\n');
        await caption.evaluate((caption, text) => {
            const transfer = new DataTransfer();
            transfer.setData('text/plain', text);
            transfer.setData('text/html', '<em>Formatted clipboard text</em>');
            const event = new Event('paste', {bubbles: true, cancelable: true});
            Object.defineProperty(event, 'clipboardData', {value: transfer});
            caption.dispatchEvent(event);
        }, text);
        assert.equal((await caption.innerText()).trim(), expected, 'Caption paste preserves each line break exactly once');
        assert.equal(await caption.locator('em').count(), 0, 'HTML is literal caption text');
        await caption.press(modifier + '+z');
        assert.equal(await caption.textContent(), 'Saved caption');
        await caption.press(modifier + '+Shift+z');
        assert.equal((await caption.innerText()).trim(), expected, 'Local redo retains the exact pasted lines');
        await caption.press('Enter');
        assert.equal(await body.locator('.post-caption').textContent(), expected);
        const changed = await snapshot(page);
        await body.press(modifier + '+z');
        assert.equal(await snapshot(page), before);
        await body.press(modifier + '+Shift+z');
        assert.equal(await snapshot(page), changed);
        await page.waitForFunction(expected => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
            .list().some(copy => copy.snapshot.body === expected), changed);
        await saveAndReopen(page, requests, changed);
        assert.equal(await body.locator('.post-caption').textContent(), expected);
    });
}

async function deleteNestedMedia(browser, origin, mode) {
    const captionStart = mode.endsWith('caption-start');
    const initial = mode === 'empty-caption-start' ? nested.replace('Saved caption', '') : nested;
    await withEditor(browser, origin, initial, async (page, requests, body) => {
        const before = await snapshot(page);
        const expected = await page.evaluate(before => {
            const clone = document.createElement('div');
            clone.innerHTML = before;
            clone.querySelector('.post-media-picture').remove();
            return clone.innerHTML;
        }, before);
        const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
        if (captionStart) await body.locator('.post-caption').click();
        await body.evaluate((body, mode) => {
            const media = body.querySelector('.post-media-picture');
            const range = document.createRange();
            if (mode.endsWith('caption-start')) {
                const caption = media.querySelector('.post-caption');
                caption.focus();
                range.selectNodeContents(caption);
                range.collapse(true);
            } else if (mode === 'backspace') {
                range.setStart(media.nextElementSibling.firstChild, 0);
                range.collapse(true);
            } else range.selectNode(media);
            if (!mode.endsWith('caption-start')) body.focus();
            getSelection().removeAllRanges();
            getSelection().addRange(range);
        }, mode);
        if (mode === 'backspace' || captionStart) {
            await page.keyboard.press('Backspace');
            assert.equal(await snapshot(page), before, 'First Backspace only selects the image');
            assert.equal(await body.getAttribute('contenteditable'), 'true', 'Backspace leaves the nested caption host');
            assert.equal(await body.locator('.is-editing-inline-caption').count(), 0);
            await page.keyboard.press('Backspace');
        } else if (mode === 'cut') {
            await body.press('Shift+F10');
            await page.locator('[data-context-action="cut"]').click();
        } else await page.keyboard.press('Delete');
        assert.equal(await body.locator('img').count(), 0, mode + ': selected media is removed');
        assert.equal(await snapshot(page), expected, mode + ': removing media must not merge neighbouring paragraphs or lists');
        await body.press(modifier + '+z');
        assert.equal(await snapshot(page), before, mode + ': undo restores image, caption and surrounding structure');
        await body.press(modifier + '+Shift+z');
        assert.equal(await snapshot(page), expected, mode + ': redo removes only the media');
        await saveAndReopen(page, requests, expected);
    });
}

async function listCaret(browser, origin, kind, position, menu, text = 'First item with text') {
    await withEditor(browser, origin,
        '<blockquote><p>Keep this quote.</p></blockquote><p>' + (text || '<br>') + '</p>'
        + '<table><tbody><tr><td>Keep table</td></tr></tbody></table>',
        async (page, requests, body) => {
            const before = await snapshot(page);
            const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
            await body.evaluate((body, position) => {
                const range = document.createRange();
                const paragraph = body.querySelector(':scope > p');
                range.setStart(paragraph.firstChild instanceof Text ? paragraph.firstChild : paragraph, position);
                range.collapse(true);
                body.focus();
                getSelection().removeAllRanges();
                getSelection().addRange(range);
            }, position);
            if (menu) {
                await body.press('Shift+F10');
                await page.locator('[data-context-action="' + kind + '"]').click();
            } else await body.press(modifier + '+Shift+' + (kind === 'ordered-list' ? '7' : '8'));
            assert.equal(await body.locator('p > ol, p > ul').count(), 0, 'Lists must not remain inside paragraphs');
            assert.equal(await body.locator('[data-post-list-bookmark]').count(), 0, 'Selection bookmarks never remain in content');
            assert.equal((await body.textContent()).includes('\u200b'), false, 'Bookmark characters never remain in text');
            assert.equal((await body.textContent()).includes('\u00a0'), false, 'Temporary bookmark spaces never remain in text');
            await page.keyboard.insertText('ADDED');
            assert.equal(await body.locator('li').textContent(), text.slice(0, position) + 'ADDED' + text.slice(position),
                'List conversion retains the caret at the original text offset');
            if (position === text.length) {
                await page.keyboard.press('Enter');
                await page.keyboard.insertText('Second item');
                assert.deepEqual(await body.locator('li').allTextContents(), [text + 'ADDED', 'Second item']);
            }
            assert.equal(await body.locator('blockquote').textContent(), 'Keep this quote.');
            assert.equal(await body.locator('td').textContent(), 'Keep table');
            const changed = await snapshot(page);
            for (let attempts = 0; await snapshot(page) !== before && attempts < 5; attempts++) {
                await body.press(modifier + '+z');
            }
            assert.equal(await snapshot(page), before, 'Undo restores the paragraph without altering other blocks');
            for (let attempts = 0; await snapshot(page) !== changed && attempts < 5; attempts++) {
                await body.press(modifier + '+Shift+z');
            }
            assert.equal(await snapshot(page), changed, 'Redo restores the edited list');
            await saveAndReopen(page, requests, changed);
        });
}

async function listSelection(browser, origin, kind, backwards) {
    await withEditor(browser, origin, '<p>Before <strong>selected</strong> after.</p><p>Next paragraph.</p>',
        async (page, requests, body) => {
            const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
            const selected = await body.evaluate((body, backwards) => {
                const paragraph = body.querySelector('p');
                const start = [paragraph.querySelector('strong').firstChild, 1];
                const end = [paragraph.lastChild, 3];
                body.focus();
                getSelection().setBaseAndExtent(...(backwards ? end : start), ...(backwards ? start : end));
                return getSelection().toString();
            }, backwards);
            await body.press(modifier + '+Shift+' + (kind === 'ordered-list' ? '7' : '8'));
            assert.deepEqual(await body.evaluate(body => ({
                selected: getSelection().toString(),
                backwards: getSelection().anchorNode === getSelection().getRangeAt(0).endContainer
                    && getSelection().anchorOffset === getSelection().getRangeAt(0).endOffset,
                markers: body.querySelectorAll('[data-post-list-bookmark]').length,
            })), {selected, backwards, markers: 0}, 'List conversion preserves the complete selection and its direction');
            await page.keyboard.insertText('REPLACED');
            assert.equal(await body.locator('li').textContent(), 'Before sREPLACEDter.');
            assert.equal(await body.locator(':scope > p').last().textContent(), 'Next paragraph.');
            await saveAndReopen(page, requests, await snapshot(page));
        });
}

async function nativeRichClipboard(browser, origin) {
    const source = '<section id="rich-copy-source" tabindex="0"><p>Copied introduction.</p>'
        + '<blockquote><p>Copied <strong>rich quote</strong>.</p></blockquote>'
        + '<ul><li>Outer item<ol><li>Nested item</li></ol></li></ul>'
        + '<table><tbody><tr><td>Copied cell A</td><td>Copied cell B</td></tr></tbody></table></section>';
    await withEditor(browser, origin, '<p>Before paste.</p>' + media + '<p>After paste.</p>',
        async (page, requests, body) => {
            const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
            const before = await snapshot(page);
            // Copy our rendered fixture with the browser's real clipboard path,
            // then let native HTML paste and the caption's plain-text handler run.
            await page.locator('#rich-copy-source').evaluate(source => {
                source.focus();
                const range = document.createRange();
                range.selectNodeContents(source);
                getSelection().removeAllRanges();
                getSelection().addRange(range);
            });
            await page.keyboard.press(modifier + '+c');
            await body.evaluate(body => {
                const range = document.createRange();
                range.selectNodeContents(body.querySelector('p'));
                range.collapse(false);
                body.focus();
                getSelection().removeAllRanges();
                getSelection().addRange(range);
            });
            await page.keyboard.press(modifier + '+v');
            await body.locator('td').filter({hasText: 'Copied cell B'}).waitFor({state: 'attached'});
            assert.equal(await body.locator('blockquote').count(), 1, 'Native HTML paste retains the quote: ' + await body.innerHTML());
            assert.equal(await body.locator('blockquote strong, blockquote b').textContent(), 'rich quote');
            assert.equal(await body.locator('ul ol li').textContent(), 'Nested item');
            assert.deepEqual(await body.locator('td').allTextContents(), ['Copied cell A', 'Copied cell B']);
            assert.equal(await body.locator('img').count(), 1);
            const pasted = await snapshot(page);
            await body.locator('.post-caption').click();
            const caption = body.locator('.is-editing-inline-caption');
            await caption.press(modifier + '+a');
            await caption.press(modifier + '+v');
            await page.waitForFunction(() => document.querySelector('.is-editing-inline-caption')?.textContent.includes('Copied cell B'));
            await caption.press('Enter');
            const captionText = await body.locator('.post-caption').textContent();
            for (const text of ['rich quote', 'Outer item', 'Nested item', 'Copied cell A', 'Copied cell B']) {
                assert.ok(captionText.includes(text), 'Rich clipboard text is retained in the plain caption: ' + text);
            }
            assert.equal(await body.locator('.post-caption *').count(), 0, 'Caption commit stores plain text, with no pasted formatting');
            const changed = await snapshot(page);
            await body.press(modifier + '+z');
            assert.equal(await snapshot(page), pasted, 'First undo restores the earlier caption');
            await body.press(modifier + '+z');
            assert.equal(await snapshot(page), before, 'Second undo removes the rich HTML paste');
            await body.press(modifier + '+Shift+z');
            await body.press(modifier + '+Shift+z');
            assert.equal(await snapshot(page), changed);
            await saveAndReopen(page, requests, changed);
        }, source);
}

export async function runComplexEditingRegressions(browser, origin) {
    const failures = [];
    const scenarios = [
        ['multiline caption in a nested list', () => multilineCaption(browser, origin)],
        ['native rich HTML clipboard in body and caption', () => nativeRichClipboard(browser, origin)],
        ...['backspace', 'delete', 'cut', 'caption-start', 'empty-caption-start']
            .map(mode => ['nested media ' + mode, () => deleteNestedMedia(browser, origin, mode)]),
        ...['ordered-list', 'unordered-list'].flatMap(kind => [0, 6, 20].map(position => [
            kind + ' caret ' + position,
            () => listCaret(browser, origin, kind, position, position === 6),
        ])),
        ...['ordered-list', 'unordered-list'].map(kind => [
            kind + ' in an empty paragraph', () => listCaret(browser, origin, kind, 0, false, ''),
        ]),
        ...['ordered-list', 'unordered-list'].flatMap(kind => [false, true].map(backwards => [
            kind + (backwards ? ' backward selection' : ' forward selection'),
            () => listSelection(browser, origin, kind, backwards),
        ])),
    ];
    for (const [name, run] of scenarios) {
        try {
            await run();
            console.log(browser.browserType().name() + ': complex editing: ' + name);
        } catch (error) {
            console.error(browser.browserType().name() + ': ' + name + ': ' + error.message);
            failures.push(error);
        }
    }
    if (failures.length) throw new AggregateError(failures, 'Complex editing regressions');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const failures = [];
    try {
        for (const [name, engine] of Object.entries({chromium, firefox, webkit})) {
            if (process.env.EDITOR_TEST_BROWSER && process.env.EDITOR_TEST_BROWSER !== name) continue;
            const browser = await engine.launch({headless: process.env.EDITOR_TEST_HEADED !== '1'});
            try { await runComplexEditingRegressions(browser, 'http://127.0.0.1:' + server.address().port); }
            catch (error) { failures.push(error); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
    if (failures.length) throw new AggregateError(failures, 'Complex editing failed');
}
