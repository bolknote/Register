import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {pathToFileURL} from 'node:url';
import {execFileSync} from 'node:child_process';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {formData} from './save-tests.mjs';
import {pixels} from './media-navigation-tests.mjs';

const fixture = new URL('../../_resources/image-optimizer/animated.gif', import.meta.url);

async function assertAnimation(image) {
    await image.scrollIntoViewIfNeeded();
    const colors = new Set();
    const deadline = Date.now() + 5000;
    while (colors.size < 2 && Date.now() < deadline) {
        const png = await image.screenshot();
        const rendered = pixels(png);
        const width = png.readUInt32BE(16), height = png.readUInt32BE(20);
        const offset = (Math.floor(height / 2) * width + Math.floor(width / 2)) * rendered.channels;
        const [red, green, blue] = rendered.data.subarray(offset, offset + 3);
        if (red === 224 && green === 48 && blue === 80) colors.add('red');
        if (red === 48 && green === 64 && blue === 224) colors.add('blue');
    }
    assert.equal(colors.size, 2, 'The displayed GIF must actually animate between both authored frames');
}

// Runs unchanged against the fixture server and the real PHP/SQLite site.
export async function runGifPostWorkflow(page, origin) {
    const bytes = await readFile(fixture);
    const label = page.context().browser().browserType().name();
    await page.goto(origin + '/');
    await page.locator('.post-create-start').click();
    const creating = page.locator('[data-post-creating]');
    await creating.locator('[data-post-inplace-title]').fill('Animated image ' + label);
    const body = creating.locator('[data-post-inplace-body]');
    await body.click();
    await page.keyboard.type('Text before the animated image.');
    await body.press('Shift+F10');
    const [chooser] = await Promise.all([
        page.waitForEvent('filechooser'),
        page.locator('.post-editor-context-menu [data-context-action="media"]').click(),
    ]);
    const uploaded = page.waitForResponse(response => response.request().method() === 'POST'
        && response.url().endsWith('/_inplace/post/new') && response.request().postDataBuffer()?.includes(Buffer.from('name="media";')));
    await chooser.setFiles({name: 'animated.gif', mimeType: 'image/gif', buffer: bytes});
    const media = creating.locator('.post-media-picture:not(.is-processing)');
    const [response] = await Promise.all([uploaded, media.waitFor().catch(async error => {
        throw new Error(await creating.locator('.post-inplace-edit-error').textContent(), {cause: error});
    })]);
    assert.equal(response.status(), 200, 'The server must accept GIF uploads');
    const upload = await response.json();
    assert.match(upload.url, /\.gif(?:\?|$)/u);
    const stored = await page.evaluate(async url => Array.from(new Uint8Array(await (await fetch(url)).arrayBuffer())),
        new URL(upload.url, origin).href);
    assert.deepEqual(Buffer.from(stored), bytes,
        'Uploaded bytes retain every frame, frame delay, disposal method and loop count');
    await assertAnimation(media.locator('img'));
    await media.locator('.post-caption').click();
    await page.keyboard.type('Animated caption.');
    await page.keyboard.press('Enter');
    await page.keyboard.type('Text after the animated image.');
    for (let save = 0; save < 2; save++) {
        const editing = page.locator('.post-card.is-editing');
        await editing.locator('.post-edit-save').click();
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        const card = page.locator(`.post-card:has([data-post-inplace-title]:text-is("Animated image ${label}"))`);
        const image = card.locator('[data-post-inplace-body] img');
        await image.waitFor();
        await assertAnimation(image);
        for (const text of ['Text before the animated image.', 'Animated caption.', 'Text after the animated image.']) {
            assert.ok((await card.locator('[data-post-inplace-body]').textContent()).includes(text), 'Saving preserves ' + text);
        }
        if (save === 0) {
            await card.locator('.post-edit-start').focus();
            await card.locator('.post-edit-start').press('Enter');
            await page.waitForFunction(() => document.querySelector('.post-card.is-editing'));
        } else {
            const id = await card.getAttribute('data-post-id');
            await page.reload();
            const reloaded = page.locator(`.post-card[data-post-id="${id}"] [data-post-inplace-body]`);
            await assertAnimation(reloaded.locator('img'));
            for (const text of ['Text before the animated image.', 'Animated caption.', 'Text after the animated image.']) {
                assert.ok((await reloaded.textContent()).includes(text), 'Reloading preserves ' + text);
            }
            console.log(`${label}: GIF file chooser → upload → two saves → reload preserves animation, caption and surrounding text`);
            return Number(id);
        }
    }
}

export async function runGifUploadRegressions(browser, origin) {
    const page = await browser.newPage({viewport: {width: 1280, height: 900}});
    page.setDefaultTimeout(8000);
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    let savedBody = null;
    let savedTitle = null;
    let revision = 0;
    const bytes = await readFile(fixture);
    try {
        await page.route(origin + '/', async route => {
            const response = await page.request.get(origin + '/recovery.html');
            await route.fulfill({response, body: (await response.text()).replace('<div class="post-editor-context-main">',
                '<div class="post-editor-context-main"><button type="button" data-context-action="media">Image</button>')});
        });
        await page.route('**/image-optimizer/**', async route => {
            const path = new URL(route.request().url()).pathname;
            const source = '_assets/register' + path;
            const content = process.env.GIF_TEST_REVISION && path.endsWith('/optimizer.js')
                ? execFileSync('git', ['show', `${process.env.GIF_TEST_REVISION}:${source}`])
                : await readFile(new URL('../../../' + source, import.meta.url));
            await route.fulfill({contentType: 'text/javascript', body: content});
        });
        await page.route('**/animated-upload.gif', route => route.fulfill({contentType: 'image/gif', body: bytes}));
        await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        await page.route('**/_inplace/post/*', async route => {
            const data = await formData(route);
            if (data.get('inplace_action') === 'media_redate') {
                await route.fulfill({json: {success: true, action: 'media_redate', media: []}});
                return;
            }
            if (data.get('inplace_action') === 'media') {
                const media = data.get('media');
                assert.equal(media.type, 'image/gif');
                assert.match(media.name, /\.gif$/u);
                // WebKit's interception omits file bodies from multipart data.
                // The real PHP workflow verifies the actual stored file bytes.
                assert.equal(data.get('media_width'), '96');
                assert.equal(data.get('media_height'), '48');
                await route.fulfill({json: {success: true, action: 'media', kind: 'image', media_id: 41,
                    url: '/animated-upload.gif', width: 96, height: 48}});
                return;
            }
            savedBody = data.get('body');
            savedTitle = data.get('title');
            revision++;
            await route.fulfill({json: {success: true, action: revision === 1 ? 'create' : 'edit',
                id: 10, url: '/created', action_url: '/_inplace/post/10', token: 'fixture', revision,
                title: savedTitle, body_html: `<div class="post body" data-post-inplace-body>${savedBody}</div>`,
                tags: [], published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
                message: 'Saved', scheduled: false}});
        });
        await page.route('**/recovery-fixture.js', async route => {
            const source = await readFile(new URL('./recovery-fixture.js', import.meta.url), 'utf8');
            await route.fulfill({contentType: 'text/javascript', body: savedBody === null ? source : source.replace(
                'if (!feed.childElementCount) feed.append(post(false));',
                `if (!feed.childElementCount) { const card = post(false); card.dataset.postId = '10';
                    card.querySelector('[data-post-inplace-title]').textContent = ${JSON.stringify(savedTitle)};
                    card.querySelector('[data-post-inplace-body]').innerHTML = ${JSON.stringify(savedBody)}; feed.append(card); }`,
            )});
        });
        await runGifPostWorkflow(page, origin);
        for (const [name, width, mimeType] of [
            ['animated.gif', 96, 'image/gif'], ['static.gif', 96, 'image/gif'],
            ['animated-wide.gif', 2400, 'image/gif'], ['animated.gif', 96, ''],
        ]) {
            const source = await readFile(new URL('../../_resources/image-optimizer/' + name, import.meta.url));
            const result = await page.evaluate(async ({bytes, name, mimeType}) => {
                const {optimizeImage} = await import('/image-optimizer/js/optimizer.js');
                const result = await optimizeImage(new File([new Uint8Array(bytes)], name, {type: mimeType}));
                return {...result, blob: undefined, blobType: result.blob.type,
                    bytes: Array.from(new Uint8Array(await result.blob.arrayBuffer()))};
            }, {bytes: [...source], name, mimeType});
            assert.deepEqual(Buffer.from(result.bytes), source, name + ': all source bytes survive processing');
            assert.equal(result.blobType, 'image/gif');
            assert.equal(result.extension, 'gif');
            assert.equal(result.width, width);
            assert.equal(result.height, 48);
            assert.equal(result.displayWidth, width);
            assert.equal(result.displayHeight, 48);
            assert.equal(result.retina, false);
            assert.equal(result.resized, false);
        }
        const failures = await page.evaluate(async bytes => {
            const {optimizeImage} = await import('/image-optimizer/js/optimizer.js');
            const gif = new File([new Uint8Array(bytes)], 'animated.gif', {type: 'image/gif'});
            const errors = [];
            for (const stage of ['before', 'during', 'invalid']) {
                const controller = new AbortController();
                if (stage === 'before') controller.abort();
                try {
                    await optimizeImage(stage === 'invalid' ? new Blob(['not an image'], {type: 'image/gif'}) : gif, {
                        signal: controller.signal,
                        onProgress() { if (stage === 'during') controller.abort(); },
                    });
                    errors.push(null);
                } catch (error) { errors.push({name: error.name, message: error.message}); }
            }
            return errors;
        }, [...bytes]);
        assert.equal(failures[0]?.name, 'AbortError');
        assert.equal(failures[1]?.name, 'AbortError');
        assert.match(failures[2]?.message || '', /GIF file is invalid/u);
        assert.deepEqual(errors, []);
    } catch (error) {
        console.error({pageErrors: errors, uploadError: await page.locator('.post-card.is-editing .post-inplace-edit-error').textContent().catch(() => '')});
        throw error;
    } finally { await page.close(); }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit]) {
            if (process.env.EDITOR_TEST_BROWSER && process.env.EDITOR_TEST_BROWSER !== engine.name()) continue;
            const browser = await engine.launch();
            try { await runGifUploadRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
