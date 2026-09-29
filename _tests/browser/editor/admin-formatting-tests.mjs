import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const value = page => page.evaluate(() => window.adminEditor.getValue());

async function withEditor(browser, origin, run) {
    const page = await browser.newPage();
    const errors = [];
    page.setDefaultTimeout(10000);
    page.on('pageerror', error => errors.push(String(error)));
    try {
        await page.goto(origin + '/admin.html?id=9&codemirror=1&toolbar=1');
        await page.waitForFunction(() => window.adminEditorReady);
        await run(page);
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
}

async function setBody(page, body, ranges = [[0, 0]], keyMap) {
    await page.evaluate(({body, ranges, keyMap}) => {
        window.adminEditor.setValue(body, true);
        const cm = document.querySelector('.CodeMirror').CodeMirror;
        if (keyMap) cm.setOption('keyMap', keyMap);
        cm.setSelections(ranges.map(([anchor, head]) => ({
            anchor: cm.posFromIndex(anchor), head: cm.posFromIndex(head),
        })));
        cm.clearHistory();
        cm.focus();
    }, {body, ranges, keyMap});
}

async function checkHistoryAndSave(page, initial, expected, keyMap) {
    if (keyMap) await page.keyboard.press(keyMap === 'macDefault' ? 'Meta+z' : 'Control+z');
    else await page.getByRole('button', {name: 'Undo', exact: true}).click();
    assert.equal(await value(page), initial, 'One undo restores all source, including the original selection');
    if (keyMap) await page.keyboard.press(keyMap === 'macDefault' ? 'Meta+Shift+z' : 'Control+y');
    else await page.getByRole('button', {name: 'Redo', exact: true}).click();
    assert.equal(await value(page), expected);
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide')));
    assert.equal(await page.evaluate(() => localStorage.getItem('register_content_draft:post:9')), expected);
    const saves = holdRequests(page, '**/admin-save?id=9');
    await saves.installed;
    if (keyMap) await page.keyboard.press('Control+s');
    else await page.getByRole('button', {name: 'Save', exact: true}).click();
    const pending = await saves.next();
    assert.equal((await formData(pending)).get('body').replace(/\r\n/g, '\n'), expected);
    await pending.fulfill({status: 503, json: {message: 'Retry later'}});
}

export async function runAdminNativeShortcutRegressions(browser, origin) {
    const initial = '<p>alpha beta tail</p>\n<p>Second paragraph</p>';
    for (const keyMap of ['macDefault', 'pcDefault']) {
        for (const [key, open, close] of [
            ['b', '<strong>', '</strong>'], ['k', '<a href="">', '</a>'], ['o', '<nobr>', '</nobr>'],
        ]) {
            for (const selected of [false, true]) {
                await withEditor(browser, origin, async page => {
                    const start = initial.indexOf('beta');
                    const end = start + (selected ? 'beta'.length : 0);
                    await setBody(page, initial, [[end, start]], keyMap);
                    await page.keyboard.press('Control+' + key);
                    const expected = initial.slice(0, start) + open + initial.slice(start, end) + close + initial.slice(end);
                    assert.equal(await value(page), expected, 'The shortcut must run before the platform binding can edit or move text');
                    await checkHistoryAndSave(page, initial, expected, keyMap);
                });
                console.log(`admin native shortcuts: ${keyMap} Ctrl+${key} with ${selected ? 'selection' : 'caret'} formats once and retains undo/redo, recovery and save`);
            }
        }
    }
}

export async function runSmartParagraphMarkupRegressions(browser, origin) {
    const image = '<img\n src="/example.png"\n alt="Visible picture">';
    const link = '<a\n href="/post"\n title="More > text">Link text</a>';
    const quoted = '<img\n src="/example.png"\n alt="first  \n\n  second" data-note="a > b">';
    const paragraph = '<p\n id="details">Section</p\n>';
    const quote = '<blockquote\n cite="/post">Section\ncontinued</blockquote\n>';
    const comment = '<!-- first  \n\n literal <img src="/hidden.png">  \nlast -->';
    for (const [name, block, formatted, images, links] of [
        ['multiline image', image, `<p>${image}</p>`, ['Visible picture'], []],
        ['multiline link', link, `<p>${link}</p>`, [], ['/post']],
        ['blank lines in quoted attributes', quoted, `<p>${quoted}</p>`, ['first  \n\n  second'], []],
        ['multiline paragraph delimiters', paragraph, paragraph, [], []],
        ['multiline quote delimiters', quote, quote.replace('Section\n', 'Section<br />\n'), [], []],
        ['multiline comment', comment, comment, [], []],
    ]) {
        await withEditor(browser, origin, async page => {
            const initial = 'Lead\nsecond line\n\n' + block + '\n\nTail.';
            const expected = '<p>Lead<br />\nsecond line</p>\n\n' + formatted + '\n\n<p>Tail.</p>';
            await setBody(page, initial);
            await page.getByRole('button', {name: 'Smart paragraphs', exact: true}).click();
            assert.equal(await value(page), expected, 'Tag and attribute source survives byte for byte while prose is formatted');
            assert.deepEqual(await page.evaluate(() => {
                const doc = new DOMParser().parseFromString(window.adminEditor.getValue(), 'text/html');
                return {
                    images: Array.from(doc.images, image => image.alt),
                    links: Array.from(doc.links, link => link.getAttribute('href')),
                };
            }), {images, links}, 'The formatted HTML still contains the authored images and links');
            await checkHistoryAndSave(page, initial, expected);
            await page.getByRole('button', {name: 'Smart paragraphs', exact: true}).click();
            assert.equal(await value(page), expected, 'Repeated formatting remains stable');
        });
        console.log(`admin smart paragraphs: ${name} retains markup, undo/redo, recovery and save`);
    }
}

export async function runParagraphAttributeRegressions(browser, origin) {
    const prefix = '<p><a href="#details">Go to section</a></p>\n';
    const globals = ' id="details" class=\'lead\' lang=en title="x > y" data-note="a &amp; b" aria-label="Section" dir="auto"';
    const cases = [
        ['global attributes', `<p${globals}>Section text</p>`, 'Heading 2', `<h2${globals}>Section text</h2>`],
        ['multiline attributes', '<P\n ID=details\n title=\'Literal "quote" > text\'>Section text</P>', 'Quote',
            '<blockquote\n ID=details\n title=\'Literal "quote" > text\'>Section text</blockquote>'],
        ['pre width', '<pre id="details" width="80">Section text</pre>', 'Heading 3', '<h3 id="details">Section text</h3>'],
        ['quote citation', '<blockquote cite="/source" id="details">Section text</blockquote>', 'Paragraph', '<p id="details">Section text</p>'],
        ['explicit alignment', '<p id="details" ALIGN=right data-align="left" title="align=left">Section text</p>', 'Control+e',
            '<p id="details" align="center" data-align="left" title="align=left">Section text</p>'],
        ['clear alignment', '<p id="details" align="center" class="lead">Section text</p>', 'Paragraph',
            '<p id="details" class="lead">Section text</p>'],
        ['heading alignment', '<p id="details" align="center">Section text</p>', 'Heading 4',
            '<h4 id="details" align="center">Section text</h4>'],
        ['pre alignment', '<h2 id="details" align="center" class="lead">Section text</h2>', 'Preformatted',
            '<pre id="details" class="lead">Section text</pre>'],
    ];
    for (const [name, block, action, formatted] of cases) {
        for (const selected of [false, true]) {
            await withEditor(browser, origin, async page => {
                const initial = prefix + block;
                const expected = prefix + formatted;
                const position = initial.indexOf('Section text') + 3;
                await setBody(page, initial, [selected ? [initial.length, prefix.length] : [position, position]]);
                if (action.startsWith('Control+')) await page.keyboard.press(action);
                else await page.getByRole('button', {name: action, exact: true}).click();
                assert.equal(await value(page), expected, 'Changing the block type retains compatible authored attributes');
                assert.equal(await page.evaluate(() => {
                    const doc = new DOMParser().parseFromString(window.adminEditor.getValue(), 'text/html');
                    const target = doc.querySelector('a').getAttribute('href').slice(1);
                    return doc.getElementById(target)?.textContent;
                }), 'Section text', 'The existing link still resolves to its section');
                if (!selected) {
                    assert.equal(await page.evaluate(() => {
                        const cm = document.querySelector('.CodeMirror').CodeMirror;
                        return cm.indexFromPos(cm.getCursor());
                    }), expected.indexOf('Section text') + 3, 'The caret follows its original text through the changed opening tag');
                }
                await checkHistoryAndSave(page, initial, expected);
            });
            console.log(`admin paragraph attributes: ${name} with ${selected ? 'selection' : 'caret'} retains anchors, undo/redo, recovery and save`);
        }
    }
    for (const disjoint of [false, true]) {
        await withEditor(browser, origin, async page => {
            const first = '<p id="first" class="lead">First section</p>';
            const second = '<p id="second" lang="en">Second section</p>';
            const separator = disjoint ? '\n<p>Unselected</p>\n' : '\n\n';
            const initial = first + separator + second;
            const expected = '<h2 id="first" class="lead">First section</h2>' + separator
                + '<h2 id="second" lang="en">Second section</h2>';
            const ranges = disjoint ? [[first.length, 0], [initial.length, first.length + separator.length]] : [[initial.length, 0]];
            await setBody(page, initial, ranges);
            await page.getByRole('button', {name: 'Heading 2', exact: true}).click();
            assert.equal(await value(page), expected, 'Each complete block retains its own attributes');
            await checkHistoryAndSave(page, initial, expected);
        });
        console.log(`admin paragraph attributes: ${disjoint ? 'disjoint' : 'adjacent'} selected blocks retain separate anchors and history`);
    }
}
