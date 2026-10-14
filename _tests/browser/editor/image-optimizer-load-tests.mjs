import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {formData} from './save-tests.mjs';

const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==', 'base64');
const optimizer = `import {ready} from '/optimizer-load-dependency.js';
    export async function optimizeImage(blob, {signal} = {}) {
        window.top.optimizerRuns++;
        if (signal?.aborted) throw new DOMException('Image cancelled', 'AbortError');
        if (!(blob instanceof Blob)) throw new Error('Wrong Blob realm');
        if (!ready) throw new Error('Invalid dependency');
        return {blob, extension: 'png', retina: false, width: 1, height: 1, displayWidth: 1, displayHeight: 1};
    }`;

async function chooseImage(page, body) {
    await body.press('ControlOrMeta+End');
    await body.press('Shift+F10');
    const chooser = page.waitForEvent('filechooser');
    await page.locator('.post-editor-context-menu [data-context-action="media"]').click();
    await (await chooser).setFiles({name: 'load-test.png', mimeType: 'image/png', buffer: png});
}

export async function runImageOptimizerLoadRegressions(browser, origin) {
    for (const failing of ['entry', 'dependency', 'persistent-entry', 'persistent-dependency', 'loader', 'timeout', 'cancel']) {
        const context = await browser.newContext({viewport: {width: 1280, height: 900}});
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        let entries = 0, dependencies = 0, uploads = 0, loaderRequests = 0;
        let unavailable = failing.startsWith('persistent-') || ['loader', 'timeout', 'cancel'].includes(failing);
        const held = [];
        let signalHeld;
        const holdStarted = new Promise(resolve => { signalHeld = resolve; });
        const waitForHeld = async () => {
            let timer;
            try {
                await Promise.race([holdStarted, new Promise((_, reject) => {
                    timer = setTimeout(() => reject(new Error('Missing held optimizer request')), 5000);
                })]);
            } finally { clearTimeout(timer); }
        };
        try {
            await page.addInitScript(() => {
                if (window === window.top) {
                    window.optimizerPolicyViolations = [];
                    window.optimizerRuns = 0;
                }
                document.addEventListener('securitypolicyviolation', event => {
                    window.top.optimizerPolicyViolations.push(event.violatedDirective);
                });
            });
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            await page.route('**/recovery.html*', async route => {
                const response = await route.fetch();
                await route.fulfill({response, body: (await response.text()).replace(
                    '<div class="post-editor-context-main">',
                    '<div class="post-editor-context-main"><button type="button" data-context-action="media">Image or audio</button>',
                )});
            });
            await page.route('**/image-optimizer/js/optimizer.js*', async route => {
                entries++;
                if (failing === 'cancel' && entries === 1) { await route.abort('connectionreset'); return; }
                if (['cancel', 'timeout'].includes(failing) && unavailable) {
                    held.push(route);
                    signalHeld();
                    return;
                }
                if ((failing === 'entry' && entries === 1) || (failing === 'loader' && entries === 1)
                    || (failing === 'persistent-entry' && unavailable)) await route.abort('connectionreset');
                else await route.fulfill({contentType: 'text/javascript', body: optimizer});
            });
            await page.route('**/image-optimizer/js/optimizer-loader.js.asset*', async route => {
                loaderRequests++;
                if (failing === 'loader' && unavailable) await route.abort('connectionreset');
                else await route.fallback();
            });
            await page.route('**/optimizer-load-dependency.js*', async route => {
                dependencies++;
                if ((failing === 'dependency' && dependencies === 1)
                    || (failing === 'persistent-dependency' && unavailable)) await route.abort('connectionreset');
                else await route.fulfill({contentType: 'text/javascript', body: 'export const ready = true;'});
            });
            await page.route('**/load-test.png', route => route.fulfill({contentType: 'image/png', body: png}));
            await page.route('**/_inplace/post/9', async route => {
                const data = await formData(route);
                assert.equal(data.get('inplace_action'), 'media');
                uploads++;
                await route.fulfill({json: {success: true, action: 'media', kind: 'image', media_id: 41 + uploads,
                    url: '/load-test.png', width: 1, height: 1}});
            });
            await page.goto(origin + '/recovery.html' + (failing === 'timeout' ? '?timeout=200' : ''));
            const originalUrl = page.url();
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            assert.equal(entries, 0, 'Opening the editor must not load the optimizer');
            const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
            const authorText = () => body.evaluate(element => {
                const clone = element.cloneNode(true);
                clone.querySelectorAll('.post-media-picture').forEach(picture => picture.remove());
                return clone.textContent.replace(/\u00a0/gu, ' ');
            });
            await body.click();
            await body.press('ControlOrMeta+End');
            await page.keyboard.type(' Text retained across a failed image import.');
            let originalText = await authorText();
            const settled = () => page.waitForFunction(() => {
                const card = document.querySelector('.post-card.is-editing');
                return window.editorTest.editorStates.get(card).mediaUploads.size === 0;
            });
            await chooseImage(page, body);
            if (failing === 'cancel') {
                await waitForHeld();
                await page.waitForFunction(() => document.querySelector('iframe'));
                await page.waitForFunction(() => document.querySelector('[data-media-stage="optimizing"]'));
                await page.getByRole('button', {name: 'Cancel', exact: true}).click();
                await page.getByRole('button', {name: 'Discard changes', exact: true}).click();
                await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
                for (const route of held) await route.fulfill({contentType: 'text/javascript', body: optimizer});
                await page.waitForFunction(() => optimizerRuns === 1);
                assert.equal(uploads, 0, 'Late iframe completion cannot upload a cancelled image');
                assert.equal(await page.locator('.post-card[data-post-id="9"] [data-post-inplace-body]').textContent(), 'Server body 1');
                assert.equal(await page.locator('.post-inplace-edit-error:visible').count(), 0, 'A cross-realm AbortError is handled as cancellation');
                assert.equal(page.url(), originalUrl);
                assert.deepEqual(await page.evaluate(() => optimizerPolicyViolations), []);
                assert.deepEqual(errors, []);
                console.log(`${browser.browserType().name()}: cancelled iframe optimization cannot upload late or show an error`);
                continue;
            }
            if (failing === 'timeout') {
                await waitForHeld();
                await page.waitForFunction(() => document.querySelector('[data-media-stage="optimizing"]'));
                await body.press('ControlOrMeta+End');
                await page.keyboard.type(' Typed while loading.');
                originalText = await authorText();
                assert.ok(originalText.includes('Typed while loading.'), 'Typing stays outside the upload placeholder');
            }
            await settled();
            assert.equal(await authorText(), originalText, 'A failed import keeps the entire post text');
            if (unavailable) {
                assert.equal(uploads, 0, 'Unavailable processing must not upload the original unoptimized image');
                assert.equal(await body.locator('.is-processing').count(), 0, 'A failed import leaves no stuck placeholder');
                assert.equal(await page.locator('iframe').count(), 0, 'Failed and timed-out contexts are disposed');
                const message = await page.locator('.post-card.is-editing .post-inplace-edit-error').textContent();
                assert.ok(message.includes('your text remains in the editor') && message.includes('load-test.png'));
                assert.doesNotMatch(message, /Failed to fetch|https?:/u, 'No opaque dynamic-import URL is shown to the author');
                assert.ok(entries <= 2 && loaderRequests <= 1, 'Automatic attempts are bounded');
                await body.press('ControlOrMeta+End');
                await page.keyboard.type(' Typed after failure.');
                originalText = await authorText();
                for (const route of held) await route.abort('connectionreset').catch(() => {});
                unavailable = false;
                await chooseImage(page, body);
                await settled();
                assert.equal(uploads, 1, 'An explicit retry succeeds in the same document after recovery');
            } else {
                assert.equal(uploads, 1, 'A transient import failure is retried automatically before uploading');
            }
            const loadedEntries = entries;
            await chooseImage(page, body);
            await settled();
            const error = await page.locator('.post-card.is-editing .post-inplace-edit-error').textContent();
            assert.equal(uploads, 2,
                `Retrying a ${failing} failure must load again without reloading the page; ${JSON.stringify({entries, dependencies, error})}`);
            assert.equal(entries, loadedEntries, 'The successfully loaded optimizer is reused for the next image');
            assert.equal(await authorText(), originalText, 'All native text survives module failure and subsequent uploads');
            assert.equal(page.url(), originalUrl, 'No editor/page navigation is used for module recovery');
            assert.deepEqual(await page.evaluate(() => optimizerPolicyViolations), [], 'Recovery retains the existing CSP');
            assert.deepEqual(errors, []);
        } finally { await context.close(); }
        console.log(`${browser.browserType().name()}: optimizer ${failing} failure retries, retains text and CSP without page reload`);
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit]) {
            if (process.env.EDITOR_TEST_BROWSER && process.env.EDITOR_TEST_BROWSER !== engine.name()) continue;
            const browser = await engine.launch();
            try { await runImageOptimizerLoadRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
