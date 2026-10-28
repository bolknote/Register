import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {formData} from './save-tests.mjs';
import {pixels} from './media-navigation-tests.mjs';

const pre = '<pre><code>first command\nsecond command</code></pre>';
const leading = pre + '<p><tt>command</tt> explains the operation.</p>';
const middle = pre + '<p>Plain text <tt>command</tt> explains the operation.</p>';
const normalizeTransport = html => html.replaceAll('\r\n', '\n');

async function settle(page) {
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

async function withInlineCode(browser, origin, source, {mobile = false, colorScheme = 'dark', inlineStyles = false} = {}, run) {
    const page = await browser.newPage({viewport: mobile ? {width: 390, height: 844} : {width: 1100, height: 900},
        hasTouch: mobile, colorScheme, reducedMotion: 'reduce'});
    page.setDefaultTimeout(10000);
    const errors = [];
    const saved = [];
    page.on('pageerror', error => errors.push(String(error)));
    try {
        await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        if (inlineStyles) await page.route('**/recovery.html', async route => {
            const response = await route.fetch();
            const headers = response.headers();
            headers['content-security-policy'] += "; style-src-attr 'unsafe-inline'";
            await route.fulfill({response, headers});
        });
        await page.route('**/recovery-fixture.css', async route => {
            const response = await route.fetch();
            await route.fulfill({response, body: 'body { margin: 24px; } #content { width: min(960px, 100%); }'
                + '\n@view-transition { navigation: none; }\n.has-leading-boundary-caret::before { animation: none !important; }'});
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
        await page.evaluate(html => {
            const card = document.querySelector('.post-card[data-post-id="9"]');
            card.querySelector('[data-post-inplace-body]').innerHTML = html;
            card.querySelector('[name="body"]').value = html;
        }, source);
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
        // TT first appears after the fixture loads. Wait for its webfont before
        // using line-fragment coordinates, especially after a WebKit font swap.
        await settle(page);
        await page.evaluate(async () => { await document.fonts.ready; });
        await run({page, body, saved});
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
}

async function positionBefore(page, body, action) {
    const code = body.locator('tt').first();
    await code.click(action === 'left' ? {} : {position: {x: 1, y: 10}});
    if (action === 'left') {
        assert.equal(await code.evaluate(code => code.contains(getSelection().anchorNode)), true,
            'The real text click starts inside the code');
        for (let count = 0; count < 16; count++) {
            const outside = await code.evaluate(code => {
                const selection = getSelection();
                const node = selection.anchorNode;
                if (code.contains(node)) return false;
                return node.nodeType === Node.TEXT_NODE
                    || (node instanceof Element && node.childNodes[selection.anchorOffset]?.hasAttribute?.('data-post-inline-code-exit'));
            });
            if (outside) break;
            await page.keyboard.press('ArrowLeft');
        }
    }
    await settle(page);
}

async function assertCaretAtCodeBoundary(page, body, label, codeIndex, after, selector = 'tt') {
    await settle(page);
    const caret = await body.evaluate((body, {codeIndex, after, selector}) => {
        const code = body.querySelectorAll(selector)[codeIndex];
        const selection = getSelection();
        const range = selection?.rangeCount === 1 ? selection.getRangeAt(0) : null;
        const edge = document.createRange();
        if (after) edge.setStartAfter(code);
        else edge.setStartBefore(code);
        edge.collapse(true);
        const marker = body.querySelector('[data-post-inline-code-exit].has-leading-boundary-caret');
        const style = marker ? getComputedStyle(marker, '::before') : null;
        const rect = marker?.getBoundingClientRect();
        const native = range?.getBoundingClientRect();
        const element = range?.startContainer instanceof Element ? range.startContainer : range?.startContainer.parentElement;
        return {
            correct: Boolean(range?.collapsed && (code.closest('p') || code.parentElement).contains(range.startContainer)
                && !element?.closest('tt') && !code.contains(range.startContainer) && (after
                    ? range.compareBoundaryPoints(Range.START_TO_START, edge) >= 0
                    : range.compareBoundaryPoints(Range.START_TO_START, edge) <= 0)),
            focused: document.activeElement === body,
            synthetic: Boolean(marker),
            x: marker ? rect.x + parseFloat(style.left) : native?.x,
            y: marker ? rect.y + parseFloat(style.top) : native?.y,
            width: marker ? parseFloat(style.width) : 1,
            height: marker ? parseFloat(style.height) : native?.height,
            color: marker ? style.backgroundColor : getComputedStyle(body).caretColor,
            codeLeft: code.getBoundingClientRect().left,
            codeRight: code.getClientRects()[code.getClientRects().length - 1].right,
            anchor: range?.startContainer.nodeName,
            offset: range?.startOffset,
            container: element?.outerHTML.slice(0, 800),
            viewportHeight: innerHeight,
        };
    }, {codeIndex, after, selector});
    assert.ok(caret.correct && caret.focused, `${label}: visible insertion belongs ${after ? 'after' : 'before'} the code in the same paragraph: ${JSON.stringify(caret)}`);
    assert.ok(caret.height > 0 && caret.y >= 0 && caret.y + caret.height < caret.viewportHeight,
        `${label}: the caret is visible on screen: ${JSON.stringify(caret)}`);
    assert.ok(after ? caret.x >= caret.codeRight - 1 : caret.x <= caret.codeLeft + 1,
        `${label}: the caret is outside the code's padded background`);
    const rendered = pixels(await page.screenshot({caret: 'initial', clip: {
        x: Math.max(0, Math.floor(caret.x) - 1), y: Math.floor(caret.y),
        width: Math.ceil(caret.width + 3), height: Math.ceil(caret.height),
    }}));
    const color = caret.color.match(/[\d.]+/gu).slice(0, 3).map(Number);
    let matches = 0;
    for (let index = 0; index < rendered.data.length; index += rendered.channels) {
        if (color.every((value, channel) => Math.abs(rendered.data[index + channel] - value) <= 2)) ++matches;
    }
    assert.ok(matches >= caret.height / 2, `${label}: screenshot contains the painted caret (${matches} pixels)`);
}

const assertCaretBefore = (page, body, label, codeIndex = 0) => assertCaretAtCodeBoundary(page, body, label, codeIndex, false);
const assertCaretAfter = (page, body, label, codeIndex = 0, selector = 'tt') => assertCaretAtCodeBoundary(page, body, label, codeIndex, true, selector);

async function save(page) {
    await page.getByRole('button', {name: 'Save', exact: true}).click();
    await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
}

export async function runCopiedInlineCodeBoundaryRegressions(browser, origin) {
    for (const colorScheme of ['dark', 'light']) {
        for (const mobile of [false, true]) {
            for (const action of ['click', 'right']) {
                await withInlineCode(browser, origin, pre + '<p><tt>command</tt></p><p>Target:</p>',
                    {inlineStyles: true, colorScheme, mobile}, async ({page, body, saved}) => {
                        const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
                        await body.locator('tt').evaluate(code => {
                            code.closest('[contenteditable="true"]').focus();
                            const range = document.createRange();
                            range.selectNode(code);
                            getSelection().removeAllRanges(); getSelection().addRange(range);
                        });
                        await page.keyboard.press(`${modifier}+c`);
                        await body.locator('p').last().evaluate(paragraph => {
                            paragraph.closest('[contenteditable="true"]').focus();
                            const range = document.createRange();
                            range.selectNodeContents(paragraph); range.collapse(false);
                            getSelection().removeAllRanges(); getSelection().addRange(range);
                        });
                        await page.keyboard.press(`${modifier}+v`);
                        await page.waitForFunction(() => document.querySelector('.post-card.is-editing p:last-child')?.textContent === 'Target:command');
                        const selector = 'p:last-child :is(tt, span[style])';
                        const copied = body.locator(selector).first();
                        const initial = await copied.evaluate(code => code.outerHTML);
                        const before = await body.evaluate(body => window.editorTest.editableBodyHtml(window.editorTest.editorStates.get(body.closest('.post-card'))));
                        if (action === 'click') {
                            const rect = await copied.boundingBox();
                            await page.mouse.click(rect.x + rect.width + 12, rect.y + rect.height / 2);
                        } else {
                            await page.keyboard.press('ArrowRight');
                        }
                        await assertCaretAfter(page, body, 'actual native copied code', 0, selector);
                        const suffix = ' plain suffix';
                        await page.keyboard.type(suffix);
                        assert.equal(await copied.textContent(), 'command', 'Actual native copied code does not absorb the ordinary suffix');
                        assert.equal(await copied.evaluate(code => code.outerHTML), initial, 'The pasted tag and all its attributes remain intact');
                        assert.equal((await body.locator('p').last().textContent()).replaceAll('\u00a0', ' '), 'Target:command' + suffix);
                        await page.keyboard.press(`${modifier}+z`);
                        await assertCaretAfter(page, body, 'native copied code after undo', 0, selector);
                        await page.keyboard.press(`${modifier}+Shift+z`);
                        if (process.env.EDITOR_INLINE_CODE_CAPTURE_DIR && colorScheme === 'dark' && !mobile && action === 'click') {
                            await body.screenshot({path: `${process.env.EDITOR_INLINE_CODE_CAPTURE_DIR}/${browser.browserType().name()}-copied-code-right.png`, caret: 'initial'});
                        }
                        await save(page);
                        const expected = before.replace(/<\/p>$/u, suffix + '</p>');
                        assert.deepEqual(saved.map(html => html.replaceAll('&nbsp;', ' ')), [expected]);
                        await page.getByRole('button', {name: 'Edit', exact: true}).click();
                        await page.keyboard.type(' Changed title');
                        await save(page);
                        assert.deepEqual(saved.map(html => html.replaceAll('&nbsp;', ' ')), [expected, expected], 'Native copied HTML and its following text survive reopening and saving');
                    });
            }
        }
    }
    for (const style of ['background-color: yellow; font-family: serif', 'font-family: monospace',
        'background-color: transparent; font-family: monospace', 'background-color: rgba(255, 0, 0, 0); font-family: monospace']) {
        await withInlineCode(browser, origin, '<p><span style="' + style + '">ordinary</span></p>',
            {inlineStyles: true}, async ({page, body}) => {
                const span = body.locator('span[style]');
                const initial = await span.evaluate(span => span.outerHTML);
                const size = await span.boundingBox();
                await span.click({position: {x: size.width - 1, y: size.height / 2}});
                await page.keyboard.press('ArrowRight');
                assert.equal(await body.locator('[data-post-inline-code-exit]').count(), 0, 'Ordinary styled text keeps native navigation');
                assert.equal(await span.evaluate(span => span.outerHTML), initial);
            });
    }
    console.log(`${browser.browserType().name()}: native copied code exits keep a painted caret, spaces, original formatting, undo/redo and repeated saves`);
}

export async function runTrailingInlineCodeBoundaryRegressions(browser, origin) {
    for (const codeHtml of ['<tt>command</tt>', '<tt><span>command</span></tt>']) {
        for (const colorScheme of ['dark', 'light']) {
            for (const mobile of [false, true]) {
                for (const action of ['padding', 'blank-space']) {
                    const source = pre + '<p>Explanation:' + codeHtml + '</p>';
                    const label = `${codeHtml.includes('span') ? 'nested' : 'plain'} trailing code ${colorScheme} ${mobile ? 'mobile' : 'desktop'} ${action}`;
                    await withInlineCode(browser, origin, source, {colorScheme, mobile}, async ({page, body, saved}) => {
                        const code = body.locator('tt');
                        const initial = await code.evaluate(code => code.outerHTML);
                        const rect = await code.evaluate(code => {
                            const rects = code.getClientRects();
                            const last = rects[rects.length - 1];
                            return {x: last.right, y: last.top + last.height / 2};
                        });
                        await page.mouse.click(rect.x + (action === 'padding' ? -1 : 12), rect.y);
                        await assertCaretAfter(page, body, label);
                        const suffix = codeHtml.includes('span') ? ' plain suffix' : 'Suffix';
                        await page.keyboard.type(suffix);
                        const expected = pre + '<p>Explanation:' + initial + suffix + '</p>';
                        assert.equal(await code.evaluate(code => code.outerHTML), initial, 'Typing after code does not extend or restyle it');
                        assert.equal((await body.locator('p').last().textContent()).replaceAll('\u00a0', ' '), 'Explanation:command' + suffix);
                        const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
                        await page.keyboard.press(`${modifier}+z`);
                        await assertCaretAfter(page, body, label + ' undo');
                        await page.keyboard.press(`${modifier}+Shift+z`);
                        await page.waitForFunction(expected => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                            .list().some(copy => copy.snapshot.body.replaceAll('&nbsp;', ' ') === expected), expected);
                        await save(page);
                        assert.deepEqual(saved.map(html => html.replaceAll('&nbsp;', ' ')), [expected], 'Saved source retains all text and excludes the runtime caret marker');
                        await page.getByRole('button', {name: 'Edit', exact: true}).click();
                        await page.keyboard.type(' Changed title');
                        await save(page);
                        assert.deepEqual(saved.map(html => html.replaceAll('&nbsp;', ' ')), [expected, expected], 'Reopening and saving do not lose the suffix');
                    });
                }
            }
        }
    }
    for (const colorScheme of ['dark', 'light']) {
        const text = 'command argument '.repeat(5) + 'last';
        const source = pre + '<p>Explanation:<tt>' + text + '</tt></p>';
        await withInlineCode(browser, origin, source, {mobile: true, colorScheme}, async ({page, body, saved}) => {
            const code = body.locator('tt');
            const rects = await code.evaluate(code => Array.from(code.getClientRects(), rect => ({
                right: rect.right, y: rect.top + rect.height / 2,
            })));
            assert.ok(rects.length > 1, 'Long inline code wraps onto multiple visual lines');
            const glyph = await code.evaluate(code => {
                const range = document.createRange();
                range.setStart(code.firstChild, 0);
                range.setEnd(code.firstChild, 3);
                const rect = range.getBoundingClientRect();
                return {x: rect.left + rect.width / 2, y: rect.top + rect.height / 2};
            });
            await page.mouse.click(glyph.x, glyph.y);
            assert.equal(await code.evaluate(code => code.contains(getSelection().anchorNode)), true,
                'A glyph in an earlier visual line is still natively editable inside the code');
            const last = rects.at(-1);
            await page.mouse.click(last.right - 1, last.y);
            await assertCaretAfter(page, body, 'last wrapped code fragment ' + colorScheme);
            await page.keyboard.type('Suffix');
            assert.equal(await code.textContent(), text);
            await save(page);
            assert.deepEqual(saved, [source.replace('</tt>', '</tt>Suffix')]);
        });
    }
    await withInlineCode(browser, origin, pre + '<p><tt>first</tt><tt>command</tt></p>', {}, async ({page, body, saved}) => {
        const code = body.locator('tt').first();
        const size = await code.boundingBox();
        await code.click({position: {x: size.width - 1, y: size.height / 2}});
        await assertCaretAfter(page, body, 'right padding between adjacent code chips');
        await page.keyboard.type('Suffix');
        assert.deepEqual(await body.locator('tt').allTextContents(), ['first', 'command']);
        await save(page);
        assert.deepEqual(saved, [pre + '<p><tt>first</tt>Suffix<tt>command</tt></p>']);
    });
    await withInlineCode(browser, origin, pre + '<p><strong><tt>command</tt></strong></p>', {}, async ({page, body, saved}) => {
        const code = body.locator('tt');
        const size = await code.boundingBox();
        await code.click({position: {x: size.width - 1, y: size.height / 2}});
        await assertCaretAfter(page, body, 'right padding in surrounding emphasis');
        await page.keyboard.type('Suffix');
        assert.equal(await code.textContent(), 'command');
        await save(page);
        assert.deepEqual(saved, [pre + '<p><strong><tt>command</tt>Suffix</strong></p>']);
    });
}

export async function runInlineCodeBoundaryRegressions(browser, origin) {
    await runTrailingInlineCodeBoundaryRegressions(browser, origin);
    for (const source of [leading, middle]) {
        for (const colorScheme of ['dark', 'light']) {
            for (const mobile of [false, true]) {
                for (const action of ['left', 'click-left']) {
                    const label = `${browser.browserType().name()} ${source === leading ? 'leading' : 'middle'} code ${colorScheme} ${mobile ? 'mobile' : 'desktop'} ${action}`;
                    await withInlineCode(browser, origin, source, {colorScheme, mobile}, async ({page, body, saved}) => {
                        const initialPre = await body.locator('pre').innerHTML();
                        const initialCode = await body.locator('tt').evaluate(code => code.outerHTML);
                        await positionBefore(page, body, action);
                        await assertCaretBefore(page, body, label);
                        await page.keyboard.type('Label:');
                        const expected = source.replace('<tt>', 'Label:<tt>');
                        assert.equal(await body.locator('tt').evaluate(code => code.outerHTML), initialCode);
                        assert.equal(await body.locator('pre').innerHTML(), initialPre, `${label}: the preceding block must never receive this text`);
                        assert.equal(await body.locator('p').textContent(), source === leading
                            ? 'Label:command explains the operation.' : 'Plain text Label:command explains the operation.');
                        assert.equal(await body.locator('[data-post-inline-code-exit]').evaluateAll(markers => markers.every(marker => marker.textContent === '')), true,
                            'No author text can be stored in a disposable marker');
                        const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
                        await page.keyboard.press(`${modifier}+z`);
                        await assertCaretBefore(page, body, label + ' undo');
                        assert.equal(await body.locator('tt').evaluate(code => code.outerHTML), initialCode);
                        await page.keyboard.press(`${modifier}+Shift+z`);
                        await page.waitForFunction(expected => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
                            .list().some(copy => copy.snapshot.body === expected), expected);
                        if (process.env.EDITOR_INLINE_CODE_CAPTURE_DIR && source === leading && colorScheme === 'dark' && !mobile && action === 'click-left') {
                            await positionBefore(page, body, action);
                            await body.screenshot({path: `${process.env.EDITOR_INLINE_CODE_CAPTURE_DIR}/${browser.browserType().name()}-inline-code-boundary.png`});
                        }
                        await save(page);
                        assert.deepEqual(saved, [expected], `${label}: complete saved source has no runtime markers or style wrappers`);
                        await page.getByRole('button', {name: 'Edit', exact: true}).click();
                        await page.keyboard.type(' Changed title');
                        await save(page);
                        assert.deepEqual(saved, [expected, expected], `${label}: reopening and resaving retain every character and formatting tag`);
                    });
                }
            }
        }
    }
    await withInlineCode(browser, origin, pre + '<p><strong><tt>command</tt></strong> remains bold.</p>', {}, async ({page, body, saved}) => {
        await positionBefore(page, body, 'left');
        await assertCaretBefore(page, body, 'nested inline formatting');
        await page.keyboard.type('Label:');
        await save(page);
        assert.equal(saved.length, 1);
        assert.ok([
            pre + '<p>Label:<strong><tt>command</tt></strong> remains bold.</p>',
            pre + '<p><strong>Label:<tt>command</tt></strong> remains bold.</p>',
        ].includes(saved[0]), 'Native surrounding emphasis may apply to the prefix; the authored code and emphasis must stay intact');
    });
    for (const action of ['glyph', 'interior-left', 'double-click', 'right']) {
        await withInlineCode(browser, origin, leading, {}, async ({page, body}) => {
            const code = body.locator('tt');
            if (action === 'double-click') {
                await code.dblclick();
                assert.equal(await page.evaluate(() => getSelection().toString()), 'command');
                return;
            }
            await code.click();
            if (action === 'interior-left') {
                await page.keyboard.press('ArrowLeft');
            }
            if (action === 'right') {
                for (let count = 0; count < 16; count++) {
                    if (!await code.evaluate(code => code.contains(getSelection().anchorNode))) break;
                    await page.keyboard.press('ArrowRight');
                }
                await page.keyboard.type('Tail:');
                assert.equal(await code.textContent(), 'command', 'The existing right exit stays outside code');
                assert.equal(await body.locator('p').textContent(), 'commandTail: explains the operation.');
                return;
            }
            const offset = await code.evaluate(code => {
                const selection = getSelection();
                return code.contains(selection.anchorNode) ? selection.anchorOffset : -1;
            });
            assert.ok(offset >= 0 && offset < 7, `${action}: native editing is still inside the code`);
            await page.keyboard.type('X');
            assert.equal(await code.textContent(), 'command'.slice(0, offset) + 'X' + 'command'.slice(offset));
        });
    }
    await withInlineCode(browser, origin, pre + '<p>command explains the operation.</p>', {}, async ({page, body, saved}) => {
        await body.locator('p').dblclick({position: {x: 20, y: 12}});
        assert.equal(await page.evaluate(() => getSelection().toString()), 'command');
        await page.keyboard.press('Shift+F10');
        await page.getByRole('button', {name: 'Inline code', exact: true}).click();
        assert.equal(await body.locator('tt').textContent(), 'command');
        await positionBefore(page, body, 'left');
        await assertCaretBefore(page, body, 'normal inline-code formatting workflow');
        await page.keyboard.type('Label:');
        await save(page);
        assert.deepEqual(saved, [pre + '<p>Label:<tt>command</tt> explains the operation.</p>']);
    });
    await withInlineCode(browser, origin, leading, {}, async ({page, body, saved}) => {
        await positionBefore(page, body, 'click-left');
        await page.getByRole('textbox', {name: 'Title', exact: true}).click();
        await page.keyboard.type(' Changed title');
        await save(page);
        assert.deepEqual(saved, [leading], 'Navigation alone must not change saved content');
    });
    await withInlineCode(browser, origin, pre + '<tt>command</tt> explains the operation.', {}, async ({page, body, saved}) => {
        await positionBefore(page, body, 'left');
        await assertCaretBefore(page, body, 'inline code directly in the body');
        await page.keyboard.type('Label:');
        await save(page);
        assert.deepEqual(saved, [pre + 'Label:<tt>command</tt> explains the operation.']);
    });
    await withInlineCode(browser, origin, leading, {}, async ({page, body, saved}) => {
        await positionBefore(page, body, 'click-left');
        await page.keyboard.type('Before: ');
        await save(page);
        assert.deepEqual(saved, [pre + '<p>Before: <tt>command</tt> explains the operation.</p>'],
            'A normal typed space before code is retained without a runtime separator');
    });
    await withInlineCode(browser, origin, leading, {}, async ({page, body, saved}) => {
        const code = body.locator('tt');
        await positionBefore(page, body, 'click-left');
        await page.keyboard.press('ArrowRight');
        assert.equal(await code.evaluate(code => code.contains(getSelection().anchorNode)), true,
            'One Right from the outside leading caret enters the code');
        await page.keyboard.type('X');
        assert.equal(await code.textContent(), 'Xcommand');
        await code.click();
        for (let count = 0; count < 16; count++) {
            if (!await code.evaluate(code => code.contains(getSelection().anchorNode))) break;
            await page.keyboard.press('ArrowRight');
        }
        await page.keyboard.press('ArrowLeft');
        assert.equal(await code.evaluate(code => code.contains(getSelection().anchorNode)), true,
            'One Left from the outside trailing caret enters the code');
        await page.keyboard.type('Y');
        assert.equal(await code.textContent(), 'XcommandY');
        await save(page);
        assert.deepEqual(saved, [pre + '<p><tt>XcommandY</tt> explains the operation.</p>']);
    });
    await withInlineCode(browser, origin, pre + '<p><tt>first</tt><tt>command</tt></p>', {}, async ({page, body, saved}) => {
        const code = body.locator('tt').last();
        await code.click({position: {x: 1, y: 10}});
        await assertCaretBefore(page, body, 'between adjacent inline codes', 1);
        await page.keyboard.type('Label:');
        assert.deepEqual(await body.locator('tt').allTextContents(), ['first', 'command']);
        await save(page);
        assert.deepEqual(saved, [pre + '<p><tt>first</tt>Label:<tt>command</tt></p>']);
    });
    await withInlineCode(browser, origin, pre + '<p>Unrelated paragraph.</p>', {}, async ({page, body}) => {
        await body.locator('pre code').click({position: {x: 1, y: 5}});
        await page.keyboard.type('X');
        assert.ok((await body.locator('pre code').textContent()).includes('X'));
        assert.equal(await body.locator('[data-post-inline-code-exit]').count(), 0, 'Block code is never treated as an inline boundary');
        assert.equal(await body.locator('p').textContent(), 'Unrelated paragraph.');
    });
    console.log(`${browser.browserType().name()}: inline-code boundaries passed with visible carets, native editing, undo/redo, recovery and exact repeated saves`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit].filter(engine => !process.env.EDITOR_TEST_BROWSER
            || engine.name() === process.env.EDITOR_TEST_BROWSER)) {
            const browser = await engine.launch({headless: process.env.EDITOR_TEST_HEADED !== '1'
                && process.env.EDITOR_TEST_CLIPBOARD_HEADED !== '1'});
            try {
                await runInlineCodeBoundaryRegressions(browser, `http://127.0.0.1:${server.address().port}`);
                await runCopiedInlineCodeBoundaryRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
