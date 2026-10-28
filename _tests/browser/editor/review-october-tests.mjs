import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {openFuzzEditor, fuzzSnapshot, saveRoundTrip, timed} from './fuzz-editing.mjs';
import {fulfillPreview} from './html-block-tests.mjs';
import {formData} from './save-tests.mjs';

const canonical = html => html.replace(/ (?:class|style)=""/gu, '');
const picture = '<div class="post-picture post-media-picture"><img src="/responsive-image.svg"'
    + ' width="160" height="90" alt="Example"><div class="post-caption">CAPTION</div></div>';

async function withEditor(browser, origin, html, run) {
    const session = await openFuzzEditor(browser, origin, html);
    try {
        await run(session);
        assert.deepEqual(session.errors, [], 'No browser exceptions');
    } finally { await timed(session.page.close(), 3000).catch(() => {}); }
}

async function formatAll(session, action) {
    await session.body.focus();
    await session.page.keyboard.press(session.modifier + '+a');
    await session.body.press('Shift+F10');
    await session.page.locator(`.post-editor-context-menu [data-context-action="${action}"]`).click();
}

async function undoRedo(session, before) {
    const after = await fuzzSnapshot(session.page);
    await session.body.focus();
    await session.page.keyboard.press(session.modifier + '+z');
    assert.equal(canonical((await fuzzSnapshot(session.page)).html), canonical(before.html), 'Undo restores the complete original body');
    await session.page.keyboard.press(session.modifier + '+Shift+z');
    assert.equal(canonical((await fuzzSnapshot(session.page)).html), canonical(after.html), 'Redo restores the complete formatted body');
}

async function logicalHtmlOrder(body) {
    return body.evaluate(root => {
        function text(node) {
            if (node instanceof Element && node.matches('div[data-post-html-source]')) return '|HTML|';
            return node.nodeType === Node.TEXT_NODE ? node.data : Array.from(node.childNodes, text).join('');
        }
        return text(root).replace(/\s/gu, '');
    });
}

async function assertNoEmptyParagraphs(body) {
    assert.equal(await body.locator('p').evaluateAll(nodes => nodes.filter(node =>
        node.textContent.trim() === '' && !node.querySelector('img, audio, video, table, hr')
        && !node.classList.contains('post-editor-body-paragraph')).length), 0, 'No empty authored paragraphs are manufactured');
}

async function withHtmlInsertion(browser, origin, initial, run) {
    await withEditor(browser, origin, initial, async session => {
        const {page, body} = session;
        await page.route('**/_inplace/post/9', async route => {
            const data = await formData(route);
            if (data.get('inplace_action') === 'html_preview') await fulfillPreview(route, data);
            else await route.fallback();
        });
        await body.locator('p').first().evaluate(node => {
            node.closest('[data-post-inplace-body]').focus();
            const range = document.createRange(); range.selectNodeContents(node); range.collapse(false);
            getSelection().removeAllRanges(); getSelection().addRange(range);
        });
        await body.press('Shift+F10');
        await page.locator('[data-context-action="html"]').click();
        const block = body.locator('.post-html-block');
        const source = '<section><p>UNIQUE HTML</p><ul><li>one</li><li>two</li></ul></section>';
        await block.locator('textarea').fill(source);
        await block.locator('.post-html-block-edit').click();
        async function assertPreview() {
            assert.equal(await body.locator('.post-html-block-preview').count(), 1, 'One preview belongs to the insertion');
            assert.equal(await page.locator('form[target^="register-html-"]').count(), 1, 'Detached preview sessions leave no orphan forms');
            assert.equal(await body.locator('div[data-post-html-source]').getAttribute('data-post-html-source'), source);
            await page.frameLocator('.post-html-block-preview').getByText('UNIQUE HTML', {exact: true}).waitFor();
        }
        await assertPreview();
        await run(session, assertPreview);
    });
}

async function htmlOrder(browser, origin, action) {
    await withHtmlInsertion(browser, origin, '<p>BEFORE</p><p>AFTER</p>', async (session, assertPreview) => {
        const {page, body} = session;
        const before = await fuzzSnapshot(page);
        await formatAll(session, action);
        assert.equal(await logicalHtmlOrder(body), 'BEFORE|HTML|AFTER', 'Formatting preserves the insertion position between both paragraphs');
        assert.equal(await body.locator('div[data-post-html-source]').count(), 1);
        const selector = action === 'code' ? 'pre' : action === 'h2' ? 'h2' : 'ul li';
        const formatted = (await body.locator(selector).allTextContents()).join('');
        assert.ok(formatted.includes('BEFORE') && formatted.includes('AFTER'), 'Both prose blocks receive the requested format');
        await assertPreview();
        await undoRedo(session, before);
        assert.equal(await logicalHtmlOrder(body), 'BEFORE|HTML|AFTER');
        await assertPreview();
        await saveRoundTrip(session);
        assert.equal(await logicalHtmlOrder(body), 'BEFORE|HTML|AFTER', 'Save/reopen preserves the insertion position');
        await assertPreview();
    });
}

async function htmlToggle(browser, origin, action) {
    await withHtmlInsertion(browser, origin, '<p>BEFORE</p><p>AFTER</p>', async (session, assertPreview) => {
        const {page, body} = session;
        const initial = await fuzzSnapshot(page);
        await formatAll(session, action);
        const selector = action === 'quote' ? 'blockquote' : action === 'ordered-list' ? 'ol' : 'ul';
        assert.ok(await body.locator(selector).count() > 0, 'The first command applies its requested block style');
        if (action !== 'quote') assert.equal(await body.locator('li div[data-post-html-source]').count(), 1,
            'The HTML-only list item is included in the list');
        assert.equal(await logicalHtmlOrder(body), 'BEFORE|HTML|AFTER');
        await assertPreview();
        await undoRedo(session, initial);
        await assertPreview();
        const styled = await fuzzSnapshot(page);
        await formatAll(session, action);
        async function assertUnstyled() {
            assert.equal(await body.locator(action === 'quote' ? 'blockquote' : 'ul, ol').count(), 0,
                'The second command removes the complete block style, including an HTML-only item');
            assert.equal(await logicalHtmlOrder(body), 'BEFORE|HTML|AFTER');
            await assertPreview();
        }
        await assertUnstyled();
        await undoRedo(session, styled);
        await assertUnstyled();
        await saveRoundTrip(session);
        await assertUnstyled();
    });
}

async function authoredHtmlQuoteToParagraph(browser, origin) {
    // Start with an authored quote, then insert through the public HTML tool so
    // the fixture never constructs or modifies a private runtime preview DOM.
    await withHtmlInsertion(browser, origin, '<blockquote><p>BEFORE</p><p>AFTER</p></blockquote>', async (session, assertPreview) => {
        const {page, body} = session;
        assert.equal(await body.locator('blockquote div[data-post-html-source]').count(), 1);
        const before = await fuzzSnapshot(page);
        await formatAll(session, 'paragraph');
        async function assertUnquoted() {
            assert.equal(await body.locator('blockquote').count(), 0, 'Paragraph removes the authored quote wrapper');
            assert.equal(await logicalHtmlOrder(body), 'BEFORE|HTML|AFTER');
            await assertPreview();
        }
        await assertUnquoted();
        await undoRedo(session, before);
        await assertUnquoted();
        await saveRoundTrip(session);
        await assertUnquoted();
    });
}

async function removeMediaList(browser, origin, tag, paragraphs) {
    const inside = paragraphs ? '<p>BEFORE</p>' + picture + '<p>AFTER</p>' : 'BEFORE' + picture + 'AFTER';
    await withEditor(browser, origin, `<${tag}><li>${inside}</li></${tag}>`, async session => {
        const before = await fuzzSnapshot(session.page);
        await formatAll(session, tag === 'ol' ? 'ordered-list' : 'unordered-list');
        async function assertUnlisted() {
            const snapshot = await fuzzSnapshot(session.page);
            assert.equal(await session.body.locator('ul, ol').count(), 0, 'Toggling off removes the complete selected list');
            assert.equal(snapshot.text.replace(/\s/gu, ''), 'BEFORECAPTIONAFTER');
            assert.deepEqual(snapshot.captions, ['CAPTION'], 'The caption remains owned by its image');
            assert.equal(snapshot.images.length, 1);
            await assertNoEmptyParagraphs(session.body);
        }
        await assertUnlisted();
        await undoRedo(session, before);
        await assertUnlisted();
        await saveRoundTrip(session);
        await assertUnlisted();
    });
}

async function preLines(browser, origin) {
    const html = '<pre id="code">FIRST\nSECOND\nTHIRD</pre><table><tbody><tr><td><p>CELL</p></td></tr></tbody></table><p>TAIL</p>';
    await withEditor(browser, origin, html, async session => {
        const before = await fuzzSnapshot(session.page);
        await formatAll(session, 'paragraph');
        async function assertLines() {
            assert.equal(await session.body.locator('pre').count(), 0, 'The code block really becomes prose');
            assert.equal(await session.body.locator('table').count(), 1);
            assert.deepEqual((await session.body.innerText()).split(/\r?\n/u).map(line => line.trim()).filter(Boolean),
                ['FIRST', 'SECOND', 'THIRD', 'CELL', 'TAIL'], 'Explicit source lines remain separate rendered lines');
        }
        await assertLines();
        await undoRedo(session, before);
        await assertLines();
        await saveRoundTrip(session);
        await assertLines();
    });
}

async function removePartialMediaList(browser, origin, tag) {
    const html = `<${tag}${tag === 'ol' ? ' start="5"' : ''}><li>KEEP</li><li><p>BEFORE</p>`
        + picture + `<p>AFTER</p></li><li>STAY</li></${tag}>`;
    await withEditor(browser, origin, html, async session => {
        const {page, body} = session;
        const before = await fuzzSnapshot(page);
        await body.locator('li').nth(1).evaluate(item => {
            item.closest('[data-post-inplace-body]').focus();
            const range = document.createRange(); range.selectNodeContents(item);
            getSelection().removeAllRanges(); getSelection().addRange(range);
        });
        await body.press('Shift+F10');
        await page.locator(`[data-context-action="${tag === 'ol' ? 'ordered-list' : 'unordered-list'}"]`).click();
        async function assertSiblings() {
            assert.deepEqual(await body.locator('li').allTextContents(), ['KEEP', 'STAY'], 'Unselected siblings remain list items');
            assert.equal(await body.locator('li img').count(), 0, 'Only the selected item and its attachment leave the list');
            const snapshot = await fuzzSnapshot(page);
            assert.equal(snapshot.text.replace(/\s/gu, ''), 'KEEPBEFORECAPTIONAFTERSTAY');
            assert.deepEqual(snapshot.captions, ['CAPTION']);
            await assertNoEmptyParagraphs(body);
            if (tag === 'ol') assert.deepEqual(await body.locator('ol').evaluateAll(lists => lists.map(list => list.start)), [5, 7],
                'Removing an ordered item does not renumber the unselected following sibling');
        }
        await assertSiblings();
        await undoRedo(session, before);
        await assertSiblings();
        await saveRoundTrip(session);
        await assertSiblings();
    });
}

async function partialListDeletion(browser, origin, key) {
    const html = '<ul><li>FIRST</li><li>prefixREMOVE</li></ul><p>UNSELECTED</p>';
    await withEditor(browser, origin, html, async session => {
        const {page, body, modifier} = session;
        const before = await fuzzSnapshot(page);
        await body.locator('p').evaluate(node => {
            node.closest('[data-post-inplace-body]').focus();
            const range = document.createRange(); range.selectNodeContents(node); range.collapse(true);
            getSelection().removeAllRanges(); getSelection().addRange(range);
        });
        for (let index = 0; index < 7; index++) await page.keyboard.press('Shift+ArrowLeft');
        assert.equal(await page.evaluate(() => getSelection().getRangeAt(0).toString()), 'REMOVE');
        await page.keyboard.press(key);
        assert.equal(await body.locator('li').last().textContent(), 'prefix');
        await page.keyboard.type('TYPED');
        async function assertTyped() {
            assert.equal(await body.locator('li').last().textContent(), 'prefixTYPED', 'Typing continues at the deletion point in the retained list item');
            assert.equal(await body.locator('p').textContent(), 'UNSELECTED', 'The unselected following paragraph stays untouched');
        }
        await assertTyped();
        await page.keyboard.press(modifier + '+z');
        assert.equal(await body.locator('li').last().textContent(), 'prefix', 'Typing is one undo group');
        await page.keyboard.press(modifier + '+z');
        assert.equal(canonical((await fuzzSnapshot(page)).html), canonical(before.html), 'Undo restores the removed suffix and following paragraph');
        await page.keyboard.press(modifier + '+Shift+z');
        await page.keyboard.press(modifier + '+Shift+z');
        await assertTyped();
        await saveRoundTrip(session);
        await assertTyped();
    });
}

export async function runReviewOctoberRegressions(browser, origin) {
    const cases = [
        ...['code', 'unordered-list', 'h2'].map(action => ['html-order-' + action, () => htmlOrder(browser, origin, action)]),
        ...['quote', 'unordered-list', 'ordered-list'].map(action => ['html-toggle-' + action, () => htmlToggle(browser, origin, action)]),
        ['authored-html-quote-to-paragraph', () => authoredHtmlQuoteToParagraph(browser, origin)],
        ...['ul', 'ol'].flatMap(tag => [false, true].map(paragraphs =>
            [`remove-${tag}-${paragraphs ? 'paragraphs' : 'bare'}-with-media`, () => removeMediaList(browser, origin, tag, paragraphs)])),
        ...['ul', 'ol'].map(tag => ['remove-partial-' + tag + '-with-media', () => removePartialMediaList(browser, origin, tag)]),
        ['pre-lines-with-table', () => preLines(browser, origin)],
        ...['Delete', 'Backspace'].map(key => ['partial-list-' + key, () => partialListDeletion(browser, origin, key)]),
    ];
    const errors = [];
    for (const [name, run] of cases) {
        if (process.env.EDITOR_REVIEW_CASE && process.env.EDITOR_REVIEW_CASE !== name) continue;
        try {
            await run();
            console.log(`${browser.browserType().name()} October review: ${name} passed`);
        } catch (error) {
            errors.push(error);
            console.error(`${browser.browserType().name()} October review: ${name} FAILED: ${error.message}`);
        }
    }
    if (errors.length) throw new AggregateError(errors, `${browser.browserType().name()}: ${errors.length} October review regressions failed`);
}

async function main() {
    const engines = [chromium, firefox, webkit];
    if (process.env.EDITOR_TEST_BROWSER && !engines.some(engine => engine.name() === process.env.EDITOR_TEST_BROWSER)) throw new Error('Unknown EDITOR_TEST_BROWSER');
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const errors = [];
    try {
        for (const engine of engines) {
            if (process.env.EDITOR_TEST_BROWSER && process.env.EDITOR_TEST_BROWSER !== engine.name()) continue;
            const browser = await engine.launch();
            try { await runReviewOctoberRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            catch (error) { errors.push(error); }
            finally { await browser.close(); }
        }
    } finally {
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
    if (errors.length) throw new AggregateError(errors, 'October editor review regressions failed');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) await main();
