import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {formData} from './save-tests.mjs';
import {pixels} from './media-navigation-tests.mjs';

const svg = attributes => `<svg xmlns="http://www.w3.org/2000/svg" ${attributes} onload="window.svgAuthoredCode=true">`
    + '<style>.panel{fill:rgb(12,34,56)}text{font:20px monospace;fill:white}</style>'
    + '<script>window.svgAuthoredCode=true;</script><rect class="panel" width="100%" height="100%"/>'
    + '<text x="10" y="30">Authored vector</text></svg>';

export async function runSvgUploadRegressions(browser, origin) {
    for (const mode of ['drop', 'paste', 'chooser']) {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        page.setDefaultTimeout(10000);
        const manifest = '<proof:manifest xmlns:proof="http://c2pa.org/manifest">QUJDRA==</proof:manifest>';
        const drawing = svg('width="100%" viewBox="0 0 640 320"').replace('<style>',
            '<metadata><rights xmlns="urn:test:licence">CC BY</rights></metadata><style>');
        const bytes = Buffer.from(mode === 'drop' ? drawing.replace('<metadata>', '<metadata>' + manifest) : drawing);
        let storedBytes = Buffer.from(drawing);
        const optimizer = Promise.withResolvers();
        let savedBody = null;
        let uploads = 0;
        try {
            await page.route('**/recovery.html', async route => {
                const response = await route.fetch();
                await route.fulfill({response, body: (await response.text()).replace('<div class="post-editor-context-main">',
                    '<div class="post-editor-context-main"><button type="button" data-context-action="media">Image</button>')});
            });
            await page.route('**/image-optimizer/**', async route => {
                await optimizer.promise;
                const path = new URL(route.request().url()).pathname;
                await route.fulfill({contentType: 'text/javascript', body: await readFile(new URL('../../../_assets/register' + path, import.meta.url))});
            });
            await page.route('**/vector-upload.svg', route => route.fulfill({contentType: 'image/svg+xml', body: storedBytes}));
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            await page.route('**/_inplace/post/*', async route => {
                const data = await formData(route);
                if (data.get('inplace_action') === 'media') {
                    uploads++;
                    const file = data.get('media');
                    assert.equal(file.type, 'image/svg+xml');
                    assert.match(file.name, /\.svg$/u);
                    if (browser.browserType().name() !== 'webkit') {
                        storedBytes = Buffer.from(await file.arrayBuffer());
                        assert.equal(storedBytes.toString(), drawing, 'Keep authored code, styles and licence when removing C2PA');
                    }
                    assert.equal(data.get('media_width'), '640');
                    assert.equal(data.get('media_height'), '320');
                    assert.equal(data.get('media_retina'), '0');
                    await route.fulfill({json: {success: true, action: 'media', kind: 'image', media_id: 41,
                        url: '/vector-upload.svg', width: 640, height: 320}});
                } else if (data.get('inplace_action') === 'edit') {
                    savedBody = data.get('body');
                    await route.fulfill({json: {success: true, action: 'edit', title: data.get('title'), revision: 2,
                        body_html: `<div class="post body" data-post-inplace-body>${savedBody}</div>`,
                        published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September', tags: [], scheduled: false}});
                } else await route.fulfill({json: {success: true, media: []}});
            });
            await page.route('**/recovery-fixture.js', async route => {
                const source = await readFile(new URL('./recovery-fixture.js', import.meta.url), 'utf8');
                await route.fulfill({contentType: 'text/javascript', body: savedBody === null ? source : source.replace(
                    'if (!feed.childElementCount) feed.append(post(false));',
                    `if (!feed.childElementCount) { const card = post(false); card.querySelector('[data-post-inplace-body]').innerHTML = ${JSON.stringify(savedBody)}; feed.append(card); }`,
                )});
            });
            await page.goto(origin + '/recovery.html');
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            const editing = page.locator('.post-card.is-editing');
            const body = editing.locator('[data-post-inplace-body]');
            if (mode === 'chooser') {
                await body.click({button: 'right'});
                const [chooser] = await Promise.all([page.waitForEvent('filechooser'),
                    page.locator('.post-editor-context-menu [data-context-action="media"]').click()]);
                await chooser.setFiles({name: 'drawing.svg', mimeType: 'image/svg+xml', buffer: bytes});
            } else await body.evaluate((body, {mode, source}) => {
                body.focus();
                const range = document.createRange();
                range.selectNodeContents(body); range.collapse(false);
                getSelection().removeAllRanges(); getSelection().addRange(range);
                const transfer = new DataTransfer();
                transfer.items.add(new File([source], 'drawing.svg', {type: mode === 'drop' ? 'text/xml' : 'image/svg+xml'}));
                if (mode === 'drop') {
                    const bounds = body.getBoundingClientRect();
                    body.dispatchEvent(new DragEvent('drop', {bubbles: true, cancelable: true, dataTransfer: transfer,
                        clientX: bounds.right - 3, clientY: bounds.bottom - 2}));
                } else {
                    const event = new Event('paste', {bubbles: true, cancelable: true});
                    Object.defineProperty(event, 'clipboardData', {value: transfer}); body.dispatchEvent(event);
                }
            }, {mode, source: bytes.toString()});
            const pending = body.locator('.post-media-picture.is-processing img');
            await pending.waitFor({state: 'attached'});
            assert.ok(await body.locator('.is-processing [role="status"]').isVisible());
            await pending.evaluate(image => image.decode());
            await assertColumnWidth(pending);
            assert.equal(uploads, 0, 'The local preview must be visible before processing starts');
            optimizer.resolve();
            const image = body.locator('.post-media-picture:not(.is-processing) img');
            await image.waitFor();
            await page.waitForFunction(() => document.querySelector('img[src="/vector-upload.svg"]')?.naturalWidth > 0);
            await assertColumnWidth(image);
            const png = await image.screenshot();
            const rendered = pixels(png);
            const offset = (Math.floor(png.readUInt32BE(20) / 2) * png.readUInt32BE(16)
                + Math.floor(png.readUInt32BE(16) / 2)) * rendered.channels;
            assert.deepEqual(Array.from(rendered.data.subarray(offset, offset + 3)), [12, 34, 56], 'Authored SVG styles render as saved');
            assert.equal(await page.evaluate(() => window.svgAuthoredCode), undefined, 'SVG remains an image during insertion');
            if (mode === 'drop') {
                await image.click({button: 'right'});
                const menu = page.locator('.post-editor-context-menu');
                assert.equal(await menu.locator('[data-context-image-retina]').isDisabled(), true);
                assert.equal(await menu.locator('[data-context-image-dimensions]').textContent(),
                    await image.evaluate(image => `${image.width} × ${image.height}`));
                await page.keyboard.press('Escape');
            }
            await editing.getByRole('button', {name: 'Save', exact: true}).click();
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            assert.match(savedBody, /src="\/vector-upload\.svg"/u);
            assert.doesNotMatch(savedBody, /blob:|data:image\/|is-processing/u);
            await page.reload();
            await page.waitForFunction(() => document.querySelector('img[src="/vector-upload.svg"]')?.naturalWidth > 0);
            const published = page.locator('img[src="/vector-upload.svg"]');
            await assertColumnWidth(published);
            if (mode === 'drop') {
                // Saved by an earlier editor, before local previews carried a vector class.
                await published.evaluate(image => image.classList.remove('post-media-vector'));
                await assertColumnWidth(published);
            }
            await page.setViewportSize({width: 430, height: 800});
            await assertColumnWidth(published);
            assert.equal(uploads, 1);
            assert.deepEqual(errors, []);
            console.log(`${browser.browserType().name()}: SVG ${mode} → local preview → vector upload → responsive save and reload`);

            if (mode === 'drop') {
                await checkSvgCompaction(page, manifest);
                for (const [attributes, width, height] of [
                    ['width="320" height="160"', 320, 160], ['viewBox="0 0 2400 1200"', 2400, 1200],
                    ['width="320" viewBox="0 0 640 320"', 320, 160], ['height="160" viewBox="0 0 640 320"', 320, 160],
                    ['width="2in" height="25.4mm"', 192, 96], ['width="6pc" height="101.6q"', 96, 96],
                    ['width="320.5px" height="160.5px"', 321, 161], ['', 300, 150],
                ]) {
                    const source = svg(attributes);
                    const result = await page.evaluate(async source => {
                        const {optimizeImage} = await import('/image-optimizer/js/optimizer.js');
                        const result = await optimizeImage(new File([source], 'drawing.svg', {type: ''}));
                        return {width: result.width, height: result.height, retina: result.retina,
                            extension: result.extension, type: result.blob.type, bytes: await result.blob.text()};
                    }, source);
                    assert.deepEqual(result, {width, height, retina: false, extension: 'svg', type: 'image/svg+xml', bytes: source});
                }
                const invalid = await page.evaluate(async () => {
                    const {optimizeImage} = await import('/image-optimizer/js/optimizer.js');
                    const results = [];
                    for (const source of ['<svg xmlns="http://www.w3.org/2000/svg"><g></svg>', '<html/>']) {
                        try { await optimizeImage(new File([source], 'drawing.svg', {type: 'image/svg+xml'})); results.push(false); }
                        catch (_) { results.push(true); }
                    }
                    return results;
                });
                assert.deepEqual(invalid, [true, true]);
                const encoded = Buffer.concat([Buffer.from([0xff, 0xfe]), Buffer.from('<?xml version="1.0" encoding="UTF-16"?>' + svg('width="320" height="160"'), 'utf16le')]);
                const utf16 = await page.evaluate(async bytes => {
                    const {optimizeImage} = await import('/image-optimizer/js/optimizer.js');
                    const result = await optimizeImage(new File([new Uint8Array(bytes)], 'drawing.svg', {type: 'image/svg+xml'}));
                    return {width: result.width, height: result.height, bytes: Array.from(new Uint8Array(await result.blob.arrayBuffer()))};
                }, [...encoded]);
                assert.deepEqual(utf16, {width: 320, height: 160, bytes: [...encoded]});
            }
        } finally { optimizer.resolve(); await page.close(); }
    }
}

async function assertColumnWidth(image) {
    const [width, height, column] = await image.evaluate(image => [
        image.getBoundingClientRect().width, image.getBoundingClientRect().height,
        image.closest('[data-post-inplace-body]').getBoundingClientRect().width,
    ]);
    assert.ok(column > 0 && Math.abs(width - column) < 1, `SVG width ${width}, column ${column}`);
    assert.ok(Math.abs(height - width / 2) < 1, `SVG aspect ratio at ${width} × ${height}`);
}

async function checkSvgCompaction(page, manifest) {
    const opaqueStyle = "--label:'hint;fill:rgb(0,0,0);';color:rgb(1,2,3)";
    const formatted = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50">\n'
        + '  <title>Drawing</title>\n  <!--! retain licence -->\n  <g>\n'
        + '    <path id="line" style="fill:rgb(102,153,204);stroke:rgb(0,0,0)" d="M 1.000001 1 L 97.1234567 2 L 90 45 Z"/>\n'
        + '    <g xml:space="preserve">\n      <desc style="' + opaqueStyle + '">notes</desc>\n    </g>\n'
        + '    <text y="20" xml:space="preserve">two  <tspan> words </tspan></text>\n  </g>\n</svg>';
    const result = await page.evaluate(async source => {
        const {optimizeImage} = await import('/image-optimizer/js/optimizer.js');
        const file = new File([source], 'drawing.svg', {type: 'image/svg+xml'});
        const {blob} = await optimizeImage(file);
        async function render(blob, width) {
            const url = URL.createObjectURL(blob);
            try {
                const image = new Image(width, width / 2);
                image.src = url;
                await image.decode();
                const canvas = document.createElement('canvas');
                canvas.width = width; canvas.height = width / 2;
                const context = canvas.getContext('2d');
                context.drawImage(image, 0, 0, canvas.width, canvas.height);
                return context.getImageData(0, 0, canvas.width, canvas.height).data;
            } finally { URL.revokeObjectURL(url); }
        }
        const identical = [];
        for (const width of [218, 1000]) {
            const before = await render(file, width);
            const after = await render(blob, width);
            identical.push(before.some(value => value > 0) && before.every((value, i) => value === after[i]));
        }
        return {source: await blob.text(), size: blob.size, identical};
    }, formatted);
    assert.ok(result.size < Buffer.byteLength(formatted));
    assert.match(result.source, /d="M1\.000001 1L97\.1234567 2L90 45Z"/u);
    assert.ok(result.source.includes('style="fill:#69c;stroke:#000"'));
    assert.ok(result.source.includes('<g xml:space="preserve">\n      <desc'));
    assert.ok(result.source.includes(opaqueStyle));
    assert.match(result.source, /two  <tspan> words <\/tspan>/u);
    assert.match(result.source, /<!--! retain licence -->/u);
    assert.deepEqual(result.identical, [true, true], 'Compaction must retain every rendered pixel at both sizes');

    const doctype = '<!DOCTYPE svg [<!-- [ \" ] --> <!ENTITY label "saved [label]">]>';
    const encoded = Buffer.from('<?xml version="1.0" encoding="UTF-16"?>' + doctype
        + '<svg xmlns="http://www.w3.org/2000/svg"><metadata>' + manifest
        + '</metadata><title>&label;</title></svg>', 'utf16le');
    const decoded = await page.evaluate(async bytes => {
        const {optimizeImage} = await import('/image-optimizer/js/optimizer.js');
        const {blob} = await optimizeImage(new File([new Uint8Array(bytes)], 'drawing.svg'));
        const source = await blob.text();
        const doc = new DOMParser().parseFromString(source, 'image/svg+xml');
        return {source, title: doc.querySelector('title')?.textContent};
    }, [...encoded]);
    assert.ok(decoded.source.includes(doctype));
    assert.match(decoded.source, /encoding="UTF-8"/u);
    assert.doesNotMatch(decoded.source, /proof:manifest/u);
    assert.equal(decoded.title, 'saved [label]');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit]) {
            if (process.env.EDITOR_TEST_BROWSER && process.env.EDITOR_TEST_BROWSER !== engine.name()) continue;
            const browser = await engine.launch();
            try { await runSvgUploadRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
