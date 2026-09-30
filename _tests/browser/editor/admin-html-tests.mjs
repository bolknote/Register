import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

export async function runAdminHtmlRegressions(browser, origin) {
    const errors = [];
    const draftTarget = '9';
    const value = page => page.evaluate(() => window.adminEditor.getValue());
    async function withPage(initial, run) {
        const context = await browser.newContext();
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        page.on('pageerror', error => errors.push(String(error)));
        await page.route('**/admin.html?*', async route => {
            const response = await route.fetch();
            const encoded = initial.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
            await route.fulfill({response, body: (await response.text())
                .replace('>Server body</textarea>', `>${encoded}</textarea>`)});
        });
        await page.goto(origin + '/admin.html?id=9&codemirror=1&toolbar=1&alt=1');
        await page.waitForFunction(() => window.adminEditorReady);
        try { await run(page); }
        finally { await context.close(); }
    }
    async function placeCaret(page, index) {
        await page.evaluate(index => {
            const cm = document.querySelector('.CodeMirror').CodeMirror;
            cm.setCursor(cm.posFromIndex(index));
            cm.clearHistory();
            cm.focus();
        }, index);
    }
    async function editAlt(page, text) {
        await placeCaret(page, 5);
        await page.locator('.ai-image-alt-text').click();
        await page.locator('.ai-image-alt-input').fill(text);
    }

    const image = '<img src="/example.png" alt="Saved description">';
    const changedImage = image.replace('Saved description', 'Unfinished description');
    for (const leave of ['accept', 'dismiss', 'pagehide']) {
        await withPage(image, async page => {
            await editAlt(page, 'Unfinished description');
            if (leave === 'pagehide') {
                // Mobile lifecycle can skip beforeunload: commit before the
                // form's pagehide listener persists its recovery copy.
                await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide')));
                assert.equal(await value(page), changedImage);
                assert.equal(await page.evaluate(key => window.readAdminDraft(key), draftTarget), changedImage);
                return;
            }
            const warned = page.waitForEvent('dialog');
            const reload = page.evaluate(() => { location.reload(); });
            const dialog = await warned;
            assert.equal(dialog.type(), 'beforeunload');
            if (leave === 'accept') {
                const navigated = page.waitForNavigation();
                await dialog.accept();
                await navigated;
                await page.waitForFunction(() => window.adminEditorReady);
                await page.getByRole('button', {name: 'Restore draft', exact: true}).first().click();
            } else {
                await dialog.dismiss();
            }
            await reload;
            assert.equal(await value(page), changedImage);
            assert.equal(await page.evaluate(key => window.readAdminDraft(key), draftTarget), changedImage);
            assert.equal(await page.evaluate(() => Boolean(window.onbeforeunload())), true,
                'Native form restoration must not make an unsaved description look saved');
            if (leave === 'dismiss') {
                await page.getByRole('button', {name: 'Undo', exact: true}).click();
                assert.equal(await value(page), image, 'Committing on exit is one undoable edit');
                assert.equal(await page.evaluate(key => window.readAdminDraft(key), draftTarget), null);
            }
        });
        console.log(`admin alt recovery: ${leave} retains the unfinished description`);
    }

    await withPage(image, async page => {
        await editAlt(page, 'Cancelled description');
        await page.locator('.ai-image-alt-input').press('Escape');
        assert.equal(await value(page), image);
        assert.equal(await page.evaluate(() => window.onbeforeunload()), undefined);
        await page.locator('.ai-image-alt-text').click();
        assert.equal(await page.evaluate(() => window.onbeforeunload()), undefined);
        assert.equal(await page.evaluate(key => window.readAdminDraft(key), draftTarget), null);
    });
    console.log('admin alt recovery: cancelled and unchanged fields stay clean');

    const blockCases = [
        ['adjacent lines', '<p>one</p>\n<p>two</p>', 'two', '<p>one</p>\n<h2>two</h2>'],
        ['same line', '<p>one</p><p>two</p>', 'two', '<p>one</p><h2>two</h2>'],
        ['blank separator', '<p>one</p>\n\n<p>two</p>', 'two', '<p>one</p>\n\n<h2>two</h2>'],
        ['nested blocks', '<blockquote><p>one</p><p title="x > y">two</p></blockquote>\n<h3>next</h3>',
            'two', '<blockquote><p>one</p><h2 title="x > y">two</h2></blockquote>\n<h3>next</h3>'],
        ['untagged between blocks', '<p>one</p>\ntwo\n<p>three</p>', 'two', '<p>one</p>\n<h2>two</h2>\n<p>three</p>'],
        ['plain multiline text', 'one\ntwo', 'two', '<h2>one\ntwo</h2>'],
        ['empty line inside a block', '<p>one\n\ntwo</p>\n<p>three</p>', '\n\n', '<h2>one\n\ntwo</h2>\n<p>three</p>'],
        ['caret after closing tag', '<p>two</p>', null, '<h2>two</h2>'],
    ];
    for (const [name, original, caret, expected] of blockCases) {
        await withPage(original, async page => {
            await placeCaret(page, caret === null ? original.length : original.indexOf(caret) + 1);
            await page.getByRole('button', {name: 'Heading 2', exact: true}).click();
            assert.equal(await value(page), expected);
            const position = await page.evaluate(() => {
                const cm = document.querySelector('.CodeMirror').CodeMirror;
                return cm.indexFromPos(cm.getCursor());
            });
            if (caret === 'two') assert.equal(position, expected.indexOf('two') + 1, 'The caret follows its original text');
            await page.getByRole('button', {name: 'Undo', exact: true}).click();
            assert.equal(await value(page), original);
            await page.getByRole('button', {name: 'Redo', exact: true}).click();
            assert.equal(await value(page), expected);
        });
        console.log(`admin blocks: ${name} preserves neighbouring HTML and undo/redo`);
    }

    for (const selection of [false, true]) {
        await withPage('<p>alpha</p>', async page => {
            await placeCaret(page, 5);
            if (selection) await page.evaluate(() => {
                const cm = document.querySelector('.CodeMirror').CodeMirror;
                cm.setSelection(cm.posFromIndex(0), cm.posFromIndex(cm.getValue().length));
            });
            await page.getByRole('button', {name: 'Preformatted', exact: true}).click();
            assert.equal(await value(page), '<pre>alpha</pre>');
            await page.getByRole('button', {name: 'Paragraph', exact: true}).click();
            assert.equal(await value(page), '<p>alpha</p>');
            await page.getByRole('button', {name: 'Undo', exact: true}).click();
            assert.equal(await value(page), '<pre>alpha</pre>');
            await page.getByRole('button', {name: 'Undo', exact: true}).click();
            assert.equal(await value(page), '<p>alpha</p>');
        });
        console.log(`admin blocks: pre round trip with ${selection ? 'selection' : 'caret'} is reversible`);
    }

    await withPage('    one\n\ttwo  ', async page => {
        await placeCaret(page, 10);
        await page.getByRole('button', {name: 'Preformatted', exact: true}).click();
        assert.equal(await value(page), '<pre>    one\n\ttwo  </pre>');
        await page.getByRole('button', {name: 'Undo', exact: true}).click();
        assert.equal(await value(page), '    one\n\ttwo  ');
        await page.getByRole('button', {name: 'Redo', exact: true}).click();
        assert.equal(await value(page), '<pre>    one\n\ttwo  </pre>');
    });
    console.log('admin blocks: preformatted text keeps indentation and trailing spaces');

    for (const [original, expected] of [
        ['<p>one</p>\n<pre>two</pre>', '<h2>one</h2>\n<h2>two</h2>'],
        ['<picture><img src="/example.png" alt="Existing"></picture>', '<h2><picture><img src="/example.png" alt="Existing"></picture></h2>'],
        ['<progress value="1">Progress</progress>', '<h2><progress value="1">Progress</progress></h2>'],
        ['<P title="x > y">one</P>', '<h2 title="x > y">one</h2>'],
    ]) {
        await withPage(original, async page => {
            await page.evaluate(() => {
                const cm = document.querySelector('.CodeMirror').CodeMirror;
                cm.setSelection(cm.posFromIndex(cm.getValue().length), cm.posFromIndex(0));
                cm.clearHistory();
                cm.focus();
            });
            await page.getByRole('button', {name: 'Heading 2', exact: true}).click();
            assert.equal(await value(page), expected);
            await page.getByRole('button', {name: 'Undo', exact: true}).click();
            assert.equal(await value(page), original);
        });
        console.log('admin blocks: selected complete blocks use exact tag names and preserve unrelated markup');
    }

    for (const [original, oldAlt, expected] of [
        ['<img src="/example.png" alt="x > y">', 'x > y', '<img src="/example.png" alt="New &amp; safe &gt; description">'],
        ["<img src='/example.png' alt='x > y'>", 'x > y', "<img src='/example.png' alt='New &amp; safe &gt; description'>"],
        ['<img title="x > y" src="/example.png" />', '', '<img title="x > y" src="/example.png" alt="New &amp; safe &gt; description" />'],
        ['<img data-src="/other.png" src="/example.png" data-alt="Other" alt="Existing">', 'Existing',
            '<img data-src="/other.png" src="/example.png" data-alt="Other" alt="New &amp; safe &gt; description">'],
        ["<img title='alt=\"Other\" > text' src=/example.png alt=Existing>", 'Existing',
            "<img title='alt=\"Other\" > text' src=/example.png alt=\"New &amp; safe &gt; description\">"],
    ]) {
        await withPage(original, async page => {
            await placeCaret(page, 5);
            assert.equal(await page.locator('.ai-image-alt-preview img').getAttribute('src'), '/example.png');
            await page.locator('.ai-image-alt-text').click();
            const input = page.locator('.ai-image-alt-input');
            assert.equal(await input.inputValue(), oldAlt);
            await input.fill('New & safe > description');
            await input.press('Enter');
            assert.equal(await value(page), expected);
            await page.getByRole('button', {name: 'Undo', exact: true}).click();
            assert.equal(await value(page), original);
            await page.getByRole('button', {name: 'Redo', exact: true}).click();
            assert.equal(await value(page), expected);
            // The same scanner and occurrence marker are used by delayed AI.
            const ai = holdRequests(page, '**/admin-ai-alt');
            await ai.installed;
            await page.locator('.ai-image-alt-regenerate').click();
            const request = await ai.next();
            assert.equal((await formData(request)).get('image_src'), '/example.png');
            await request.fulfill({json: {success: true, result: 'Generated description'}});
            await page.waitForFunction(() => window.adminEditor.getValue().includes('Generated description'));
            assert.equal(await value(page), expected.replace('New &amp; safe &gt; description', 'Generated description'));
        });
        console.log('admin images: quoted boundaries and exact attributes survive manual/AI changes and undo/redo');
    }

    for (const original of [
        '<!-- <img src="/comment.png" alt="Comment"> -->',
        '<script>const template = \'<img src="/script.png">\';</script>',
        '<textarea><img src="/textarea.png"></textarea>',
        '<p title=\'<img src="/attribute.png">\'>Text</p>',
    ]) {
        await withPage(original, async page => {
            await placeCaret(page, original.indexOf('<img') + 5);
            assert.equal(await page.locator('.ai-image-alt-preview').count(), 0);
            assert.equal(await value(page), original);
        });
        console.log('admin images: literal image markup is not treated as an editable image');
    }
    assert.deepEqual(errors, []);
}
