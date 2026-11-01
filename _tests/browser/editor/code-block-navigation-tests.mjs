import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {formData} from './save-tests.mjs';

const code = '<pre><code class="language-shell">first command\nlast command</code></pre>';
const original = '<p>Introduction.</p>' + code;
const normalizeTransport = html => html.replaceAll('\r\n', '\n');

async function withCode(browser, origin, {source = original, mobile = false} = {}, run) {
    const page = await browser.newPage({viewport: mobile ? {width: 390, height: 844} : {width: 1100, height: 900},
        hasTouch: mobile, colorScheme: 'dark', reducedMotion: 'reduce'});
    page.setDefaultTimeout(10000);
    const errors = [];
    const saved = [];
    page.on('pageerror', error => errors.push(String(error)));
    try {
        await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        await page.route('**/recovery.html', async route => {
            const response = await route.fetch();
            // The recovery fixture's reduced menu omits block-format controls.
            await route.fulfill({response, body: (await response.text()).replace(
                '<div class="post-editor-context-main">',
                '<div class="post-editor-context-main"><button type="button" data-context-action="code">Code block</button>',
            )});
        });
        await page.route('**/recovery-fixture.css', async route => {
            const response = await route.fetch();
            await route.fulfill({response, body: 'body { margin: 24px; } #content { width: min(960px, 100%); }'});
        });
        await page.route('**/_inplace/post/9', async route => {
            const data = await formData(route);
            assert.equal(data.get('inplace_action'), 'edit');
            saved.push(normalizeTransport(data.get('body')));
            await route.fulfill({json: {success: true, action: 'edit', revision: saved.length + 1,
                title: data.get('title'), body_html: `<div class="post body" data-post-inplace-body>${data.get('body')}</div>`,
                published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
                tags: [], scheduled: false, message: 'Saved'}});
        });
        await page.goto(origin + '/recovery.html');
        await page.evaluate(source => {
            const card = document.querySelector('[data-post-id="9"]');
            card.querySelector('[data-post-inplace-body]').innerHTML = source;
            card.querySelector('[name="body"]').value = source;
        }, source);
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
        await run({page, body, saved});
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
}

async function assertOutsideCode(body, label) {
    assert.equal(await body.evaluate(body => {
        const selection = getSelection();
        const node = selection?.anchorNode;
        const element = node instanceof Element ? node : node?.parentElement;
        const range = selection?.rangeCount === 1 ? selection.getRangeAt(0) : null;
        const after = document.createRange();
        after.setStartAfter(body.querySelector('pre'));
        after.collapse(true);
        return document.activeElement === body && range?.collapsed && body.contains(node)
            && !element.closest('pre') && range.compareBoundaryPoints(Range.START_TO_START, after) >= 0;
    }), true, label + ': the focused caret belongs to ordinary text after the code');
}

async function moveAfterCode(page, body, action, mobile = false) {
    if (action === 'click') {
        await body.scrollIntoViewIfNeeded();
        const bounds = await body.boundingBox();
        const x = bounds.x + 6;
        const y = bounds.y + bounds.height - 3;
        if (mobile) await page.touchscreen.tap(x, y); else await page.mouse.click(x, y);
    } else {
        await body.locator('pre').click();
        await body.locator('pre').evaluate(pre => {
            const range = document.createRange();
            range.selectNodeContents(pre);
            range.collapse(false);
            getSelection().removeAllRanges();
            getSelection().addRange(range);
        });
        await page.keyboard.press(action === 'down' ? 'ArrowDown' : 'Enter');
    }
}

async function save(page) {
    await page.getByRole('button', {name: 'Save', exact: true}).click();
    await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
}

export async function runCodeBlockNavigationRegressions(browser, origin) {
    const sources = [original, '<pre>  first\n\tlast  </pre>',
        '<pre><code>first command\nlast command\n</code></pre>',
        '<pre><code><span class="hljs-keyword">echo</span> first\n<span class="hljs-built_in">exit</span> 0</code></pre>',
        original + '\n<!-- Author comment -->\n'];
    for (const mobile of [false, true]) {
        for (const source of mobile ? [original] : sources) {
            for (const action of ['click', 'down']) {
                const label = `${browser.browserType().name()} ${mobile ? 'touch' : 'desktop'} ${action}`;
                await withCode(browser, origin, {source, mobile}, async ({page, body, saved}) => {
                    const initialCode = await body.locator('pre').evaluate(pre => pre.outerHTML);
                    await moveAfterCode(page, body, action, mobile);
                    await assertOutsideCode(body, label);
                    if (process.env.EDITOR_CODE_CAPTURE_DIR && !mobile && source === original && action === 'click') {
                        await body.screenshot({path: `${process.env.EDITOR_CODE_CAPTURE_DIR}/${browser.browserType().name()}-code-tail.png`, caret: 'initial'});
                    }
                    for (let count = 0; count < 3; count++) await page.keyboard.press('ArrowDown');
                    await assertOutsideCode(body, label + ' repeated Down');
                    await page.keyboard.type('Text after code.');
                    assert.equal(await body.locator('pre').evaluate(pre => pre.outerHTML), initialCode, 'Typing never changes the existing code');
                    assert.equal(await body.locator(':scope > p').last().textContent(), 'Text after code.');
                    const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
                    await page.keyboard.press(`${modifier}+z`);
                    await assertOutsideCode(body, label + ' undo');
                    assert.equal(await body.locator(':scope > p').last().textContent(), '');
                    await page.keyboard.press(`${modifier}+Shift+z`);
                    assert.equal(await body.locator(':scope > p').last().textContent(), 'Text after code.');
                    const expected = source + '<p>Text after code.</p>';
                    await save(page);
                    assert.deepEqual(saved, [expected], 'Saving preserves code whitespace and highlights without editor artifacts');
                    await page.getByRole('button', {name: 'Edit', exact: true}).click();
                    await page.getByRole('textbox', {name: 'Title', exact: true}).fill('Changed title');
                    await save(page);
                    assert.deepEqual(saved, [expected, expected], 'A second save adds no blank paragraphs');
                });
            }
        }
    }
    await withCode(browser, origin, {}, async ({page, body, saved}) => {
        await moveAfterCode(page, body, 'click');
        await page.getByRole('textbox', {name: 'Title', exact: true}).fill('Changed title');
        await save(page);
        assert.deepEqual(saved, [original], 'The unused caret line is never saved');
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        await moveAfterCode(page, body, 'enter');
        await assertOutsideCode(body, 'Enter at the end of code');
        assert.equal(await body.locator(':scope > p').count(), 2, 'One Enter uses one ordinary line after code');
        await page.keyboard.type('After Enter.');
        await save(page);
        assert.deepEqual(saved, [original, original + '<p>After Enter.</p>']);
    });
    await withCode(browser, origin, {source: original + '<p>Existing tail.</p>'}, async ({page, body}) => {
        assert.equal(await body.locator(':scope > p').count(), 2, 'Existing prose after code needs no extra caret line');
    });
    await withCode(browser, origin, {}, async ({page, body}) => {
        await body.locator('pre').click();
        await body.locator('pre').evaluate(pre => {
            const text = pre.querySelector('code').lastChild;
            const range = document.createRange(); range.setStart(text, text.length); range.collapse(true);
            getSelection().removeAllRanges(); getSelection().addRange(range);
        });
        await page.keyboard.press('Shift+Enter');
        await page.keyboard.type('Another code line.');
        // WebKit natively splits PRE here, while keeping the caret/code style.
        assert.ok((await body.locator('pre').allTextContents()).join('\n').endsWith('Another code line.'), 'Shift+Enter keeps typing in code');
        assert.equal(await body.evaluate(() => Boolean(getSelection().anchorNode.parentElement.closest('pre'))), true);
        assert.equal(await body.locator(':scope > p').last().textContent(), '', 'An internal code line never consumes the following prose line');
    });
    for (const action of ['paste', 'format']) {
        await withCode(browser, origin, {source: action === 'paste' ? original : '<p>Command.</p>'}, async ({page, body}) => {
            await body.click();
            if (action === 'paste') {
                const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
                await body.locator('pre').evaluate(pre => {
                    const range = document.createRange();
                    range.selectNode(pre);
                    getSelection().removeAllRanges(); getSelection().addRange(range);
                });
                await page.keyboard.press(`${modifier}+c`);
                await body.evaluate(body => {
                    const range = document.createRange();
                    range.selectNodeContents(body);
                    getSelection().removeAllRanges(); getSelection().addRange(range);
                });
                await page.keyboard.press(`${modifier}+v`);
                await page.waitForFunction(() => {
                    const body = document.querySelector('.post-card.is-editing [data-post-inplace-body]');
                    return body.querySelector('pre') && !body.textContent.includes('Introduction.');
                });
            } else {
                await body.press('Shift+F10');
                await page.locator('.post-editor-context-menu [data-context-action="code"]').click();
            }
            const initialCode = await body.locator('pre').evaluate(pre => pre.outerHTML);
            await moveAfterCode(page, body, 'click');
            await assertOutsideCode(body, action + ' creates a usable following line');
            await page.keyboard.type('After a new code block.');
            assert.equal(await body.locator('pre').evaluate(pre => pre.outerHTML), initialCode);
            assert.equal(await body.locator(':scope > p').last().textContent(), 'After a new code block.');
        });
    }
    console.log(`${browser.browserType().name()}: code-block tails accept click, tap, Down and Enter; undo, redo, paste, formatting and two saves preserve code`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit].filter(engine => !process.env.EDITOR_TEST_BROWSER
            || engine.name() === process.env.EDITOR_TEST_BROWSER)) {
            const browser = await engine.launch();
            try { await runCodeBlockNavigationRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
