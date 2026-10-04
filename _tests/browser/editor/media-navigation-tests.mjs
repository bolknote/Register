import assert from 'node:assert/strict';
import {inflateSync} from 'node:zlib';
import {pathToFileURL} from 'node:url';
import {formData, holdRequests} from './save-tests.mjs';

// Inspect the rendered caret, not merely a CSS class or a valid Selection.
// Playwright screenshots are non-interlaced 8-bit RGB/RGBA PNGs.
function pixels(png) {
    let width, height, channels;
    const chunks = [];
    for (let offset = 8; offset < png.length;) {
        const length = png.readUInt32BE(offset);
        const type = png.toString('ascii', offset + 4, offset + 8);
        const data = png.subarray(offset + 8, offset + 8 + length);
        if (type === 'IHDR') {
            width = data.readUInt32BE(0);
            height = data.readUInt32BE(4);
            assert.equal(data[8], 8);
            assert.ok([2, 6].includes(data[9]));
            assert.equal(data[12], 0);
            channels = data[9] === 6 ? 4 : 3;
        }
        if (type === 'IDAT') chunks.push(data);
        offset += length + 12;
    }
    const raw = inflateSync(Buffer.concat(chunks));
    const stride = width * channels;
    const result = Buffer.alloc(stride * height);
    for (let y = 0; y < height; ++y) {
        const filter = raw[y * (stride + 1)];
        for (let x = 0; x < stride; ++x) {
            const left = x < channels ? 0 : result[y * stride + x - channels];
            const above = y === 0 ? 0 : result[(y - 1) * stride + x];
            const upperLeft = y === 0 || x < channels ? 0 : result[(y - 1) * stride + x - channels];
            const predictor = left + above - upperLeft;
            const distances = [left, above, upperLeft].map(value => Math.abs(predictor - value));
            const paeth = distances[0] <= distances[1] && distances[0] <= distances[2]
                ? left : distances[1] <= distances[2] ? above : upperLeft;
            const correction = [0, left, above, Math.floor((left + above) / 2), paeth][filter];
            assert.notEqual(correction, undefined);
            result[y * stride + x] = (raw[y * (stride + 1) + 1 + x] + correction) & 255;
        }
    }
    return {data: result, channels};
}

async function assertCaret(page, body, side, label) {
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    const caret = await body.evaluate((body, side) => {
        const selection = getSelection();
        const range = selection?.rangeCount === 1 ? selection.getRangeAt(0) : null;
        const media = body.querySelector('.post-media-picture');
        const imageRange = document.createRange();
        imageRange.selectNode(media);
        imageRange.collapse(side === 'before');
        const correctSide = range?.collapsed && body.contains(range.startContainer)
            && (side === 'before'
                ? range.compareBoundaryPoints(Range.START_TO_START, imageRange) <= 0
                : range.compareBoundaryPoints(Range.START_TO_START, imageRange) >= 0);
        const painted = body.matches('.has-leading-boundary-caret')
            ? body : body.querySelector('.has-leading-boundary-caret');
        const style = painted ? getComputedStyle(painted, '::before') : null;
        const rect = painted?.getBoundingClientRect();
        return {
            correctSide: Boolean(correctSide), focused: document.activeElement === body,
            count: document.querySelectorAll('.has-leading-boundary-caret').length,
            content: style?.content, color: style?.backgroundColor,
            x: rect ? rect.x + parseFloat(style.left) : NaN,
            y: rect ? rect.y + parseFloat(style.top) : NaN,
            width: style ? parseFloat(style.width) : 0,
            height: style ? parseFloat(style.height) : 0,
            viewportWidth: innerWidth, viewportHeight: innerHeight,
            html: body.innerHTML,
        };
    }, side);
    assert.ok(caret.correctSide, `${label}: caret must be ${side} the picture: ${JSON.stringify(caret)}`);
    assert.ok(caret.focused, `${label}: body must retain keyboard focus`);
    assert.equal(caret.count, 1, `${label}: exactly one painted boundary caret`);
    assert.notEqual(caret.content, 'none');
    assert.ok(caret.width > 0 && caret.height > 0);
    assert.ok(caret.x >= 0 && caret.y >= 0
        && caret.x + caret.width < caret.viewportWidth && caret.y + caret.height < caret.viewportHeight,
    `${label}: caret must be in the visible viewport: ${JSON.stringify(caret)}`);
    const rendered = pixels(await page.screenshot({clip: {
        x: Math.floor(caret.x), y: Math.floor(caret.y),
        width: Math.ceil(caret.width + 2), height: Math.ceil(caret.height),
    }}));
    const color = caret.color.match(/[\d.]+/gu).slice(0, 3).map(Number);
    let matches = 0;
    for (let i = 0; i < rendered.data.length; i += rendered.channels) {
        if (color.every((value, channel) => Math.abs(rendered.data[i + channel] - value) <= 2)) ++matches;
    }
    assert.ok(matches >= caret.height / 2,
        `${label}: the screenshot must contain the visible accent-colored caret (${matches} pixels): ${JSON.stringify(caret)}`);
}

async function insertImage(page, body, height, source) {
    const png = await body.evaluate(async (body, {height, source}) => {
        const canvas = document.createElement('canvas');
        canvas.width = 800;
        canvas.height = height;
        const context = canvas.getContext('2d');
        context.fillStyle = '#426482';
        context.fillRect(0, 0, canvas.width, canvas.height);
        if (source === 'picker') return canvas.toDataURL('image/png').split(',')[1];
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
        const transfer = new DataTransfer();
        transfer.items.add(new File([blob], 'navigation.png', {type: 'image/png'}));
        const event = new Event('paste', {bubbles: true, cancelable: true});
        Object.defineProperty(event, 'clipboardData', {value: transfer});
        body.dispatchEvent(event);
    }, {height, source});
    if (source === 'picker') {
        await body.press('Shift+F10');
        const chooser = page.waitForEvent('filechooser');
        await page.locator('.post-editor-context-menu [data-context-action="media"]').click();
        await (await chooser).setFiles({name: 'navigation.png', mimeType: 'image/png', buffer: Buffer.from(png, 'base64')});
    }
}

async function withNewPost(browser, origin, height, run, initial = '', source = 'paste') {
    const page = await browser.newPage({viewport: {width: 1100, height: 900}});
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    try {
        await page.emulateMedia({reducedMotion: 'reduce', colorScheme: height > 900 ? 'dark' : 'light'});
        await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        await page.route('**/navigation.svg', route => route.fulfill({contentType: 'image/svg+xml', body:
            `<svg xmlns="http://www.w3.org/2000/svg" width="800" height="${height}"><rect width="800" height="${height}" fill="#426482"/></svg>`,
        }));
        if (source === 'picker') {
            await page.route('**/recovery.html', async route => {
                const response = await route.fetch();
                await route.fulfill({response, body: (await response.text()).replace(
                    '<div class="post-editor-context-main">',
                    '<div class="post-editor-context-main"><button type="button" data-context-action="media">Image or audio</button>',
                )});
            });
        }
        await page.route('**/recovery-fixture.js', async route => {
            const response = await route.fetch();
            await route.fulfill({response, body: (await response.text())
                .replace('aiAltEnabled: false', 'aiAltEnabled: true')
                .replace("const body = creating ? '' : `<p>Server body ${revision}</p>`;",
                    'const body = creating ? ' + JSON.stringify(initial) + ' : `<p>Server body ${revision}</p>`;')});
        });
        await page.route('**/recovery-fixture.css', async route => {
            const response = await route.fetch();
            // Stop only the blink phase so a painted cursor has deterministic pixels.
            await route.fulfill({response, body: (await response.text())
                + '\n.has-leading-boundary-caret::before { animation: none !important; }'});
        });
        await page.route('**/image-optimizer/js/optimizer.js', route => route.fulfill({contentType: 'text/javascript', body:
            `export async function optimizeImage(blob) {
                await window.navigationOptimization;
                return {blob, extension: 'png', retina: false, width: 800, height: ${height}, displayWidth: 800, displayHeight: ${height}};
            }`,
        }));
        const requests = holdRequests(page, '**/_inplace/post/new');
        await requests.installed;
        await page.goto(origin + '/recovery.html');
        await page.evaluate(() => {
            window.navigationOptimization = new Promise(resolve => { window.finishNavigationOptimization = resolve; });
        });
        await page.getByRole('button', {name: 'New post', exact: true}).click();
        await page.keyboard.type('Navigation fixture');
        const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
        await (initial ? body.locator('p').first() : body).click();
        await insertImage(page, body, height, source);
        await body.locator('[data-media-stage="optimizing"]').waitFor();
        await body.locator('img').evaluate(image => image.complete && image.naturalWidth > 0
            ? undefined : new Promise(resolve => image.addEventListener('load', resolve, {once: true})));
        let upload, alt;
        const advance = async stage => {
            if (stage === 'optimizing') return;
            if (!upload) {
                await page.evaluate(() => window.finishNavigationOptimization());
                upload = await requests.next();
                assert.equal((await formData(upload)).get('inplace_action'), 'media');
            }
            if (stage === 'uploading') return;
            if (!alt) {
                await upload.fulfill({json: {
                    success: true, action: 'media', kind: 'image', media_id: 42,
                    url: '/navigation.svg', width: 800, height,
                }});
                alt = await requests.next();
                assert.equal((await formData(alt)).get('inplace_action'), 'ai_alt');
            }
            if (stage === 'alt') return;
            await alt.fulfill({json: {success: true, action: 'ai_alt', result: 'A generic test picture'}});
            await page.waitForFunction(() => {
                const state = window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
                return state.mediaUploads.size === 0 && state.aiAltTasks.size === 0;
            });
        };
        await run({page, body, requests, advance});
        assert.deepEqual(errors, []);
    } finally {
        await page.close();
    }
}

async function savedBody(page, body, requests) {
    const expected = await body.evaluate(body => window.editorTest.editableBodyHtml(
        window.editorTest.editorStates.get(body.closest('.post-card')),
    ));
    await page.waitForFunction(expected => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
        .list().some(copy => copy.snapshot.body === expected), expected);
    await page.getByRole('button', {name: 'Save', exact: true}).click();
    let request = await requests.next();
    let data = await formData(request);
    if (data.get('inplace_action') === 'media_redate') {
        await request.fulfill({json: {success: true, media: [{media_id: 42, url: '/navigation.svg'}]}});
        request = await requests.next();
        data = await formData(request);
    }
    assert.equal(data.get('inplace_action'), 'create');
    assert.equal(data.get('body'), expected, 'Saving must retain the exact editor/recovery content');
    await request.fulfill({json: {
        success: true, action: 'create', revision: 1, title: data.get('title'),
        body_html: `<div class="post body" data-post-inplace-body>${expected}</div>`,
        published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
        tags: [], scheduled: false, message: 'Saved',
        id: 10, url: '/created', action_url: '/_inplace/post/10', token: 'fixture',
    }});
    await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
    const saved = page.locator('.post-card[data-post-id="10"] [data-post-inplace-body]');
    assert.equal(await saved.locator('img').count(), 1);
    return {expected, saved};
}

export async function runMediaNavigationRegressions(browser, origin) {
    for (const {height, source} of [
        {height: 300, source: 'paste'},
        {height: 1200, source: 'paste'},
        {height: 1200, source: 'picker'},
    ]) {
        for (const stage of ['complete', 'optimizing', 'uploading', 'alt']) {
            for (const side of ['before', 'after']) {
                const label = `${browser.browserType().name()} ${source} ${height}px ${stage} ${side}`;
                await withNewPost(browser, origin, height, async ({page, body, requests, advance}) => {
                    await advance(stage);
                    const originalTop = await body.locator('img').evaluate(image => image.getBoundingClientRect().top + scrollY);
                    // No programmatic selection changes: start at the actual
                    // caret left by public insertion, then send real arrow keys.
                    await page.keyboard.press('ArrowDown');
                    await assertCaret(page, body, 'after', `${label} initial down`);
                    await page.keyboard.press('ArrowUp');
                    await assertCaret(page, body, 'before', `${label} up`);
                    for (let repeat = 0; repeat < 2; ++repeat) {
                        await page.keyboard.press('ArrowDown');
                        await assertCaret(page, body, 'after', `${label} repeated down`);
                        await page.keyboard.press('ArrowUp');
                        await assertCaret(page, body, 'before', `${label} repeated up`);
                    }
                    const navigatedTop = await body.locator('img').evaluate(image => image.getBoundingClientRect().top + scrollY);
                    assert.ok(Math.abs(navigatedTop - originalTop) < 1,
                        `${label}: moving the caret must not move the picture or add an empty line (${originalTop} -> ${navigatedTop}): ${await body.innerHTML()}`);
                    if (side === 'after') {
                        await page.keyboard.press('ArrowDown');
                        await assertCaret(page, body, 'after', `${label} down`);
                    }
                    await page.keyboard.type('During');
                    const selectionBeforeReply = await body.evaluate(body => {
                        window.navigationTypedNode = getSelection().anchorNode;
                        return Array.from(body.childNodes).filter(node => !(node instanceof Element) || !node.matches('.post-media-picture'))
                            .map(node => node.textContent).join('');
                    });
                    assert.equal(selectionBeforeReply, 'During', `${label}: native keys must insert all text immediately: ${await body.innerHTML()}`);
                    if (stage !== 'complete') await advance('complete');
                    assert.equal(await body.evaluate(() => getSelection().anchorNode === window.navigationTypedNode), true,
                        `${label}: finishing optimization/upload/AI must not replace or move the text caret`);
                    assert.equal(await body.evaluate(body => Array.from(body.childNodes)
                        .filter(node => !(node instanceof Element) || !node.matches('.post-media-picture')).map(node => node.textContent).join('')),
                    selectionBeforeReply);
                    await page.keyboard.type('After');
                    assert.equal(await body.locator('.post-media-picture').textContent(), '', 'Body typing must not enter the media wrapper/caption');
                    if (stage === 'alt') {
                        const html = () => body.evaluate(body => window.editorTest.editableBodyHtml(
                            window.editorTest.editorStates.get(body.closest('.post-card')),
                        ));
                        const beforeUndo = await html();
                        const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
                        await page.keyboard.press(`${modifier}+z`);
                        assert.equal(await body.locator('img').count(), 1, 'Undoing body typing must retain the completed image');
                        await page.keyboard.press(`${modifier}+Shift+z`);
                        assert.equal(await html(), beforeUndo, 'Redo must retain every typed character on the same side of the image');
                    }
                    const {expected, saved} = await savedBody(page, body, requests);
                    assert.ok(expected.includes('DuringAfter'), `${label}: saving must preserve every typed character: ${expected}`);
                    assert.ok(side === 'before'
                        ? expected.indexOf('DuringAfter') < expected.indexOf('<img')
                        : expected.indexOf('DuringAfter') > expected.indexOf('<img'),
                    `${label}: native typing and saving must agree with the caret side: ${expected}`);
                    assert.equal((await saved.textContent()).trim(), 'DuringAfter');
                    assert.equal(await saved.locator('p').count(), 1, 'Arrow navigation alone must not add saved empty lines');
                    console.log(`media navigation: ${label}`);
                }, '', source);
            }
        }
    }
    for (const side of ['before', 'after']) {
        await withNewPost(browser, origin, 1200, async ({page, body, requests, advance}) => {
            await advance('alt');
            await page.keyboard.press('ArrowUp');
            if (side === 'after') await page.keyboard.press('ArrowDown');
            await assertCaret(page, body, side, `completion ${side} before reply`);
            await advance('complete');
            await assertCaret(page, body, side, `completion ${side} after reply`);
            await page.keyboard.type('First input');
            const {expected, saved} = await savedBody(page, body, requests);
            assert.ok(expected.includes('First input'));
            assert.equal((await saved.textContent()).trim(), 'First input');
            assert.equal(await saved.locator('p').count(), 1);
        });
    }
    await withNewPost(browser, origin, 1200, async ({page, body, requests, advance}) => {
        await advance('alt');
        await page.keyboard.press('ArrowUp');
        await page.keyboard.press('ArrowDown');
        await advance('complete');
        const caption = body.locator('.post-caption');
        await caption.click();
        await page.keyboard.press('ArrowUp');
        await assertCaret(page, body, 'before', 'up from a tall image caption');
        await page.keyboard.press('ArrowDown');
        await assertCaret(page, body, 'after', 'down after leaving the caption');
        await caption.click();
        await page.keyboard.press('ArrowDown');
        await assertCaret(page, body, 'after', 'down from a tall image caption');
        await page.keyboard.type('Body after caption');
        const {saved} = await savedBody(page, body, requests);
        assert.equal((await saved.textContent()).trim(), 'Body after caption');
        assert.equal(await saved.locator('.post-caption').count(), 0, 'Empty caption navigation must not manufacture caption text');
    });
    await withNewPost(browser, origin, 300, async ({page, body, requests, advance}) => {
        await advance('complete');
        const prose = 'Text wrapping over several ordinary lines. '.repeat(6).trim();
        await page.keyboard.type(prose);
        const line = () => body.evaluate(body => {
            const paragraph = body.querySelector('p');
            const range = getSelection().getRangeAt(0);
            const text = document.createRange();
            text.selectNodeContents(paragraph);
            const rects = Array.from(text.getClientRects()).filter(rect => rect.height > 0 && rect.width > 0);
            const caret = Array.from(range.getClientRects()).find(rect => rect.height > 0);
            return {inside: paragraph.contains(range.startContainer), first: Math.min(...rects.map(rect => rect.top)),
                current: caret?.top, height: caret?.height};
        });
        const last = await line();
        assert.ok(last.current > last.first + last.height * 2, 'The native text must wrap over at least three visual lines');
        await page.keyboard.press('ArrowUp');
        let current = await line();
        assert.ok(current.inside && current.current < last.current && current.current > current.first,
            'Up in a wrapped paragraph must move one line, not skip the paragraph');
        for (let count = 0; current.current > current.first + current.height / 2 && count < 20; ++count) {
            await page.keyboard.press('ArrowUp');
            current = await line();
            assert.ok(current.inside, 'Native up must stay in text until reaching its first visual line');
        }
        assert.ok(current.current <= current.first + current.height / 2);
        await page.keyboard.press('ArrowUp');
        await assertCaret(page, body, 'before', 'up from the first text line');
        await page.keyboard.type('Before.');
        await page.keyboard.press('ArrowDown');
        await page.keyboard.type('Start ');
        assert.deepEqual(await body.locator(':scope > p').allTextContents(), ['Before.', 'Start ' + prose]);
        await page.keyboard.press('ArrowUp');
        await assertCaret(page, body, 'before', 'up with an existing preceding paragraph');
        await page.keyboard.type('Above.');
        const {saved} = await savedBody(page, body, requests);
        assert.deepEqual(await saved.locator(':scope > p').allTextContents(), ['Before.', 'Above.', 'Start ' + prose]);
        assert.equal(await saved.locator('p br').count(), 0, 'Arrow navigation must not manufacture paragraph/line breaks in prose');
    });
    for (const initial of ['<blockquote><p><br></p></blockquote>', '<ul><li><p><br></p></li></ul>']) {
        for (const side of ['before', 'after']) {
            await withNewPost(browser, origin, 300, async ({page, body, requests, advance}) => {
                await advance('alt');
                await page.keyboard.press('ArrowUp');
                await assertCaret(page, body, 'before', 'nested image up');
                if (side === 'after') {
                    await page.keyboard.press('ArrowDown');
                    await assertCaret(page, body, 'after', 'nested image down');
                }
                await page.keyboard.type('Nested text');
                await advance('complete');
                await page.keyboard.type(' retained');
                const {expected, saved} = await savedBody(page, body, requests);
                assert.ok(expected.includes('Nested text retained'), 'Nested image navigation must retain every typed character');
                assert.ok(side === 'before' ? expected.indexOf('Nested text') < expected.indexOf('<img')
                    : expected.indexOf('Nested text') > expected.indexOf('<img'));
                assert.equal(await saved.locator(initial.startsWith('<blockquote') ? 'blockquote' : 'ul > li').count(), 1);
                assert.equal((await saved.textContent()).trim(), 'Nested text retained');
            }, initial);
        }
    }
    console.log('media navigation: completion retains a visible boundary caret; multiline body arrows and all surrounding text survive saving');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const {chromium, firefox, webkit} = await import('playwright');
    const {createFixtureServer} = await import('./server.mjs');
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit].filter(engine => !process.env.EDITOR_TEST_BROWSER
            || engine.name() === process.env.EDITOR_TEST_BROWSER)) {
            const browser = await engine.launch();
            try {
                await runMediaNavigationRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            } finally { await browser.close(); }
        }
    } finally {
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
}
