import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const value = page => page.evaluate(() => window.adminEditor.getValue());
const joiningText = 'Семья 👨‍👩‍👧‍👦. Работа 👩🏽‍💻. می\u200cروم. क्\u200dष. 20\u2060°C.';

async function withPage(browser, origin, params, run) {
    const page = await browser.newPage();
    const errors = [];
    page.setDefaultTimeout(10000);
    page.on('pageerror', error => errors.push(String(error)));
    try {
        await page.goto(origin + '/admin.html?id=9' + params);
        await page.waitForFunction(() => window.adminEditorReady);
        await run(page);
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
}

async function setBody(page, body, ranges = []) {
    await page.evaluate(({body, ranges}) => {
        window.adminEditor.setValue(body, true);
        const cm = document.querySelector('.CodeMirror').CodeMirror;
        if (ranges.length) cm.setSelections(ranges.map(([anchor, head]) => ({
            anchor: cm.posFromIndex(anchor), head: cm.posFromIndex(head),
        })));
        cm.clearHistory();
        cm.focus();
    }, {body, ranges});
}

async function checkHistoryAndSave(page, initial, result) {
    await page.getByRole('button', {name: 'Undo', exact: true}).click();
    assert.equal(await value(page), initial, 'One undo restores the original source');
    await page.getByRole('button', {name: 'Redo', exact: true}).click();
    assert.equal(await value(page), result);
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide')));
    assert.equal(await page.evaluate(() => localStorage.getItem('register_content_draft:post:9')), result);
    const saves = holdRequests(page, '**/admin-save?id=9');
    await saves.installed;
    await page.getByRole('button', {name: 'Save', exact: true}).click();
    const pending = await saves.next();
    assert.equal((await formData(pending)).get('body').replace(/\r\n/g, '\n'), result);
    await pending.fulfill({status: 503, json: {message: 'Retry later'}});
}

export async function runImageInsertionTextRegressions(browser, origin) {
    const cases = [
        ['quotes', ['Camera "Front" & rear'], ['Camera "Front" & rear']],
        ['entities', ['Camera &quot;Front&quot; &amp; rear'], ['Camera "Front" & rear']],
        ['formatted text', ['<em title="detail">Camera</em> &amp; <strong>rear</strong>'], ['Camera & rear']],
        ['block and line boundaries', ['<p>First<br>line</p><p>Second paragraph</p>'], ['First line Second paragraph']],
        ['literal markup', ['&lt;em&gt;Camera&lt;/em&gt;'], ['<em>Camera</em>']],
        ['code text', ['<code>a &lt; b</code>'], ['a < b']],
        ['Unicode joining characters', [joiningText], [joiningText]],
        ['encoded joining characters', ['👩&#8205;💻 می&zwnj;روم 20&#8288;°C'], ['👩‍💻 می\u200cروم 20\u2060°C']],
        ['literal entity names', ['&amp;lt;widget&amp;gt; &amp;amp;'], ['&lt;widget&gt; &amp;']],
        ['multiple reversed selections', ['First "camera"', 'Second &amp; camera'], ['First "camera"', 'Second & camera']],
        ['plain text', ['Plain description'], ['Plain description']],
        ['empty selection', [''], ['']],
    ];
    for (const [name, selections, descriptions] of cases) {
        await withPage(browser, origin, '&codemirror=1&toolbar=1' + (selections[0] ? '&alt=1' : ''), async page => {
            const ai = holdRequests(page, '**/admin-ai-alt');
            await ai.installed;
            let initial = '';
            const ranges = [];
            for (const selection of selections) {
                initial += '<p>Before</p>\n';
                const start = initial.length;
                initial += selection;
                ranges.push([initial.length, start]);
                initial += '\n';
            }
            initial += '<p>After</p>';
            await setBody(page, initial, ranges);
            await page.evaluate(() => document.dispatchEvent(new CustomEvent('return_image.register', {
                detail: {file_path: '/camera.png', width: 100, height: 80},
            })));
            const result = await value(page);
            const parsed = await page.evaluate(() => {
                const body = new DOMParser().parseFromString(window.adminEditor.getValue(), 'text/html').body;
                return {
                    images: Array.from(body.querySelectorAll('img'), image => ({
                        alt: image.alt,
                        attributes: image.getAttributeNames().sort(),
                    })),
                    text: body.textContent.replace(/\s+/g, ' ').trim(),
                };
            });
            assert.deepEqual(parsed.images, descriptions.map(alt => ({
                alt, attributes: ['alt', 'height', 'loading', 'src', 'width'],
            })));
            assert.equal(parsed.text, selections.map(() => 'Before').concat('After').join(' '));
            assert.equal(ai.count, 0, 'Selected descriptions must not trigger automatic replacement');
            await checkHistoryAndSave(page, initial, result);
        });
        console.log(`admin image insertion: ${name} retains safe descriptions, undo/redo, recovery and save`);
    }
}

export async function runSmartParagraphRegressions(browser, origin) {
    const preText = 'first  \n\n  middle\ncontinued  \n\nlast  ';
    const cases = [
        ['toolbar-created pre', `<pre>${preText}</pre>`],
        ['quoted attributes', '<pre data-note="literal > marker">first\n\nmiddle\n\nlast</pre>'],
        ['uppercase pre', '<PRE>first\n\nmiddle\n\nlast</PRE>'],
        ['script', '<script>const first = "<pre>";\n\nconst middle = 2;\n\nconst last = 3;</script>'],
        ['style', '<style>.first { color: red; }\n\n.middle { margin: 0; }\n\n.last { padding: 0; }</style>'],
        ['nested lists', '<ol><li>first\n\n  middle  \n\nlast<ul><li>nested\n\ntext</li></ul></li></ol>'],
        ['textarea', '<textarea>first\n\n<em>literal markup</em>  \n\nlast</textarea>'],
        ['multiple protected blocks', `<pre>${preText}</pre>\n\n<pre>${preText}</pre>`],
        ['existing wrappers', `<div class="sample"><pre>${preText}</pre></div>`],
        ['unclosed pre', `<pre>${preText}`],
        ['cut marker', '<cut />'],
    ];
    for (const [name, block] of cases) {
        await withPage(browser, origin, '&codemirror=1&toolbar=1', async page => {
            if (name === 'toolbar-created pre') {
                await setBody(page, preText, [[0, preText.length]]);
                await page.getByRole('button', {name: 'Preformatted', exact: true}).click();
                assert.equal(await value(page), block);
            }
            const initial = 'Lead\nsecond line\n\n' + block + (name === 'unclosed pre' ? '' : '\n\nTail.');
            const expected = '<p>Lead<br />\nsecond line</p>\n\n' + block
                + (name === 'unclosed pre' ? '' : '\n\n<p>Tail.</p>');
            await setBody(page, initial);
            await page.getByRole('button', {name: 'Smart paragraphs', exact: true}).click();
            assert.equal(await value(page), expected, 'Protected source survives byte for byte while prose is formatted');
            await checkHistoryAndSave(page, initial, expected);
            await page.getByRole('button', {name: 'Smart paragraphs', exact: true}).click();
            assert.equal(await value(page), expected, 'Repeated formatting remains stable');
        });
        console.log(`admin smart paragraphs: ${name} preserves source, undo/redo, recovery and save`);
    }
}

export async function runSocialPreviewTextRegressions(browser, origin) {
    const cases = [
        ['adjacent paragraphs', '<p>First paragraph.</p><p>Second paragraph.</p>', 'First paragraph. Second paragraph.'],
        ['line break', '<p>First line.<br>Second line.</p>', 'First line. Second line.'],
        ['nested blocks', '<blockquote><p>Quote.</p></blockquote><ul><li>First.</li><li>Second.</li></ul>', 'Quote. First. Second.'],
        ['table cells', '<table><tr><td>First</td><td>Second</td></tr></table>', 'First Second'],
        ['code and non-editorial content', '<p>Intro.</p><pre>hidden code</pre><code>more code</code>'
            + '<script>const hidden = 1;</script><style>.card { color: red; }</style>'
            + '<template>template text</template><noscript>fallback text</noscript><svg><text>drawing</text></svg>'
            + '<math><mi>x</mi></math><p>Continuation.</p>', 'Intro. Continuation.'],
        ['entities and invisible separators', '<p>Camera &quot;Front&quot;&nbsp;&amp;&#8203;rear</p>', 'Camera "Front" & rear'],
        ['Unicode joining characters', '<p>' + joiningText + '</p>', joiningText],
        ['encoded joining characters', '<p>👩&#8205;💻 می&zwnj;روم 20&#8288;°C</p>', '👩‍💻 می\u200cروم 20\u2060°C'],
        ['literal markup', '<p>Use &lt;widget&gt; and &lt;/widget&gt; as literal text.</p>', 'Use <widget> and </widget> as literal text.'],
        ['literal entity names', '<p>Write &amp;lt;widget&amp;gt; and &amp;amp; in the source.</p>', 'Write &lt;widget&gt; and &amp; in the source.'],
        ['cut marker', '<p>Lead.</p><cut /><p>Rest of article.</p>', 'Lead.'],
        ['empty lead before cut', '<pre>only code</pre><cut><p>Visible body.</p>', 'Visible body.'],
        ['invisible lead before cut', '<p>&nbsp;&#8203;&#xfeff;</p><cut><p>Visible body.</p>', 'Visible body.'],
        ['title-only lead before cut', '<h1>Server title</h1><cut><p>Visible body.</p>', 'Visible body.'],
        ['title and invisible lead before cut', '<h1>&nbsp;Server&#8203;title</h1><p>&nbsp;</p><cut><p>Visible body.</p>', 'Visible body.'],
        ['quoted greater-than sign', '<p title="left > right">First.</p><p>Second.</p>', 'First. Second.'],
        ['nested omitted content', '<p>Intro.</p><template>outer<template>inner</template>hidden tail</template><p>Ending.</p>', 'Intro. Ending.'],
        ['comment cut', '<p>Intro.</p><!-- <cut /> --><p>Ending.</p>', 'Intro. Ending.'],
        ['attribute cut', '<p>Intro.</p><p title="<cut>">Ending.</p>', 'Intro. Ending.'],
        ['script cut', '<p>Intro.</p><script>const marker = "<cut>";</script><p>Ending.</p>', 'Intro. Ending.'],
        ['style cut', '<p>Intro.</p><style>p::before {content:"<cut>"}</style><p>Ending.</p>', 'Intro. Ending.'],
        ['code cut', '<p>Intro.</p><pre>sample <cut /> code</pre><p>Ending.</p>', 'Intro. Ending.'],
        ['template cut', '<p>Intro.</p><template><cut /></template><p>Ending.</p>', 'Intro. Ending.'],
        ['real cut after a comment', '<p>Intro.</p><!-- <cut /> --><p>Ending.</p><CUT /><p>Rest.</p>', 'Intro. Ending.'],
        ['unclosed cut element', '<p>Intro.</p><cut><p>Rest.</p>', 'Intro.'],
        ['HTML5 entities', '<p>👩&zwj;💻 می&zwnj;روم 20&NoBreak;°C &NotEqualTilde; &amp;lt;tag&amp;gt;</p>',
            '👩‍💻 می\u200cروم 20\u2060°C ≂̸ &lt;tag&gt;'],
        ['duplicated title', '<h1>Server title</h1><p>Body.</p>', 'Body.'],
        ['title with invisible separators', '<h1>Server&nbsp;&#8203;&#xfeff;title</h1><p>Body.</p>', 'Body.'],
        ['astral and multi-character entities', '<p>&Afr; &fjlig; &NotEqualTilde; &LT;tag&GT;</p>', '𝔄 fj ≂̸ <tag>'],
        ['whole sentence limit', '<p>First sentence. ' + 'long '.repeat(40) + '</p>', 'First sentence.'],
        ['word limit', '<p>' + 'word '.repeat(40) + '</p>', Array(31).fill('word').join(' ') + '…'],
        ['Unicode limit', '<p>' + '🙂'.repeat(170) + '</p>', '🙂'.repeat(159) + '…'],
        ['empty editorial text', '<pre>only code</pre>', 'Empty preview'],
        ['invisible editorial text', '<p>&nbsp;&#8203;&#xfeff;</p>', 'Empty preview'],
    ];
    for (const codemirror of [false, true]) {
        await withPage(browser, origin, '&social=1' + (codemirror ? '&codemirror=1&toolbar=1' : ''), async page => {
            for (const [name, html, expected] of cases) {
                if (codemirror) await page.evaluate(html => window.adminEditor.setValue(html), html);
                else await page.locator('[name="body"]').fill(html);
                assert.equal(await page.locator('[data-social-preview-description]').textContent(), expected, name);
                assert.equal(await page.locator('[name="meta_description"]').inputValue(), '', 'Preview leaves metadata generation to saving');
                console.log(`social preview text: ${codemirror ? 'CodeMirror' : 'textarea'} ${name} matches the local description`);
            }
            await page.locator('[name="meta_description"]').fill('Explicit description');
            await page.locator('[name="title"]').fill('Updated title');
            assert.equal(await page.locator('[data-social-preview-description]').textContent(), 'Explicit description');
            await page.locator('[name="meta_description"]').fill('');
            assert.equal(await page.locator('[data-social-preview-description]').textContent(), 'Empty preview');
        });
    }
}
