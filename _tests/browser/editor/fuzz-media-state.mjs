import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {mkdir, readFile, writeFile} from 'node:fs/promises';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {openFuzzEditor, fuzzSnapshot, historyRoundTrip, saveRoundTrip, random, timed} from './fuzz-editing.mjs';
import {fulfillPreview} from './html-block-tests.mjs';
import {formData} from './save-tests.mjs';

const output = new URL('../../_output/editor-fuzz-media/', import.meta.url);
const picture = (name, caption) => '<div class="post-picture post-media-picture"><img src="/responsive-image.svg"'
    + ' width="160" height="90" alt="' + name + '"><div class="post-caption">' + caption + '</div></div>';
const initial = '<p id="intro">Introduction 👩🏽‍💻.</p><ul><li><p id="list-a">List alpha.</p>'
    + picture('A', 'Caption A') + '</li><li><p id="list-b">List beta.</p></li></ul>'
    + '<blockquote><p id="quote-a">Quote alpha.</p>' + picture('B', 'Caption B')
    + '<p id="quote-b">Quote beta.</p></blockquote><p id="outro">Final paragraph.</p>';

async function mediaState(page) {
    return page.evaluate(() => {
        const state = editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
        const root = state.body.cloneNode(true);
        const images = Array.from(root.querySelectorAll('img'), image => {
            const owner = image.closest('.post-picture, .post-media-picture, figure, [data-post-media-overlay]');
            const caption = owner?.querySelector('.post-caption, figcaption, .post-media-overlay-caption');
            return [image.getAttribute('alt'), caption?.textContent || ''];
        }).sort((a, b) => a[0].localeCompare(b[0]) || a[1].localeCompare(b[1]));
        root.querySelectorAll('.post-picture, .post-media-picture, figure, [data-post-media-overlay], [data-post-html-source]')
            .forEach(node => node.remove());
        return {images, prose: root.textContent.replace(/\s/gu, ''),
            sources: Array.from(state.body.querySelectorAll('[data-post-html-source]'), node => node.dataset.postHtmlSource),
            htmlBlocks: state.body.querySelectorAll('[data-post-html-source]').length,
            forms: document.querySelectorAll('form[target^="register-html-"]').length,
            editable: state.body.contentEditable};
    });
}

async function act(session, operation) {
    const {page, body, modifier} = session;
    const before = await fuzzSnapshot(page);
    const mediaBefore = await mediaState(page);
    if (operation.kind === 'drag') {
        const source = body.locator('img').nth(operation.image);
        const targets = body.locator('p, .post-caption');
        const target = operation.targetSelector ? body.locator(operation.targetSelector).nth(operation.targetIndex || 0)
            : targets.nth(Math.min(await targets.count() - 1, Math.floor(operation.target * await targets.count())));
        session.dragLocation = {target: await target.evaluate(node => node.outerHTML), before: mediaBefore};
        await source.dragTo(target, {sourcePosition: {x: 70, y: 45}, targetPosition: {x: 4 + operation.position * 60, y: 10}});
        const after = await mediaState(page);
        session.dragLocation.after = after;
        session.dragLocation.serialized = await fuzzSnapshot(page);
        if (operation.save) {
            await saveRoundTrip(session);
            session.dragLocation.submitted = session.saves.at(-1);
            session.dragLocation.reopened = await fuzzSnapshot(page);
        }
        assert.deepEqual(after.images, mediaBefore.images, 'drag-changes-image-caption-association');
        assert.equal(after.prose, mediaBefore.prose, 'drag-changes-prose');
        if (operation.history !== false) await historyRoundTrip(session);
    } else if (operation.kind === 'caption') {
        const captions = body.locator('.is-inline-caption-entry');
        if (!await captions.count()) return;
        await captions.nth(Math.min(operation.image, await captions.count() - 1)).click();
        const caption = body.locator('.is-editing-inline-caption');
        await caption.press(modifier + '+a');
        await page.keyboard.insertText(operation.text);
        await caption.press('Enter');
        if (operation.historyType) {
            const committed = await fuzzSnapshot(page);
            assert.notEqual(committed.caret, null, 'caption-commit-loses-caret');
            await page.keyboard.press(modifier + '+z');
            await page.keyboard.press(modifier + '+Shift+z');
            const redone = await fuzzSnapshot(page);
            await page.keyboard.insertText(operation.historyType);
            const typed = await fuzzSnapshot(page);
            session.typingLocation = {committed, redone, typed};
            const text = committed.text.replace(/\s/gu, '');
            assert.equal(typed.text.replace(/\s/gu, ''), committed.caret + operation.historyType
                + text.slice(committed.caret.length), 'caption-redo-moves-typing-position');
        }
    } else if (operation.kind === 'overlay-cancel') {
        const image = body.locator('img').nth(operation.image);
        await body.focus();
        await page.evaluate(() => getSelection().removeAllRanges());
        await image.click({button: 'right'});
        await page.locator('[data-context-action="edit-image-caption"]').click();
        await body.locator('.is-editing-caption').fill(operation.text);
        const style = page.locator('.post-media-caption-toolbar [data-caption-font]').first();
        if (await style.count()) await style.click();
        await page.locator('.post-media-caption-toolbar [data-caption-action="cancel"]').click();
        assert.equal((await fuzzSnapshot(page)).html, before.html, 'overlay-cancel-changes-content');
    } else if (operation.kind === 'delete-all') {
        if (operation.focus !== false) await body.focus();
        const beforeAll = await page.evaluate(() => ({anchor: getSelection().anchorNode?.nodeName,
            anchorParent: getSelection().anchorNode?.parentElement?.outerHTML.slice(0, 500), anchorOffset: getSelection().anchorOffset,
            focus: getSelection().focusNode?.nodeName, focusOffset: getSelection().focusOffset,
            active: document.activeElement?.outerHTML.slice(0, 200)}));
        await page.keyboard.press(modifier + '+a');
        session.bulkSelection = await page.evaluate(() => ({active: document.activeElement?.outerHTML.slice(0, 200),
            text: getSelection().toString(), anchor: getSelection().anchorNode?.nodeName, anchorOffset: getSelection().anchorOffset,
            focus: getSelection().focusNode?.nodeName, focusOffset: getSelection().focusOffset}));
        session.bulkSelection.before = beforeAll;
        await page.keyboard.press('Backspace');
        assert.equal(await body.locator('img,[data-post-html-source]').count(), 0, 'delete-all-retains-atomic-blocks');
        await page.keyboard.press(modifier + '+z');
        assert.equal((await fuzzSnapshot(page)).html, before.html, 'undo-all-loses-content');
        await page.keyboard.press(modifier + '+Shift+z');
        assert.equal(await body.locator('img,[data-post-html-source]').count(), 0, 'redo-all-retains-atomic-blocks');
        await page.keyboard.press(modifier + '+z');
        assert.equal((await fuzzSnapshot(page)).html, before.html, 'second-undo-all-loses-content');
    } else if (operation.kind === 'html') {
        if (mediaBefore.htmlBlocks >= 3) return;
        const targets = body.locator('p').filter({hasNot: page.locator('[data-post-html-source]')});
        const target = targets.nth(Math.min(await targets.count() - 1, Math.floor(operation.target * await targets.count())));
        await target.evaluate(target => {
            const body = target.closest('[data-post-inplace-body]');
            body.focus();
            const range = document.createRange(); range.selectNodeContents(target); range.collapse(false);
            getSelection().removeAllRanges(); getSelection().addRange(range);
        });
        await body.press('Shift+F10');
        await page.locator('[data-context-action="html"]').click();
        const block = body.locator('.post-html-block').filter({has: page.locator('textarea:not([hidden])')});
        const source = '<section><p>HTML ' + operation.text + '</p><ul><li>one</li><li>two</li></ul></section>';
        await block.locator('textarea').fill(source);
        await block.locator('.post-html-block-edit').click();
        assert.ok((await mediaState(page)).sources.includes(source), 'html-source-lost-on-done');
        if (operation.history !== false) await historyRoundTrip(session);
    } else if (operation.kind === 'html-remove') {
        const blocks = body.locator('.post-html-block');
        if (!await blocks.count()) return;
        await blocks.first().locator('.post-html-block-remove').click();
        await historyRoundTrip(session);
    } else if (operation.kind === 'cut-paste-all' || operation.kind === 'copy-image' || operation.kind === 'cut-image') {
        const clipboard = await body.evaluate((body, operation) => {
            body.focus();
            const range = document.createRange();
            let copiedImage;
            if (operation.kind === 'cut-paste-all') range.selectNodeContents(body);
            else {
                const images = body.querySelectorAll('img');
                const image = images[Math.min(operation.image, images.length - 1)];
                const owner = image.closest('.post-picture, .post-media-picture, figure') || image;
                copiedImage = [image.getAttribute('alt'), owner.querySelector('.post-caption, figcaption')?.textContent || ''];
                range.selectNode(owner);
            }
            getSelection().removeAllRanges(); getSelection().addRange(range);
            const data = new DataTransfer();
            const event = new Event(operation.kind === 'copy-image' ? 'copy' : 'cut', {bubbles: true, cancelable: true});
            Object.defineProperty(event, 'clipboardData', {value: data});
            body.dispatchEvent(event);
            return {html: data.getData('text/html'), text: data.getData('text/plain'), handled: event.defaultPrevented, copiedImage};
        }, operation);
        assert.ok(clipboard.handled, 'media-clipboard-not-handled');
        if (operation.kind === 'cut-paste-all') {
            assert.equal(await body.locator('img,[data-post-html-source]').count(), 0, 'cut-all-retains-blocks');
        } else await body.evaluate((body, operation) => {
            const targets = body.querySelectorAll('p');
            const target = targets[Math.min(targets.length - 1, Math.floor(operation.target * targets.length))] || body;
            const range = document.createRange(); range.selectNodeContents(target); range.collapse(false);
            getSelection().removeAllRanges(); getSelection().addRange(range);
        }, operation);
        await body.evaluate((body, clipboard) => {
            const data = new DataTransfer();
            data.setData('text/html', clipboard.html); data.setData('text/plain', clipboard.text);
            const event = new Event('paste', {bubbles: true, cancelable: true});
            Object.defineProperty(event, 'clipboardData', {value: data});
            body.dispatchEvent(event);
            if (!event.defaultPrevented) document.execCommand('insertHTML', false, clipboard.html);
        }, clipboard);
        const pasted = await mediaState(page);
        if (operation.kind === 'copy-image') {
            const copied = clipboard.copiedImage;
            assert.ok(copied, 'copy-image-missing-original');
            const expected = [...mediaBefore.images, copied].sort((a, b) => a[0].localeCompare(b[0]) || a[1].localeCompare(b[1]));
            assert.deepEqual(pasted.images, expected, 'copy-image-changes-caption-or-identity');
            session.expectedImages = expected.map(([name]) => name);
        } else assert.deepEqual(pasted.images, mediaBefore.images, 'cut-paste-loses-image-or-caption');
        assert.equal(pasted.prose, mediaBefore.prose, 'clipboard-changes-unselected-prose');
        await historyRoundTrip(session);
    } else if (operation.kind === 'history') await historyRoundTrip(session);
    const after = await mediaState(page);
    assert.deepEqual(after.images.map(([name]) => name), session.expectedImages, 'media-count-or-identity-changed');
    assert.equal(after.editable, 'true', 'body-remains-noneditable');
    assert.equal(after.forms, after.htmlBlocks, 'orphan-preview-form');
    assert.deepEqual(session.errors, [], 'page-errors');
}

function generated(seed, steps) {
    const next = random(seed);
    return {seed, html: initial, operations: Array.from({length: steps}, () => ({
        kind: ['drag', 'drag', 'caption', 'overlay-cancel', 'delete-all', 'history', 'html', 'html-remove',
            'cut-paste-all', 'copy-image', 'cut-image'][Math.floor(next() * 11)],
        image: Math.floor(next() * 2), target: next(), position: next(), text: ['Edited 👩🏽‍💻', 'é 🇷🇺', 'A & B'][Math.floor(next() * 3)],
    }))};
}

async function main() {
    await mkdir(output, {recursive: true});
    const replay = process.env.EDITOR_FUZZ_REPLAY ? JSON.parse(await readFile(process.env.EDITOR_FUZZ_REPLAY, 'utf8')) : null;
    const cases = Number(process.env.EDITOR_FUZZ_CASES || 20);
    const steps = Number(process.env.EDITOR_FUZZ_STEPS || 20);
    const baseSeed = Number(process.env.EDITOR_FUZZ_SEED || 20263000);
    assert.ok(Number.isSafeInteger(cases) && cases > 0 && Number.isSafeInteger(steps) && steps > 0, 'Positive integer cases/steps required');
    assert.ok(Number.isSafeInteger(baseSeed), 'Integer seed required');
    assert.ok(!process.env.EDITOR_TEST_BROWSER || ['chromium', 'firefox', 'webkit'].includes(process.env.EDITOR_TEST_BROWSER), 'Unknown browser');
    const report = {sourceCommit: execFileSync('git', ['rev-parse', 'HEAD'], {encoding: 'utf8'}).trim(),
        started: new Date().toISOString(), platform: process.platform, node: process.version,
        generatorVersion: 2, baseSeed, cases, steps, runs: [], failures: []};
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    try {
        for (const engine of [chromium, firefox, webkit]) {
            if (process.env.EDITOR_TEST_BROWSER && process.env.EDITOR_TEST_BROWSER !== engine.name()) continue;
            const browser = await engine.launch();
            try {
                let actions = 0;
                let passed = 0;
                for (let index = 0; index < (replay ? 1 : cases); index++) {
                    const test = replay || generated(baseSeed + index, steps);
                    const session = await openFuzzEditor(browser, origin, test.html || test.initial);
                    session.expectedImages = (await mediaState(session.page)).images.map(([name]) => name);
                    await session.page.route('**/_inplace/post/9', async route => {
                        const data = await formData(route);
                        if (data.get('inplace_action') === 'html_preview') await fulfillPreview(route, data);
                        else await route.fallback();
                    });
                    let step = 0;
                    let before;
                    try {
                        for (const operation of test.operations) {
                            if (process.env.EDITOR_FUZZ_TRACE) console.log(engine.name(), test.seed, step, operation.kind);
                            before = await timed(fuzzSnapshot(session.page));
                            if (process.env.EDITOR_FUZZ_SCREENSHOTS) await session.page.screenshot({path: fileURLToPath(new URL(`${engine.name()}-${test.seed}-before-${step}.png`, output))});
                            await timed(act(session, operation), 12000);
                            step++; actions++;
                        }
                        await timed(saveRoundTrip(session), 12000);
                        passed++;
                    } catch (error) {
                        const failure = {seed: test.seed, engine: engine.name(), step, html: test.html || test.initial,
                            operations: test.operations.slice(0, step + 1), error: error.message, before, url: session.page.url(),
                            bulkSelection: session.bulkSelection, typingLocation: session.typingLocation};
                        failure.dragLocation = session.dragLocation;
                        try { failure.after = await timed(fuzzSnapshot(session.page), 1000); } catch {}
                        if (process.env.EDITOR_FUZZ_SCREENSHOTS) await timed(session.page.screenshot({path: fileURLToPath(new URL(`${engine.name()}-${test.seed}-failure.png`, output))}), 2000).catch(() => {});
                        await writeFile(new URL(`${engine.name()}-${test.seed}.json`, output), JSON.stringify(failure, null, 2));
                        report.failures.push(failure);
                        console.log(engine.name(), test.seed, step, 'FAIL', error.message.split('\n')[0]);
                    } finally { await timed(session.page.close(), 3000).catch(() => {}); }
                    if ((index + 1) % 5 === 0) console.log(engine.name(), index + 1, 'seeds;', actions, 'actions;', passed, 'passed');
                }
                report.runs.push({engine: engine.name(), version: browser.version(), actions, passed});
            } finally { await browser.close(); }
        }
    } finally {
        report.finished = new Date().toISOString();
        const id = replay ? 'replay-' + replay.seed : String(baseSeed);
        await writeFile(new URL(`report-${id}-${process.env.EDITOR_TEST_BROWSER || 'all'}.json`, output), JSON.stringify(report, null, 2));
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
    console.log(JSON.stringify({runs: report.runs, failures: report.failures.length}));
    if (report.failures.length) process.exitCode = 1;
}

export {act as applyMediaFuzzAction, mediaState};

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) await main();
