import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';

const quoteButton = '[data-comment-command="formatBlock"][data-comment-command-value="blockquote"]';

async function setup(page, html, target = 'blockquote') {
    await page.evaluate(({html, target}) => {
        const comment = setupComment(html);
        selectEnd(target ? comment.surface.querySelector(target) : comment.surface);
    }, {html, target});
    await page.waitForFunction(() => {
        const range = getSelection().getRangeAt(0);
        const element = range.startContainer instanceof Element ? range.startContainer : range.startContainer.parentElement;
        return document.querySelector('[data-comment-command-value="blockquote"]').getAttribute('aria-pressed')
            === (element.closest('blockquote') ? 'true' : 'false');
    });
}

async function inspect(page) {
    return page.evaluate(() => {
        const surface = document.querySelector('.comment-editor-surface');
        const range = getSelection().getRangeAt(0);
        const element = range.startContainer instanceof Element ? range.startContainer : range.startContainer.parentElement;
        return {
            inQuote: !!element.closest('blockquote'),
            inEditor: surface.contains(range.startContainer),
            html: surface.innerHTML,
            source: document.querySelector('.comment-editor-source').value,
            quoteText: Array.from(surface.querySelectorAll('blockquote'), quote => quote.textContent),
            text: surface.textContent,
            active: document.querySelector('[data-comment-command-value="blockquote"]').getAttribute('aria-pressed'),
            accidentalStyles: !!surface.querySelector('[style], font'),
        };
    });
}

async function assertOutside(page, label) {
    const result = await inspect(page);
    assert.equal(result.inQuote, false, `${label}: caret must leave all quote wrappers (${result.html})`);
    assert.equal(result.inEditor, true, `${label}: keep an editable caret in the comment`);
    assert.equal(result.active, 'false', `${label}: the quote button must switch off`);
    assert.equal(result.accidentalStyles, false, `${label}: do not copy computed quote styling into the HTML`);
    return result;
}

export async function runCommentQuoteRegressions(browser, origin) {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    const name = browser.browserType().name();
    const modifier = await page.evaluate(() => /Mac|iPhone|iPad|iPod/u.test(navigator.platform) ? 'Meta' : 'Control');
    try {
        await page.goto(origin + '/comment.html');
        await page.waitForFunction(() => typeof setupComment === 'function');

        // Reproduce the normal typing workflow, not an artificial empty-block event.
        await setup(page, '', null);
        await page.keyboard.type('Typed quote.');
        await page.locator(quoteButton).click();
        assert.equal((await inspect(page)).inQuote, true);
        await page.keyboard.press('Enter');
        assert.equal((await inspect(page)).inQuote, true, 'The first Enter continues a nonempty quote');
        await page.keyboard.press('Enter');
        await assertOutside(page, 'Typed quote, second Enter');
        await page.keyboard.type('Outside.');
        assert.equal((await inspect(page)).text, 'Typed quote.Outside.');
        assert.equal((await inspect(page)).quoteText.join(''), 'Typed quote.');

        const fixtures = [
            ['direct quote', '<p>Before.</p><blockquote>Quote.</blockquote><p>After.</p>', 'blockquote'],
            ['paragraph in quote', '<p>Before.</p><blockquote><p>Quote.</p></blockquote><p>After.</p>', 'blockquote p'],
            ['formatted quote', '<blockquote><p><strong>Quote <em>text.</em></strong></p></blockquote>', 'blockquote p'],
            ['links and formulas', '<blockquote><p>A <a href="https://example.test/source?q=x&amp;mode=full">source</a> and $$x+1$$.</p></blockquote>', 'blockquote p'],
            ['quote in list item', '<ul><li>Before.<blockquote><p>Quote.</p></blockquote>After.</li></ul>', 'blockquote p'],
            ['nested quotes', '<blockquote><p>Outer.</p><blockquote><p>Quote.</p></blockquote><p>Following.</p></blockquote><p>After.</p>', 'blockquote blockquote p'],
            ['middle paragraph', '<blockquote><p>Before.</p><p>Quote.</p><p>After.</p></blockquote><p>Outside after.</p>', 'blockquote p:nth-child(2)'],
            ['soft lines', '<blockquote>First.<br>Quote.</blockquote><p>After.</p>', 'blockquote'],
        ];
        for (const [label, html, target] of fixtures) {
            await setup(page, html, target);
            const originalText = (await inspect(page)).text;
            await page.keyboard.press('Enter');
            if (label === 'quote in list item') {
                // Some engines already leave an inner quote on the first Enter.
                if ((await inspect(page)).inQuote) await page.keyboard.press('Enter');
            } else {
                assert.equal((await inspect(page)).inQuote, true, `${label}: first Enter continues the quote`);
                await page.keyboard.press('Enter');
            }
            const outside = await assertOutside(page, label);
            assert.equal(outside.text, originalText, `${label}: Enter must retain every word`);
            await page.keyboard.type('New response.');
            const result = await inspect(page);
            assert.equal(result.inQuote, false, `${label}: real typing must remain outside the quote`);
            assert.equal(result.quoteText.join('').includes('New response.'), false, `${label}: never move the response into a quote`);
            assert.equal(result.source.includes('New response.'), true, `${label}: retain the response in the submitted source`);
            if (label === 'links and formulas') {
                assert.equal(result.source.includes('$$x+1$$'), true, 'The submitted formula source must survive unchanged');
                assert.equal(await page.locator('.comment-editor-surface a').getAttribute('href'), 'https://example.test/source?q=x&mode=full');
            }
            if (label === 'quote in list item') {
                assert.ok(await page.locator('.comment-editor-surface li').count() > 0, 'A native item split may add a sibling, but must not unlist the text');
            }
            assert.equal(result.accidentalStyles, false);
            await page.evaluate(() => RegisterCommentEditor.html(document.getElementById('fixture')));
            assert.equal((await inspect(page)).source, result.source, `${label}: repeated form sync is lossless`);
        }

        await setup(page, '<ul><li>Before.<blockquote><br></blockquote>After.</li></ul>');
        await page.keyboard.press('Enter');
        await assertOutside(page, 'Empty quote inside a list item');
        assert.equal(await page.locator('.comment-editor-surface li').count(), 1);
        assert.equal((await inspect(page)).text, 'Before.After.');

        // Toggle only the selected paragraph, retaining the quotes on either side.
        await setup(page, '<p>Intro.</p><blockquote><p>First.</p><p><strong>Middle.</strong></p><p>Last.</p></blockquote><p>After.</p>', 'blockquote p:nth-child(2)');
        assert.equal((await inspect(page)).active, 'true', 'A nested paragraph must highlight the quote button');
        await page.locator(quoteButton).click();
        const toggled = await assertOutside(page, 'Quote button on a nested paragraph');
        assert.equal(toggled.text, 'Intro.First.Middle.Last.After.');
        assert.deepEqual(toggled.quoteText, ['First.', 'Last.']);
        assert.equal(await page.locator('.comment-editor-surface strong').textContent(), 'Middle.');
        await page.keyboard.press(`${modifier}+z`);
        assert.equal((await inspect(page)).inQuote, true, 'Undo restores quote membership and caret');
        assert.equal((await inspect(page)).text, toggled.text);
        await page.keyboard.press(`${modifier}+Shift+z`);
        await assertOutside(page, 'Redo quote-button exit');

        await setup(page, '<blockquote><p>Quote.</p></blockquote>', 'blockquote p');
        await page.keyboard.press('Enter');
        await page.keyboard.press('Enter');
        await assertOutside(page, 'Exit before undo');
        await page.keyboard.press(`${modifier}+z`);
        assert.equal((await inspect(page)).inQuote, true, 'Native undo restores the quoted line');
        assert.equal((await inspect(page)).text, 'Quote.');
        await page.keyboard.press(`${modifier}+Shift+z`);
        await assertOutside(page, 'Native redo restores the exit');

        // Mobile/virtual keyboards may emit beforeinput without a preceding keydown.
        await setup(page, '<blockquote>Quote.</blockquote>');
        await page.keyboard.press('Enter');
        const canceled = await page.evaluate(() => {
            const event = new InputEvent('beforeinput', {inputType: 'insertParagraph', bubbles: true, cancelable: true});
            document.querySelector('.comment-editor-surface').dispatchEvent(event);
            return event.defaultPrevented;
        });
        assert.equal(canceled, true, 'Virtual-keyboard paragraph input must handle the exit exactly once');
        await assertOutside(page, 'beforeinput exit');
        await page.keyboard.type('Mobile response.');
        assert.equal((await inspect(page)).inQuote, false);
        assert.equal((await inspect(page)).quoteText.join(''), 'Quote.');

        // Soft breaks, quoted list items and code retain their native behavior.
        await setup(page, '<blockquote>Quote.</blockquote>');
        await page.keyboard.press('Shift+Enter');
        await page.keyboard.press('Shift+Enter');
        assert.equal((await inspect(page)).inQuote, true);
        await page.keyboard.type('Still quoted.');
        assert.equal((await inspect(page)).quoteText.join(''), 'Quote.Still quoted.');
        for (const [html, target] of [
            ['<blockquote><ul><li>Item.</li></ul></blockquote>', 'li'],
            ['<blockquote><pre>Code.</pre></blockquote>', 'pre'],
        ]) {
            await setup(page, html, target);
            await page.keyboard.press('Enter');
            await page.keyboard.press('Enter');
            assert.equal((await inspect(page)).inQuote, true, `${target}: do not intercept the nested structure`);
            assert.equal((await inspect(page)).text.includes(target === 'li' ? 'Item.' : 'Code.'), true);
        }

        await setup(page, '<blockquote><ul><li>First.</li><li><strong>Second.</strong></li></ul></blockquote>', 'li:nth-child(2)');
        await page.locator(quoteButton).click();
        assert.deepEqual(await page.locator('.comment-editor-surface li').allTextContents(), ['First.', 'Second.'],
            'Quote formatting must not turn an existing list item into unlisted text');
        assert.equal(await page.locator('.comment-editor-surface strong').textContent(), 'Second.');
        await setup(page, '<blockquote><p>Intro.</p><ul><li>First.</li><li>Second.</li></ul></blockquote>');
        await page.evaluate(() => {
            const range = document.createRange();
            range.selectNodeContents(document.querySelector('.comment-editor-surface blockquote'));
            getSelection().removeAllRanges();
            getSelection().addRange(range);
        });
        await page.locator(quoteButton).click();
        assert.deepEqual(await page.locator('.comment-editor-surface li').allTextContents(), ['First.', 'Second.'],
            'Quote formatting on a selection containing a list must preserve its items');

        await setup(page, '<blockquote>Quote.</blockquote>');
        await page.keyboard.press('Enter');
        await page.keyboard.press('Alt+Enter');
        assert.equal((await inspect(page)).inQuote, true, 'Alt+Enter remains a native key, not an empty-quote exit');

        await setup(page, '<blockquote><p>First.</p><p>Second.</p></blockquote>');
        await page.keyboard.press('Enter');
        assert.equal((await inspect(page)).text, 'First.Second.', 'A quote-container caret must retain all paragraphs');

        await setup(page, '<blockquote><p>Before.</p><p>Replace.</p><p>After.</p></blockquote>', 'p:nth-child(2)');
        await page.evaluate(() => {
            const range = document.createRange();
            range.selectNodeContents(document.querySelector('.comment-editor-surface p:nth-child(2)'));
            getSelection().removeAllRanges();
            getSelection().addRange(range);
        });
        await page.keyboard.press('Enter');
        assert.equal((await inspect(page)).inQuote, true, 'Enter replacing selected text must not trigger the empty-line exit');
        assert.equal((await inspect(page)).text, 'Before.After.');

        await setup(page, '<blockquote>Quote.</blockquote>');
        await page.keyboard.press('Enter');
        const composing = await page.evaluate(() => {
            const surface = document.querySelector('.comment-editor-surface');
            const key = new KeyboardEvent('keydown', {key: 'Enter', isComposing: true, bubbles: true, cancelable: true});
            const input = new InputEvent('beforeinput', {inputType: 'insertParagraph', isComposing: true, bubbles: true, cancelable: true});
            surface.dispatchEvent(key);
            surface.dispatchEvent(input);
            return [key.defaultPrevented, input.defaultPrevented];
        });
        assert.deepEqual(composing, [false, false], 'Do not intercept IME composition confirmation');
        assert.equal((await inspect(page)).inQuote, true);
        assert.deepEqual(errors, []);
        console.log(`${name}: comment quotes exit with real Enter/button, preserve all text/formatting, and retain undo/redo, soft breaks, IME and virtual-keyboard input`);
    } finally {
        await page.close();
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const engines = [chromium, firefox, webkit];
    const selected = process.env.EDITOR_TEST_BROWSER;
    if (selected && !engines.some(engine => engine.name() === selected)) throw new Error(`Unknown EDITOR_TEST_BROWSER: ${selected}`);
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of engines.filter(engine => !selected || engine.name() === selected)) {
            const browser = await engine.launch();
            try { await runCommentQuoteRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally {
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
}
