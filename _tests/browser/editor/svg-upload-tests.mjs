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
        const bytes = Buffer.from(svg('viewBox="0 0 640 320"'));
        let savedBody = null;
        let uploads = 0;
        try {
            await page.route('**/recovery.html', async route => {
                const response = await route.fetch();
                await route.fulfill({response, body: (await response.text()).replace('<div class="post-editor-context-main">',
                    '<div class="post-editor-context-main"><button type="button" data-context-action="media">Image</button>')});
            });
            await page.route('**/image-optimizer/**', async route => {
                const path = new URL(route.request().url()).pathname;
                await route.fulfill({contentType: 'text/javascript', body: await readFile(new URL('../../../_assets/register' + path, import.meta.url))});
            });
            await page.route('**/vector-upload.svg', route => route.fulfill({contentType: 'image/svg+xml', body: bytes}));
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            await page.route('**/_inplace/post/*', async route => {
                const data = await formData(route);
                if (data.get('inplace_action') === 'media') {
                    uploads++;
                    const file = data.get('media');
                    assert.equal(file.type, 'image/svg+xml');
                    assert.match(file.name, /\.svg$/u);
                    if (browser.browserType().name() !== 'webkit') {
                        assert.deepEqual(Buffer.from(await file.arrayBuffer()), bytes, 'Upload must retain the complete authored SVG');
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
                transfer.items.add(new File([source], 'drawing.svg', {type: 'image/svg+xml'}));
                if (mode === 'drop') {
                    const bounds = body.getBoundingClientRect();
                    body.dispatchEvent(new DragEvent('drop', {bubbles: true, cancelable: true, dataTransfer: transfer,
                        clientX: bounds.right - 3, clientY: bounds.bottom - 2}));
                } else {
                    const event = new Event('paste', {bubbles: true, cancelable: true});
                    Object.defineProperty(event, 'clipboardData', {value: transfer}); body.dispatchEvent(event);
                }
            }, {mode, source: bytes.toString()});
            const image = body.locator('.post-media-picture:not(.is-processing) img');
            await image.waitFor();
            await page.waitForFunction(() => document.querySelector('img[src="/vector-upload.svg"]')?.naturalWidth > 0);
            const png = await image.screenshot();
            const rendered = pixels(png);
            const offset = (Math.floor(png.readUInt32BE(20) / 2) * png.readUInt32BE(16)
                + Math.floor(png.readUInt32BE(16) / 2)) * rendered.channels;
            assert.deepEqual(Array.from(rendered.data.subarray(offset, offset + 3)), [12, 34, 56], 'Authored SVG styles render as saved');
            assert.equal(await page.evaluate(() => window.svgAuthoredCode), undefined, 'SVG remains an image during insertion');
            await editing.getByRole('button', {name: 'Save', exact: true}).click();
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            assert.match(savedBody, /src="\/vector-upload\.svg"/u);
            assert.doesNotMatch(savedBody, /blob:|data:image\/|is-processing/u);
            await page.reload();
            await page.waitForFunction(() => document.querySelector('img[src="/vector-upload.svg"]')?.naturalWidth > 0);
            assert.equal(uploads, 1);
            assert.deepEqual(errors, []);
            console.log(`${browser.browserType().name()}: SVG ${mode} → original upload → styled image → save → reload`);

            if (mode === 'drop') {
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
        } finally { await page.close(); }
    }
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
