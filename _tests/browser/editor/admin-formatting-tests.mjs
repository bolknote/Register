import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const value = page => page.evaluate(() => window.adminEditor.getValue());
const selectionState = page => page.evaluate(() => {
    const cm = document.querySelector('.CodeMirror').CodeMirror;
    return {
        ranges: cm.listSelections().map(({anchor, head}) => [cm.indexFromPos(anchor), cm.indexFromPos(head)]),
        primary: cm.indexFromPos(cm.getCursor()),
    };
});

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

async function checkHistoryAndSave(page, initial, expected, keyMap, selections) {
    if (keyMap) await page.keyboard.press(keyMap === 'macDefault' ? 'Meta+z' : 'Control+z');
    else await page.getByRole('button', {name: 'Undo', exact: true}).click();
    assert.equal(await value(page), initial, 'One undo restores all source, including the original selection');
    if (selections) assert.deepEqual(await selectionState(page), selections.before, 'Undo restores every original cursor and the primary one');
    if (keyMap) await page.keyboard.press(keyMap === 'macDefault' ? 'Meta+Shift+z' : 'Control+y');
    else await page.getByRole('button', {name: 'Redo', exact: true}).click();
    assert.equal(await value(page), expected);
    if (selections) assert.deepEqual(await selectionState(page), selections.after, 'Redo restores every resulting cursor and the primary one');
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

// A marker denotes a caret in the expected source, independently of the
// implementation's line and offset mapping.
function markedText(marked) {
    let text = '';
    const carets = [];
    const parts = marked.split('|');
    for (const [index, part] of parts.entries()) {
        if (index) carets.push(text.length);
        text += part;
    }
    return {text, carets};
}

async function addCaretWithMouse(page, index) {
    const {coords, modifier} = await page.evaluate(index => {
        const cm = document.querySelector('.CodeMirror').CodeMirror;
        return {coords: cm.charCoords(cm.posFromIndex(index), 'page'),
            modifier: /Mac|iPhone|iPad|iPod/.test(navigator.platform) ? 'Meta' : 'Control'};
    }, index);
    await page.keyboard.down(modifier);
    try { await page.mouse.click(coords.left + 0.2, (coords.top + coords.bottom) / 2); }
    finally { await page.keyboard.up(modifier); }
}

export async function runCommentParagraphRegressions(browser, origin) {
    const note = '<!-- first  \n\n <pre>literal markup</pre>\n last  -->';
    for (const [name, block, expectedBlock] of [
        ['inline comment', 'First <!-- note --> line\nSecond line', '<p>First <!-- note --> line<br />\nSecond line</p>'],
        ['multiline inline comment', `First ${note} line\nSecond line`, `<p>First ${note} line<br />\nSecond line</p>`],
        ['several comments', 'First <!-- one --> line\nSecond <!-- two --> line', '<p>First <!-- one --> line<br />\nSecond <!-- two --> line</p>'],
        ['leading comment', '<!-- note -->\nFirst line\nSecond line', '<p><!-- note -->\nFirst line<br />\nSecond line</p>'],
        ['trailing inline comment', 'First line\nSecond line<!-- note -->', '<p>First line<br />\nSecond line<!-- note --></p>'],
        ['comment-only chunk', `${note}\n<!-- another -->`, `${note}\n<!-- another -->`],
        ['comments around a paragraph', `<!-- before -->\n<p>First\nSecond</p><!-- after -->`,
            `<!-- before -->\n<p>First<br />\nSecond</p><!-- after -->`],
        ['comment after a closing tag', '<p>First</p><!-- note -->\n<p>Second</p>', '<p>First</p><!-- note -->\n<p>Second</p>'],
        ['comment after an existing break', '<p>First<br /><!-- note -->\nSecond</p>', '<p>First<!-- note --><br />\nSecond</p>'],
        ['comment in protected code', `<pre>First\n${note}\n\n  Second</pre>`, `<pre>First\n${note}\n\n  Second</pre>`],
    ]) {
        await withEditor(browser, origin, async page => {
            const initial = 'Lead\nsecond line\n\n' + block + '\n\nTail.';
            const expected = '<p>Lead<br />\nsecond line</p>\n\n' + expectedBlock + '\n\n<p>Tail.</p>';
            await setBody(page, initial);
            await page.getByRole('button', {name: 'Smart paragraphs', exact: true}).click();
            assert.equal(await value(page), expected, 'Comments retain their exact contents without shielding neighbouring prose');
            await checkHistoryAndSave(page, initial, expected);
            await page.getByRole('button', {name: 'Smart paragraphs', exact: true}).click();
            assert.equal(await value(page), expected, 'Repeated formatting adds no paragraphs or line breaks');
        });
        console.log(`admin comment paragraphs: ${name} preserves markup, undo/redo, recovery and save`);
    }
}

export async function runDuplicateLineRegressions(browser, origin) {
    for (const [name, marked, duplicate, mouse = false] of [
        ['native multiple cursors', 'fi|rst line\nsec|ond line\nthird line', 'first line\nfi|rst line\nsecond line\nsec|ond line\nthird line', true],
        ['indentation and trailing spaces', '\t  fi|rst  \n  sec|ond\nlast', '\t  first  \n\t  fi|rst  \n  second\n  sec|ond\nlast'],
        ['same line', 'a|lpha b|eta\nend', 'alpha beta\na|lpha b|eta\nend'],
        ['empty and final lines', 'fi|rst\n|\nla|st', 'first\nfi|rst\n\n|\nlast\nla|st'],
        ['same and separate lines', 'a|lpha b|eta\nbr|avo', 'alpha beta\na|lpha b|eta\nbravo\nbr|avo'],
        ['single line', 'on|ly', 'only\non|ly'],
        ['Unicode columns', '🙂 П|ервая\nВто|рая', '🙂 Первая\n🙂 П|ервая\nВторая\nВто|рая'],
    ]) {
        await withEditor(browser, origin, async page => {
            const initial = markedText(marked);
            const expected = markedText(duplicate);
            await setBody(page, initial.text, (mouse ? initial.carets.slice(0, 1) : initial.carets).map(index => [index, index]), 'pcDefault');
            if (mouse) await addCaretWithMouse(page, initial.carets[1]);
            const before = await selectionState(page);
            assert.deepEqual(before.ranges, initial.carets.map(index => [index, index]));
            await page.keyboard.press('Control+d');
            assert.equal(await value(page), expected.text, 'Each distinct line is copied exactly once, with its own contents');
            const after = {ranges: expected.carets.map(index => [index, index]), primary: expected.carets[mouse ? 1 : 0]};
            assert.deepEqual(await selectionState(page), after, 'All cursors keep their columns in the copied lines');
            await checkHistoryAndSave(page, initial.text, expected.text, 'pcDefault', {before, after});
        });
        console.log(`admin duplicate line: ${name} retains text, cursors, undo/redo, recovery and save`);
    }
}

export async function runParagraphCaretRegressions(browser, origin) {
    for (const [name, marked, action, formatted, mouse = false] of [
        ['native multiple cursors', '<p id="first">al|pha</p>\n<p id="second">br|avo</p>\n<p>tail</p>', 'Heading 2',
            '<h2 id="first">al|pha</h2>\n<h2 id="second">br|avo</h2>\n<p>tail</p>', true],
        ['same block', '<p id="first">al|pha b|eta</p>', 'Heading 3', '<h3 id="first">al|pha b|eta</h3>'],
        ['same line', '<p>al|pha</p><p>br|avo</p>', 'Heading 2', '<h2>al|pha</h2><h2>br|avo</h2>'],
        ['separate plain paragraphs', 'al|pha\ncontinued\n\nbr|avo', 'Quote', '<blockquote>al|pha\ncontinued</blockquote>\n\n<blockquote>br|avo</blockquote>'],
        ['same plain paragraph', 'al|pha\nbr|avo', 'Preformatted', '<pre>al|pha\nbr|avo</pre>'],
        ['empty lines', '|\n\n|', 'Heading 2', '<h2>|</h2>\n\n<h2>|</h2>'],
        ['untouched separator cursor', '<p>al|pha</p>\n|\n<p>bravo</p>', 'Heading 2', '<h2>al|pha</h2>\n|\n<p>bravo</p>'],
        ['different block types', '<h2 id="a">al|pha</h2>\n<pre id="b">br|avo</pre>', 'Paragraph', '<p id="a">al|pha</p>\n<p id="b">br|avo</p>'],
        ['keyboard alignment', '<p id="a">al|pha</p>\n<p id="b" align="right">br|avo</p>', 'Control+e',
            '<p id="a" align="center">al|pha</p>\n<p id="b" align="center">br|avo</p>'],
        ['nested paragraphs', '<blockquote id="quote"><p>al|pha</p><p>br|avo</p></blockquote>', 'Heading 4',
            '<blockquote id="quote"><h4>al|pha</h4><h4>br|avo</h4></blockquote>'],
        ['nested targets', '<blockquote id="outer">Ou|ter<p id="inner">In|ner</p></blockquote>', 'Quote',
            '<blockquote id="outer">Ou|ter<blockquote id="inner">In|ner</blockquote></blockquote>'],
        ['cursors inside tags', '<p id="a|">alpha</p>\n<p>bravo</|p>', 'Heading 2', '<h2 id="a">|alpha</h2>\n<h2>bravo|</h2>'],
        ['empty tagged blocks', '<p id="a">|</p>\n<pre>|</pre>', 'Heading 2', '<h2 id="a">|</h2>\n<h2>|</h2>'],
    ]) {
        await withEditor(browser, origin, async page => {
            const initial = markedText(marked);
            const expected = markedText(formatted);
            await setBody(page, initial.text, (mouse ? initial.carets.slice(0, 1) : initial.carets).map(index => [index, index]));
            if (mouse) await addCaretWithMouse(page, initial.carets[1]);
            const before = await selectionState(page);
            assert.deepEqual(before.ranges, initial.carets.map(index => [index, index]));
            if (action.startsWith('Control+')) await page.keyboard.press(action);
            else await page.getByRole('button', {name: action, exact: true}).click();
            assert.equal(await value(page), expected.text, 'Each distinct paragraph is formatted once');
            const after = {ranges: expected.carets.map(index => [index, index]), primary: expected.carets[mouse ? 1 : 0]};
            assert.deepEqual(await selectionState(page), after, 'All cursors follow their original content, including the primary cursor');
            await checkHistoryAndSave(page, initial.text, expected.text, undefined, {before, after});
        });
        console.log(`admin paragraph cursors: ${name} retains blocks, cursors, undo/redo, recovery and save`);
    }
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
