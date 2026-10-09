import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {pixels} from './media-navigation-tests.mjs';
import {formData} from './save-tests.mjs';

const table = '<table id="sample-table"><caption>Original caption</caption><thead><tr><th>Program</th><th>Result</th></tr></thead>'
    + '<tbody><tr><td>First program</td><td>0.47%</td></tr><tr><td>Second program</td><td>1.38%</td></tr></tbody></table>';
const original = '<p>Original paragraph <strong>with formatting</strong>.</p>' + table;

async function settle(page) {
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

async function assertCaretAfterTable(page, body, label) {
    await settle(page);
    const caret = await body.evaluate(body => {
        const selection = getSelection();
        const range = selection?.rangeCount === 1 ? selection.getRangeAt(0) : null;
        const after = document.createRange();
        after.setStartAfter(body.querySelector('table'));
        after.collapse(true);
        const painted = body.querySelector('.has-leading-boundary-caret');
        const style = painted ? getComputedStyle(painted, '::before') : null;
        const bounds = painted?.getBoundingClientRect();
        return {
            afterTable: Boolean(range?.collapsed && body.contains(range.startContainer)
                && range.compareBoundaryPoints(Range.START_TO_START, after) >= 0),
            focused: document.activeElement === body,
            count: body.querySelectorAll('.has-leading-boundary-caret').length,
            color: style?.backgroundColor, content: style?.content,
            x: bounds ? bounds.x + parseFloat(style.left) : NaN,
            y: bounds ? bounds.y + parseFloat(style.top) : NaN,
            width: style ? parseFloat(style.width) : 0,
            height: style ? parseFloat(style.height) : 0,
            viewportHeight: innerHeight,
        };
    });
    assert.ok(caret.afterTable, `${label}: the caret must be outside, after the table: ${JSON.stringify(caret)}`);
    assert.ok(caret.focused, `${label}: keyboard focus stays in the body`);
    assert.equal(caret.count, 1, `${label}: one visible empty-line caret`);
    assert.notEqual(caret.content, 'none');
    assert.ok(caret.width > 0 && caret.height > 0 && caret.y >= 0
        && caret.y + caret.height < caret.viewportHeight, `${label}: caret is on screen: ${JSON.stringify(caret)}`);
    const rendered = pixels(await page.screenshot({clip: {
        x: Math.floor(caret.x), y: Math.floor(caret.y),
        width: Math.ceil(caret.width + 2), height: Math.ceil(caret.height),
    }}));
    const color = caret.color.match(/[\d.]+/gu).slice(0, 3).map(Number);
    let matches = 0;
    for (let index = 0; index < rendered.data.length; index += rendered.channels) {
        if (color.every((value, channel) => Math.abs(rendered.data[index + channel] - value) <= 2)) ++matches;
    }
    assert.ok(matches >= caret.height / 2, `${label}: the screenshot contains the actual painted caret`);
}

async function withTable(browser, origin, {source = original, mobile = false, colorScheme = 'dark'} = {}, run) {
    const page = await browser.newPage({viewport: mobile ? {width: 390, height: 844} : {width: 1280, height: 900},
        hasTouch: mobile, colorScheme, reducedMotion: 'reduce'});
    const errors = [];
    const saved = [];
    page.on('pageerror', error => errors.push(String(error)));
    try {
        await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        await page.route('**/recovery-fixture.css', async route => {
            const response = await route.fetch();
            await route.fulfill({response, body: 'body { margin: 24px; } #content { width: min(960px, 100%); }'
                + '\n@view-transition { navigation: none; }\n.has-leading-boundary-caret::before { animation: none !important; }'});
        });
        await page.route('**/_inplace/post/9', async route => {
            const data = await formData(route);
            assert.equal(data.get('inplace_action'), 'edit');
            saved.push(data.get('body'));
            await route.fulfill({json: {success: true, action: 'edit', revision: saved.length + 1,
                title: data.get('title'), body_html: `<div class="post body" data-post-inplace-body>${data.get('body')}</div>`,
                published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
                tags: [], scheduled: false, message: 'Saved'}});
        });
        await page.goto(origin + '/recovery.html');
        // Supply stored fixture content before opening the editor through its public UI.
        await page.evaluate(source => {
            const card = document.querySelector('.post-card[data-post-id="9"]');
            card.querySelector('[data-post-inplace-body]').innerHTML = source;
            card.querySelector('[name="body"]').value = source;
        }, source);
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
        await run({page, body, saved});
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
}

export async function runTableNavigationRegressions(browser, origin) {
    for (const colorScheme of ['dark', 'light']) {
        for (const mobile of [false, true]) {
            for (const action of ['click', 'down']) {
                const label = `${browser.browserType().name()} table ${colorScheme} ${mobile ? 'mobile' : 'desktop'} ${action}`;
                await withTable(browser, origin, {colorScheme, mobile}, async ({page, body, saved}) => {
                    const initialTable = await body.locator('table').evaluate(table => table.outerHTML);
                    if (action === 'down') {
                        await body.locator('td').last().click();
                        await page.keyboard.press('ArrowDown');
                    } else {
                        const bounds = await body.boundingBox();
                        await page.mouse.click(bounds.x + 5, bounds.y + bounds.height - 3);
                    }
                    await assertCaretAfterTable(page, body, label);
                    for (let count = 0; count < 3; count++) await page.keyboard.press('ArrowDown');
                    await assertCaretAfterTable(page, body, label + ' repeated down');
                    await page.keyboard.type('Text after the table.');
                    const expected = original + '<p>Text after the table.</p>';
                    assert.equal(await body.locator('table').evaluate(table => table.outerHTML), initialTable, `${label}: typing leaves the table intact`);
                    assert.equal(await body.locator(':scope > p').last().textContent(), 'Text after the table.');
                    const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
                    await page.keyboard.press(`${modifier}+z`);
                    await assertCaretAfterTable(page, body, label + ' undo');
                    assert.equal(await body.locator('table').evaluate(table => table.outerHTML), initialTable);
                    await page.keyboard.press(`${modifier}+Shift+z`);
                    assert.equal(await body.locator(':scope > p').last().textContent(), 'Text after the table.');
                    await page.waitForFunction(expected => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                        .list().some(copy => copy.snapshot.body === expected), expected);
                    if (process.env.EDITOR_TABLE_CAPTURE_DIR && colorScheme === 'dark' && !mobile && action === 'click') {
                        await body.screenshot({path: `${process.env.EDITOR_TABLE_CAPTURE_DIR}/${browser.browserType().name()}-table-tail.png`});
                    }
                    await page.getByRole('button', {name: 'Save', exact: true}).click();
                    await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
                    assert.deepEqual(saved, [expected], `${label}: saved HTML retains every paragraph, table and caption, without editor artifacts`);
                    await page.getByRole('button', {name: 'Edit', exact: true}).click();
                    assert.equal(await body.locator(':scope > p').last().textContent(), 'Text after the table.');
                    // Force a real second save without changing the body. An
                    // entirely unchanged edit correctly closes without a POST.
                    await page.keyboard.type(' Changed title');
                    await page.getByRole('button', {name: 'Save', exact: true}).click();
                    await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
                    assert.deepEqual(saved, [expected, expected], `${label}: reopening and resaving do not lose text or add blank lines`);
                });
            }
        }
    }
    await withTable(browser, origin, {}, async ({page, body, saved}) => {
        await body.locator('td').last().click();
        await page.keyboard.press('ArrowDown');
        await assertCaretAfterTable(page, body, 'unchanged table');
        await page.getByRole('textbox', {name: 'Title', exact: true}).click();
        await page.keyboard.type(' Changed title');
        await page.getByRole('button', {name: 'Save', exact: true}).click();
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        assert.deepEqual(saved, [original], 'Navigation alone never adds a saved blank paragraph');
    });
    for (const source of [table, original + '\n<!-- trailing author comment -->\n']) {
        await withTable(browser, origin, {source}, async ({page, body, saved}) => {
            await body.locator('td').last().click();
            await page.keyboard.press('ArrowDown');
            await assertCaretAfterTable(page, body, 'table with no following authored paragraph');
            await page.keyboard.type('Tail.');
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            // Multipart form encoding uses CRLF; only those transport line
            // endings may differ from the authored DOM source.
            assert.deepEqual(saved.map(html => html.replaceAll('\r\n', '\n')), [source + '<p>Tail.</p>'],
                'Tables alone, whitespace and authored comments stay intact');
        });
    }
    await withTable(browser, origin, {source: original + '<p>Existing tail.</p>'}, async ({page, body, saved}) => {
        assert.equal(await body.locator(':scope > p').count(), 2, 'An existing following paragraph is reused, not preceded by an empty line');
        await body.locator('td').last().click();
        await page.keyboard.press('ArrowDown');
        assert.equal(await body.evaluate(body => body.lastElementChild.contains(getSelection().anchorNode)), true,
            'Down from the last row enters the existing following paragraph');
        await body.locator('p').last().click();
        const end = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta+ArrowRight' : 'End');
        await page.keyboard.press(end);
        await page.keyboard.type(' More.');
        await page.getByRole('button', {name: 'Save', exact: true}).click();
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        assert.deepEqual(saved, [original + '<p>Existing tail. More.</p>']);
    });
    await withTable(browser, origin, {}, async ({page, body, saved}) => {
        await body.locator('td').last().click();
        await page.keyboard.press('ArrowDown');
        const before = await body.locator(':scope > p').last().boundingBox();
        const lineHeight = await body.evaluate(body => parseFloat(getComputedStyle(body).lineHeight));
        for (let count = 1; count <= 2; count++) {
            await page.keyboard.press('Enter');
            await assertCaretAfterTable(page, body, `Enter ${count} after the table`);
            const current = await body.locator(':scope > p').last().boundingBox();
            assert.ok(Math.abs(current.y - before.y - count * lineHeight) < 1, 'One Enter adds exactly one visible line');
        }
        await page.keyboard.type('After two breaks.');
        await page.getByRole('button', {name: 'Save', exact: true}).click();
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        assert.deepEqual(saved, [original + '<p><br></p><p><br></p><p>After two breaks.</p>']);
    });
    await withTable(browser, origin, {}, async ({page, body}) => {
        await body.locator('td').last().click();
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('ArrowUp');
        assert.equal(await body.evaluate(() => {
            const node = getSelection().anchorNode;
            return Boolean((node instanceof Element ? node : node?.parentElement)?.closest('td, th'));
        }), true,
            'Up from the following paragraph still enters an editable table cell: ' + JSON.stringify(await body.evaluate(() => {
                const selection = getSelection();
                const node = selection.anchorNode;
                return {node: node.nodeName, offset: selection.anchorOffset,
                    parent: (node instanceof Element ? node : node.parentElement).outerHTML};
            })));
        await page.keyboard.type('Cell edit.');
        assert.ok((await body.locator('table').textContent()).includes('Cell edit.'), 'Cell editing remains native');
    });
    const tall = '<table id="sample-table"><tbody>' + Array.from({length: 40}, (_, index) =>
        `<tr><td>Row ${index + 1}</td><td>Value ${index + 1}</td></tr>`).join('') + '</tbody></table>';
    for (const mobile of [false, true]) {
        await withTable(browser, origin, {source: tall, mobile}, async ({page, body}) => {
            await body.locator('td').last().click();
            await page.keyboard.press('ArrowDown');
            await assertCaretAfterTable(page, body, 'table taller than the viewport');
            await page.keyboard.type('After a tall table.');
            assert.equal(await body.locator(':scope > p').last().textContent(), 'After a tall table.');
            assert.equal(await body.locator('table').evaluate(table => table.outerHTML), tall);
        });
    }
    console.log('table navigation: real clicks and Down show a painted caret; typing, undo/redo and repeated saving preserve all content');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit].filter(engine => !process.env.EDITOR_TEST_BROWSER
            || engine.name() === process.env.EDITOR_TEST_BROWSER)) {
            const browser = await engine.launch();
            try { await runTableNavigationRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
