import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {mkdir, readFile, writeFile} from 'node:fs/promises';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {formData} from './save-tests.mjs';

// Seeded, real-browser editing. The fixture supplies only the initial article,
// editor translations and an echo save endpoint; editing/history are production code.
const resources = execFileSync(process.env.PHP_BIN || 'php',
    [fileURLToPath(new URL('./context-menu-fixture.php', import.meta.url))], {encoding: 'utf8'});
const output = new URL('../../_output/editor-fuzz/', import.meta.url);
const texts = ['Альфа ёж', '👩🏽‍💻 семья 👨‍👩‍👧‍👦', 'e\u0301 и 🇷🇺', 'שלום مرحبا', 'A < B & C', 'one\u00a0two', '🧑‍🚀🎉'];
const formats = ['bold', 'italic', 'strike', 'clear-format', 'inline-code', 'paragraph', 'h2', 'h3', 'quote', 'code', 'unordered-list', 'ordered-list'];
const escape = value => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;');
const timed = (promise, timeout = 7000) => {
    let timer;
    return Promise.race([promise, new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error('editing-operation-timeout')), timeout);
    })]).finally(() => clearTimeout(timer));
};

function random(seed) {
    let state = seed >>> 0;
    return () => {
        state += 0x6D2B79F5;
        let value = state;
        value = Math.imul(value ^ value >>> 15, value | 1);
        value ^= value + Math.imul(value ^ value >>> 7, value | 61);
        return ((value ^ value >>> 14) >>> 0) / 4294967296;
    };
}

function generate(seed, steps) {
    const next = random(seed);
    const choose = values => values[Math.floor(next() * values.length)];
    let index = 0;
    const inline = () => `${index++}: ${escape(choose(texts))} <strong>${escape(choose(texts))}</strong> tail.`;
    const paragraph = () => '<p>' + inline() + '</p>';
    const media = () => '<div class="post-picture post-media-picture"><img src="/responsive-image.svg?fuzz='
        + index++ + '" width="160" height="90" alt="Fuzz image"><div class="post-caption">'
        + escape(choose(texts)) + '</div></div>';
    const blocks = [
        () => paragraph(),
        () => '<blockquote>' + paragraph() + paragraph() + '</blockquote>',
        () => '<ul><li>' + inline() + '<ol><li>' + inline() + '</li><li>' + inline() + '</li></ol></li><li>' + inline() + '</li></ul>',
        () => '<ol><li>' + inline() + media() + paragraph() + '</li><li>' + inline() + '</li></ol>',
        () => '<table><tbody><tr><td>' + paragraph() + '</td><td>' + paragraph() + '</td></tr></tbody></table>',
        () => '<h2>' + inline() + '</h2>' + media(),
        () => '<pre>line 1\n' + escape(choose(texts)) + '\nline 3</pre>',
        () => media() + '<p><br></p>',
    ];
    const html = paragraph() + Array.from({length: 3}, () => choose(blocks)()).join('') + paragraph();
    const operations = [];
    for (let step = 0; step < steps; step++) {
        const kind = choose(['format', 'format', 'format', 'type', 'type', 'backspace', 'delete', 'enter', 'linebreak', 'paste-text', 'paste-html', 'caption']);
        operations.push({kind, format: choose(formats), text: choose(texts),
            a: next(), b: next(), collapsed: next() < .6, backwards: next() < .5,
            edge: choose(['start', 'middle', 'end']), block: next() < .25});
    }
    return {seed, html, operations};
}

async function open(browser, origin, html) {
    const page = await browser.newPage();
    page.setDefaultTimeout(7000);
    const errors = [];
    const nativeTrace = [];
    page.on('pageerror', error => errors.push(String(error)));
    page.on('console', message => {
        if (message.text().startsWith('FUZZ_NATIVE ')) nativeTrace.push(message.text().slice(12));
    });
    await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
    await page.route('**/recovery-fixture.js', async route => {
        const response = await route.fetch();
        await route.fulfill({response, body: (await response.text()).replace(
            /const body = creating \? '' : [^\n]+;/u,
            'const body = creating ? "" : ' + JSON.stringify(html) + ';')});
    });
    let revision = 1;
    const saves = [];
    await page.route('**/_inplace/post/9', async route => {
        const data = await formData(route);
        const body = String(data.get('body')).replace(/\r\n?/gu, '\n');
        saves.push(body);
        await route.fulfill({json: {success: true, action: 'edit', title: data.get('title'), revision: ++revision,
            body_html: '<div class="post body" data-post-inplace-body>' + body + '</div>',
            published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
            tags: [], scheduled: false, message: 'Saved'}});
    });
    await page.goto(origin + '/recovery.html');
    await page.evaluate(resources => { document.getElementById('post-editor-resources').outerHTML = resources; }, resources);
    await page.getByRole('button', {name: 'Edit', exact: true}).click();
    if (process.env.EDITOR_FUZZ_TRACE_NATIVE) await page.evaluate(() => {
        const execute = document.execCommand.bind(document);
        document.execCommand = (command, ui, value) => {
            console.log('FUZZ_NATIVE ' + JSON.stringify({phase: 'enter', command, value}));
            const result = execute(command, ui, value);
            console.log('FUZZ_NATIVE ' + JSON.stringify({phase: 'leave', command}));
            return result;
        };
    });
    const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
    return {page, errors, nativeTrace, saves, modifier, body: page.locator('.is-editing [data-post-inplace-body]')};
}

async function snapshot(page) {
    return page.evaluate(() => {
        const state = editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
        const html = editorTest.editableBodyHtml(state).replace(/ class=""/gu, '');
        const root = document.createElement('div');
        root.innerHTML = html;
        const selected = getSelection();
        let caret = null;
        const selectedContents = selected.rangeCount ? selected.getRangeAt(0).cloneContents() : null;
        const logicallyCollapsed = selected.isCollapsed || (selectedContents && selectedContents.textContent === ''
            && !selectedContents.querySelector('img,audio,video,hr,br'));
        if (logicallyCollapsed && state.body.contains(selected.anchorNode)) {
            const prefix = document.createRange();
            prefix.setStart(state.body, 0);
            prefix.setEnd(selected.anchorNode, selected.anchorOffset);
            caret = prefix.cloneContents().textContent.replace(/\s/gu, '');
        }
        return {html, text: root.textContent.replace(/[\n\r\t]/gu, '').replace(/\u00a0/gu, ' '),
            images: Array.from(root.querySelectorAll('img'), image => [image.getAttribute('src'), image.getAttribute('alt')]),
            captions: Array.from(root.querySelectorAll('img'), image => image.closest('.post-picture, .post-media-picture, figure')
                ?.querySelector('.post-caption, figcaption')?.textContent || null),
            tables: root.querySelectorAll('table').length,
            bookmarks: state.body.querySelectorAll('[data-post-list-bookmark]').length,
            // Selection.toString() can be empty in Chromium when the final
            // selected block ends in a noneditable caption. Compare DOM ranges.
            selection: (selected.rangeCount ? selected.getRangeAt(0).toString() : '')
                .replace(/[\n\r\t]/gu, '').replace(/\u00a0/gu, ' '),
            history: state.history.length,
            caret,
            editable: state.body.contentEditable};
    });
}

async function select(page, operation) {
    return page.evaluate(op => {
        const body = document.querySelector('.is-editing [data-post-inplace-body]');
        body.focus();
        const nodes = [];
        const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT);
        let node;
        while ((node = walker.nextNode())) {
            if (node.data.length && !node.parentElement.closest('[contenteditable="false"], .post-caption, .post-media-caption-toolbar')) nodes.push(node);
        }
        const point = number => {
            if (!nodes.length) return [body, 0];
            const value = number * nodes.length;
            const node = nodes[Math.min(nodes.length - 1, Math.floor(value))];
            const boundaries = Array.from(new Intl.Segmenter(undefined, {granularity: 'grapheme'}).segment(node.data), item => item.index);
            boundaries.push(node.data.length);
            const offset = op.edge === 'start' ? 0 : op.edge === 'end' ? node.data.length
                : boundaries[Math.min(boundaries.length - 1, Math.floor((value % 1) * boundaries.length))];
            return [node, offset];
        };
        let first = point(Math.min(op.a, op.b));
        let last = op.collapsed ? first : point(Math.max(op.a, op.b));
        if (op.selectAll) {
            first = [body, 0];
            last = [body, body.childNodes.length];
        } else if (op.start) {
            const endpoint = saved => {
                const element = body.querySelector(saved.selector);
                if (!element) throw new Error('Replay selector did not match: ' + saved.selector);
                return [saved.element ? element : element.firstChild, saved.offset];
            };
            first = endpoint(op.start);
            last = op.collapsed ? first : endpoint(op.end);
        }
        if (op.block) {
            const element = first[0].parentElement?.closest('p,li,h2,h3,blockquote,pre,td');
            if (element && body.contains(element)) {
                first = [element, op.edge === 'end' ? element.childNodes.length : 0];
                if (op.collapsed) last = first;
            }
        }
        const selection = getSelection();
        if (!op.keepSelection) selection.setBaseAndExtent(...(op.backwards ? last : first), ...(op.backwards ? first : last));
        const range = selection.getRangeAt(0);
        const prefix = document.createRange();
        prefix.selectNodeContents(body);
        prefix.setEnd(range.startContainer, range.startOffset);
        const suffix = document.createRange();
        suffix.selectNodeContents(body);
        suffix.setStart(range.endContainer, range.endOffset);
        return {text: selection.toString(), html: body.innerHTML,
            collapsed: range.collapsed,
            prefix: prefix.cloneContents().textContent, suffix: suffix.cloneContents().textContent,
            anchor: [selection.anchorNode.nodeName, selection.anchorOffset],
            focus: [selection.focusNode.nodeName, selection.focusOffset]};
    }, operation);
}

async function apply(session, operation) {
    const {page, body, modifier} = session;
    if (operation.kind === 'caption') {
        const captions = body.locator('.is-inline-caption-entry');
        if (!await captions.count()) return {skipped: true};
        const index = Math.min(await captions.count() - 1, Math.floor(operation.a * await captions.count()));
        await captions.nth(index).click();
        const caption = body.locator('.is-editing-inline-caption');
        await caption.press(modifier + '+a');
        const expected = operation.text + (operation.collapsed ? '' : '\nsecond line');
        if (operation.collapsed) await page.keyboard.insertText(expected);
        else await caption.evaluate((caption, text) => {
            const clipboard = new DataTransfer();
            clipboard.setData('text/plain', text);
            const event = new Event('paste', {bubbles: true, cancelable: true});
            Object.defineProperty(event, 'clipboardData', {value: clipboard});
            caption.dispatchEvent(event);
        }, expected);
        await caption.press('Enter');
        assert.equal(await body.locator('.post-caption').nth(index).textContent(), expected, 'caption-changes-lines');
        return {};
    }
    let selection;
    if (operation.nativeSelectAll) {
        await body.focus();
        await page.keyboard.press(modifier + '+a');
        selection = await select(page, {...operation, keepSelection: true});
    } else if (operation.nativeSelection) {
        await select(page, {...operation, collapsed: true, start: operation.nativeSelection < 0 ? operation.end : operation.start});
        for (let index = 0; index < Math.abs(operation.nativeSelection); index++) {
            await page.keyboard.press('Shift+' + (operation.nativeSelection < 0 ? 'ArrowLeft' : 'ArrowRight'));
        }
        selection = await select(page, {...operation, keepSelection: true});
    } else selection = await select(page, operation);
    const before = await snapshot(page);
    if (operation.kind === 'format') {
        if (operation.ui || process.env.EDITOR_FUZZ_UI) {
            await body.press('Shift+F10');
            await page.locator('.post-editor-context-menu [data-context-action="' + operation.format + '"]').click();
        } else await page.evaluate(action => {
            const state = editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
            const range = getSelection().getRangeAt(0).cloneRange();
            state.contextMenu = {range, anchor: document.createElement('div'), blockStyle: editorTest.contextBlockStyle(state.body, range)};
            editorTest.handleContextAction(state, action);
        }, operation.format);
    } else if (operation.kind === 'type') await page.keyboard.insertText(operation.text);
    else if (operation.kind === 'backspace') await page.keyboard.press('Backspace');
    else if (operation.kind === 'delete') await page.keyboard.press('Delete');
    else if (operation.kind === 'enter') await page.keyboard.press('Enter');
    else if (operation.kind === 'linebreak') await page.keyboard.press('Shift+Enter');
    else if (operation.kind.startsWith('paste-')) {
        await body.evaluate((body, operation) => {
            const clipboard = new DataTransfer();
            clipboard.setData('text/plain', operation.text + '\nnext line\n\nlast line');
            if (operation.kind === 'paste-html') clipboard.setData('text/html',
                '<blockquote><p>Pasted <strong>rich 👩🏽‍💻</strong></p></blockquote><ul><li>outer<ol><li>nested</li></ol></li></ul>');
            const event = new Event('paste', {bubbles: true, cancelable: true});
            Object.defineProperty(event, 'clipboardData', {value: clipboard});
            body.dispatchEvent(event);
            // Synthetic paste has no browser default. Invoke the same native HTML
            // insertion only when the editor intentionally leaves paste to it.
            if (!event.defaultPrevented) document.execCommand('insertHTML', false,
                clipboard.getData('text/html') || clipboard.getData('text/plain').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('\n', '<br>'));
        }, operation);
    }
    const after = await snapshot(page);
    session.lastAction = {selection, before, after};
    assert.equal(after.bookmarks, 0, 'bookmark-leak');
    assert.equal(after.editable, 'true', 'body-left-noneditable');
    assert.ok(after.html.isWellFormed(), 'invalid-unicode-html');
    if (operation.kind === 'format') {
        assert.equal(after.text.replace(/\s/gu, ''), before.text.replace(/\s/gu, ''), 'format-changes-text');
        assert.deepEqual(after.images, before.images, 'format-changes-images');
        assert.deepEqual(after.captions, before.captions, 'format-detaches-caption');
        assert.equal(after.tables, before.tables, 'format-changes-tables');
        if (!operation.collapsed) assert.equal(after.selection.replace(/\s/gu, ''), before.selection.replace(/\s/gu, ''), 'format-changes-selection');
        else assert.equal(after.caret, before.caret, 'format-moves-caret');
    }
    if (!before.images.length && (operation.kind === 'type' ||
        (!selection.collapsed && ['backspace', 'delete', 'enter', 'linebreak'].includes(operation.kind)))) {
        const inserted = operation.kind === 'type' ? operation.text : '';
        assert.equal(after.text.replace(/\s/gu, ''), (selection.prefix + inserted + selection.suffix).replace(/\s/gu, ''), 'editing-changes-unselected-text');
    }
    return {selection, before, after};
}

async function historyRoundTrip(session) {
    const before = await snapshot(session.page);
    if (before.history <= 1) return;
    await session.body.focus();
    await session.page.keyboard.press(session.modifier + '+z');
    const undone = await snapshot(session.page);
    if (undone.html === before.html) return;
    await session.page.keyboard.press(session.modifier + '+Shift+z');
    assert.equal((await snapshot(session.page)).html, before.html, 'undo-redo-changes-content');
}

async function saveRoundTrip(session) {
    const {page, saves} = session;
    const before = await snapshot(page);
    // A title edit forces an actual request even if body operations were no-ops.
    const title = page.locator('.is-editing [data-post-inplace-title]');
    await title.focus();
    await page.keyboard.insertText(' Save checkpoint');
    await page.getByRole('button', {name: 'Save', exact: true}).click();
    await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
    assert.equal(saves.at(-1), before.html, 'save-payload-changes-content');
    await page.getByRole('button', {name: 'Edit', exact: true}).click();
    const reopened = await snapshot(page);
    assert.equal(reopened.text, before.text, 'save-reopen-changes-text');
    assert.deepEqual(reopened.images, before.images, 'save-reopen-changes-images');
    assert.equal(reopened.html, before.html, 'save-reopen-changes-structure');
}

async function runCase(browser, origin, generated, checks = true) {
    const session = await open(browser, origin, generated.html);
    let step = -1;
    let last;
    try {
        for (const operation of generated.operations) {
            step++;
            if (process.env.EDITOR_FUZZ_TRACE) console.log(`${browser.browserType().name()} seed=${generated.seed} step=${step} ${operation.kind} ${operation.format}`);
            last = await timed(snapshot(session.page));
            await writeFile(new URL(`latest-${browser.browserType().name()}.json`, output), JSON.stringify({seed: generated.seed,
                initial: generated.html, operations: generated.operations.slice(0, step + 1), step, before: last}, null, 2));
            if (process.env.EDITOR_FUZZ_SCREENSHOTS) await session.page.screenshot({path: fileURLToPath(new URL(`${browser.browserType().name()}-${generated.seed}-before-${step}.png`, output))});
            await timed(apply(session, operation));
            if (checks && step % 7 === 6) await timed(historyRoundTrip(session));
            assert.deepEqual(session.errors, [], 'page-errors');
        }
        step++;
        await timed(saveRoundTrip(session), 10000);
        return null;
    } catch (error) {
        let after;
        try { after = await timed(snapshot(session.page), 1000); } catch {}
        if (process.env.EDITOR_FUZZ_SCREENSHOTS) await timed(session.page.screenshot({path: fileURLToPath(new URL(`${browser.browserType().name()}-${generated.seed}-failure.png`, output))}), 2000).catch(() => {});
        return {seed: generated.seed, engine: browser.browserType().name(), step,
            error: error.message, stack: error.stack, initial: generated.html,
            operations: generated.operations.slice(0, step + 1), before: last, after, lastAction: session.lastAction, nativeTrace: session.nativeTrace};
    } finally { await timed(session.page.close(), 3000).catch(() => {}); }
}

async function minimize(browser, origin, replay) {
    let generated = {seed: replay.seed, html: replay.initial || replay.html, operations: replay.operations};
    let failure = await runCase(browser, origin, generated);
    if (!failure) throw new Error('The recorded failure did not reproduce');
    const signature = failure.error.split('\n')[0];
    let parts = 2;
    while (generated.operations.length > 1) {
        const chunk = Math.ceil(generated.operations.length / parts);
        let reduced = false;
        for (let offset = 0; offset < generated.operations.length; offset += chunk) {
            const operations = generated.operations.filter((_, index) => index < offset || index >= offset + chunk);
            if (!operations.length) continue;
            const candidate = {...generated, operations};
            const result = await runCase(browser, origin, candidate);
            if (!result || result.error.split('\n')[0] !== signature) continue;
            generated = candidate;
            failure = result;
            parts = Math.max(2, parts - 1);
            reduced = true;
            console.log(`minimize ${signature}: ${operations.length} actions`);
            break;
        }
        if (reduced) continue;
        if (parts >= generated.operations.length) break;
        parts = Math.min(generated.operations.length, parts * 2);
    }
    const filename = `${browser.browserType().name()}-${generated.seed}-minimal.json`;
    await writeFile(new URL(filename, output), JSON.stringify(failure, null, 2));
    return failure;
}

async function main() {
    await mkdir(output, {recursive: true});
    const baseSeed = Number(process.env.EDITOR_FUZZ_SEED || 20261026);
    const cases = Number(process.env.EDITOR_FUZZ_CASES || 30);
    const steps = Number(process.env.EDITOR_FUZZ_STEPS || 40);
    assert.ok(Number.isInteger(baseSeed) && baseSeed >= 0 && baseSeed <= 0xffffffff, 'EDITOR_FUZZ_SEED must be an unsigned 32-bit integer');
    assert.ok(Number.isInteger(cases) && cases > 0 && Number.isInteger(steps) && steps > 0, 'Fuzz cases/steps must be positive integers');
    assert.ok(!process.env.EDITOR_TEST_BROWSER || ['chromium', 'firefox', 'webkit'].includes(process.env.EDITOR_TEST_BROWSER), 'Unknown EDITOR_TEST_BROWSER');
    const report = {baseSeed, cases, steps, sourceCommit: execFileSync('git', ['rev-parse', 'HEAD'], {encoding: 'utf8'}).trim(),
        platform: process.platform, node: process.version, started: new Date().toISOString(), runs: [], failures: []};
    const replay = process.env.EDITOR_FUZZ_REPLAY ? JSON.parse(await readFile(process.env.EDITOR_FUZZ_REPLAY, 'utf8')) : null;
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    try {
        for (const engine of [chromium, firefox, webkit]) {
            if (process.env.EDITOR_TEST_BROWSER && process.env.EDITOR_TEST_BROWSER !== engine.name()) continue;
            const browser = await engine.launch();
            try {
                if (replay && process.env.EDITOR_FUZZ_MINIMIZE) {
                    const minimized = await minimize(browser, origin, replay);
                    report.failures.push(minimized);
                    console.log(`${engine.name()} minimized seed=${minimized.seed}: ${minimized.operations.length} actions`);
                    continue;
                }
                let passed = 0;
                let actions = 0;
                for (let index = 0; index < (replay ? 1 : cases); index++) {
                    const generated = replay ? {seed: replay.seed, html: replay.initial || replay.html, operations: replay.operations}
                        : generate(baseSeed + index, steps);
                    const failure = await runCase(browser, origin, generated);
                    if (failure) {
                        report.failures.push(failure);
                        await writeFile(new URL(`${engine.name()}-${generated.seed}.json`, output), JSON.stringify(failure, null, 2));
                        console.log(`${engine.name()} seed=${generated.seed} step=${failure.step} FAIL ${failure.error.split('\n')[0]}`);
                    } else passed++;
                    actions += failure ? failure.step + 1 : generated.operations.length;
                    if ((index + 1) % 10 === 0) console.log(`${engine.name()}: ${index + 1} seeds, ${actions} actions, ${passed} passed`);
                }
                report.runs.push({engine: engine.name(), version: browser.version(), passed, actions});
            } finally { await browser.close(); }
        }
    } finally {
        report.finished = new Date().toISOString();
        await writeFile(new URL('report.json', output), JSON.stringify(report, null, 2));
        const reportId = replay ? `replay-${replay.seed}${process.env.EDITOR_FUZZ_MINIMIZE ? '-minimal' : ''}` : String(baseSeed);
        await writeFile(new URL(`report-${reportId}-${process.env.EDITOR_TEST_BROWSER || 'all'}.json`, output), JSON.stringify(report, null, 2));
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
    console.log(`Fuzz report: ${fileURLToPath(new URL('report.json', output))}`);
    console.log(JSON.stringify({runs: report.runs, failures: report.failures.length}));
    if (report.failures.length) process.exitCode = 1;
}

export {open as openFuzzEditor, snapshot as fuzzSnapshot, apply as applyFuzzAction, historyRoundTrip, saveRoundTrip, random, timed};

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) await main();
