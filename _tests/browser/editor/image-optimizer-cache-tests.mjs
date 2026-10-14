import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {mkdtemp, mkdir, readFile, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {buildAssets, assetDirectories, manifestPath} from '../../../tools/assets/optimizer.mjs';
import {createFixtureServer} from './server.mjs';

const graph = '_assets/register/image-optimizer';
const editor = '_assets/register/post-inplace.js';
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==', 'base64');
const emptyWasm = Buffer.from([0, 97, 115, 109, 1, 0, 0, 0]);
const changedWasm = Buffer.concat([emptyWasm, Buffer.from([0, 2, 1, 97])]); // Valid custom section.
const optimizer = `import {planDimensions} from './policy.js';
    const workerUrl = new URL('worker.js', import.meta.url);
    export async function optimizeImage(blob) {
        const plan = planDimensions();
        const worker = new Worker(workerUrl);
        try {
            await new Promise((resolve, reject) => {
                worker.onmessage = event => event.data === plan.codec ? resolve() : reject(new Error('Mixed codec versions'));
                worker.onerror = () => reject(new Error('Unable to load worker'));
                worker.postMessage('optimize');
            });
            return {blob, extension: 'png', retina: false, width: plan.width, height: 1, displayWidth: plan.width, displayHeight: 1};
        } finally { worker.terminate(); }
    }`;
const worker = `importScripts('../lib/codec.js');
    const locateFile = path => new URL('../lib/' + path, self.location.href).toString();
    self.onmessage = async () => {
        const bytes = await (await fetch(locateFile('codec.wasm'))).arrayBuffer();
        await WebAssembly.compile(bytes);
        self.postMessage(self.codec + ':' + bytes.byteLength);
    };`;

async function release(root, version, versionGraphs = true) {
    for (const directory of [...assetDirectories, '_include']) await mkdir(resolve(root, directory), {recursive: true});
    const files = {
        [editor]: await readFile(new URL('../../../' + editor, import.meta.url)),
        [graph + '/js/optimizer.js']: optimizer,
        [graph + '/js/policy.js']: `export function planDimensions() { return {width: ${version}, codec: '${version}:${version === 1 ? 8 : 12}'}; }`,
        [graph + '/js/worker.js']: worker,
        [graph + '/js/optimizer-loader.js']: await readFile(new URL('../../../' + graph + '/js/optimizer-loader.js', import.meta.url)),
        [graph + '/lib/codec.js']: `self.codec = ${version};`,
        [graph + '/lib/codec.wasm']: version === 1 ? emptyWasm : changedWasm,
        'service-worker.js': 'self.addEventListener("fetch", () => {});',
    };
    for (const [path, content] of Object.entries(files)) {
        await mkdir(resolve(root, path, '..'), {recursive: true});
        await writeFile(resolve(root, path), content);
    }
    await buildAssets(root, {bundles: [], ...(versionGraphs ? {} : {graphs: []})});
    const manifest = JSON.parse(await readFile(resolve(root, manifestPath), 'utf8'));
    return {root, editorUrl: '/' + editor + '.asset?v=' + manifest.assets[editor]};
}

async function chooseImage(page, body) {
    await body.press('ControlOrMeta+End');
    await body.press('Shift+F10');
    const chooser = page.waitForEvent('filechooser');
    await page.locator('.post-editor-context-menu [data-context-action="media"]').click();
    await (await chooser).setFiles({name: 'cache-test.png', mimeType: 'image/png', buffer: png});
}

export async function runImageOptimizerCacheRegressions(browser) {
    const scratch = await mkdtemp(resolve(tmpdir(), 'register-optimizer-upgrade-'));
    const fallback = createFixtureServer();
    let server;
    try {
        const oldRelease = await release(resolve(scratch, 'old'), 1);
        const newRelease = await release(resolve(scratch, 'new'), 2);
        const unversionedRelease = await release(resolve(scratch, 'unversioned-control'), 2, false);
        for (const scenario of ['unversioned-control', 'legacy-upgrade', 'release-upgrade']) {
            const legacy = scenario !== 'release-upgrade';
            const control = scenario === 'unversioned-control';
            const releases = [oldRelease, control ? unversionedRelease : newRelease];
            // A disposable persistent profile exercises the browser's real disk
            // cache as well as memory cache; no user browser/profile is opened.
            const context = await browser.browserType().launchPersistentContext(
                resolve(scratch, scenario + '-profile'),
                {headless: true, serviceWorkers: 'block', viewport: {width: 1280, height: 900}},
            );
            const page = await context.newPage();
            page.setDefaultTimeout(10000);
            await context.addInitScript(() => {
                if (window === window.top) window.optimizerCachePolicyViolations = [];
                document.addEventListener('securitypolicyviolation', event => {
                    window.top.optimizerCachePolicyViolations.push(event.violatedDirective);
                });
            });
            const errors = [], requests = [], uploads = [], saves = [];
            let generation = 0, savedBody = null, releaseCodec, codecStarted;
            const heldCodec = new Promise(resolve => { releaseCodec = resolve; });
            const codecRequest = new Promise(resolve => { codecStarted = resolve; });
            const html = (await readFile(new URL('./recovery.html', import.meta.url), 'utf8'))
                .replace('<div class="post-editor-context-main">', '<div class="post-editor-context-main"><button type="button" data-context-action="media">Image</button>');
            const fixture = await readFile(new URL('./recovery-fixture.js', import.meta.url), 'utf8');
            server = createServer(async (request, response) => {
                const url = new URL(request.url, 'http://127.0.0.1');
                const path = url.pathname;
                response.setHeader('Cache-Control', 'no-store');
                response.setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; connect-src 'self'; img-src 'self' data: blob:; media-src 'self'; object-src 'none'; style-src 'self'; style-src-attr 'unsafe-inline'; worker-src 'self'");
                try {
                    if (path === '/warm.html') {
                        response.setHeader('Content-Type', 'text/html');
                        response.end('<!doctype html><script src="/warm.js"></script>');
                    } else if (path === '/warm.js') {
                        response.setHeader('Content-Type', 'text/javascript');
                        // Like the formerly raw optimizer entry on shared hosting.
                        response.setHeader('Cache-Control', 'public, max-age=31536000, immutable');
                        response.end(`(async () => {
                            const {oldPolicy} = await import('/${graph}/js/policy.js');
                            const worker = new Worker('/${graph}/js/worker.js');
                            worker.onerror = event => { window.warmError = event.message; };
                            worker.onmessage = () => { worker.terminate(); window.warmed = oldPolicy; }; worker.postMessage('warm');
                        })();`);
                    } else if (path === '/recovery.html') {
                        response.setHeader('Content-Type', 'text/html');
                        response.end(html.replace('/editor.js', releases[generation].editorUrl));
                    } else if (path === '/cached-policy-probe.js') {
                        response.setHeader('Content-Type', 'text/javascript');
                        response.end(`import(${JSON.stringify(url.searchParams.get('url'))}).then(policy => {
                            window.cachedOldPolicy = policy.oldPolicy ?? policy.planDimensions().width;
                        });`);
                    } else if (path === '/recovery-fixture.js') {
                        response.setHeader('Content-Type', 'text/javascript');
                        response.end(savedBody === null ? fixture : fixture.replace(
                            /const body = creating \? '' : [^\n]+;/u,
                            "const body = creating ? '' : " + JSON.stringify(savedBody) + ';',
                        ));
                    } else if (path === '/_inplace/tags') {
                        response.setHeader('Content-Type', 'application/json'); response.end('{"tags":[]}');
                    } else if (path === '/cache-test.png') {
                        response.setHeader('Content-Type', 'image/png'); response.end(png);
                    } else if (path === '/_inplace/post/9') {
                        const chunks = [];
                        for await (const chunk of request) chunks.push(chunk);
                        const data = await new Request(url, {method: 'POST', headers: request.headers, body: Buffer.concat(chunks)}).formData();
                        response.setHeader('Content-Type', 'application/json');
                        if (data.get('inplace_action') === 'media') {
                            uploads.push(data);
                            response.end(JSON.stringify({success: true, action: 'media', kind: 'image', media_id: 40 + uploads.length,
                                url: '/cache-test.png', width: Number(data.get('media_width')), height: 1}));
                        } else if (data.get('inplace_action') === 'media_redate') {
                            response.end(JSON.stringify({success: true, media: [{media_id: 40 + uploads.length,
                                url: '/cache-test.png', kind: 'image', width: Number(uploads.at(-1).get('media_width')), height: 1}]}));
                        } else if (data.get('inplace_action') === 'media_release') {
                            response.end('{"success":true}');
                        } else {
                            savedBody = data.get('body'); saves.push(data);
                            response.end(JSON.stringify({success: true, action: 'edit', title: data.get('title'), revision: 2,
                                body_html: `<div class="post body" data-post-inplace-body>${savedBody}</div>`,
                                published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
                                tags: [], scheduled: false, message: 'Saved'}));
                        }
                    } else if (path.startsWith('/_assets/register/image-optimizer/') || path.startsWith('/' + editor)) {
                        requests.push({generation, url: url.pathname + url.search,
                            cache: request.headers['cache-control'], mode: request.headers['sec-fetch-mode']});
                        response.setHeader('Cache-Control', 'public, max-age=31536000, immutable');
                        response.setHeader('Content-Type', path.endsWith('.wasm') ? 'application/wasm' : 'text/javascript');
                        if (generation === 1 && path.endsWith('/lib/codec.js.asset')) { codecStarted(); await heldCodec; }
                        let content;
                        if (legacy && generation === 0 && path === '/' + graph + '/js/policy.js') content = 'export const oldPolicy = 1;';
                        else if (legacy && generation === 0 && path === '/' + graph + '/js/worker.js') content = worker;
                        else content = await readFile(resolve(releases[generation].root, path.slice(1).replace(/\.asset$/u, '')));
                        response.end(content);
                    } else { fallback.emit('request', request, response); }
                } catch (error) { response.writeHead(500); response.end(String(error)); }
            });
            await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
            const origin = `http://127.0.0.1:${server.address().port}`;
            page.on('pageerror', error => errors.push(String(error)));
            try {
                if (legacy) {
                    await page.goto(origin + '/warm.html');
                    await page.waitForFunction(() => window.warmed === 1 || window.warmError);
                    assert.equal(await page.evaluate(() => window.warmError), undefined);
                } else {
                    await page.goto(origin + '/recovery.html');
                    await page.getByRole('button', {name: 'Edit', exact: true}).click();
                    await chooseImage(page, page.locator('.is-editing [data-post-inplace-body]'));
                    await page.locator('.post-media-picture:not(.is-processing)').waitFor();
                    assert.equal(uploads[0].get('media_width'), '1');
                    await page.getByRole('button', {name: 'Cancel', exact: true}).click();
                    await page.getByRole('button', {name: 'Discard changes', exact: true}).click();
                }
                const warmPolicy = requests.find(item => item.generation === 0 && /\/policy\.js(?:\.asset)?\?*[^/]*$/u.test(item.url));
                assert.ok(warmPolicy, 'The old policy must actually enter the HTTP cache');
                const warmRequests = requests.filter(item => item.url === warmPolicy.url).length;
                generation = 1;
                await page.goto(origin + '/recovery.html');
                await page.getByRole('button', {name: 'Edit', exact: true}).click();
                assert.equal(requests.filter(item => item.generation === 1 && item.url.includes('image-optimizer')).length, 0,
                    'Opening the editor must keep the complete image graph lazy');
                // Probe the actual HTTP cache in this new document, before the
                // upload/save POSTs are allowed to invalidate cache entries.
                await page.evaluate(url => {
                    const script = document.createElement('script');
                    script.src = '/cached-policy-probe.js?url=' + encodeURIComponent(url);
                    document.head.append(script);
                }, warmPolicy.url);
                await page.waitForFunction(() => window.cachedOldPolicy !== undefined);
                assert.equal(await page.evaluate(() => window.cachedOldPolicy), 1,
                    'The earlier module URL still returns its cached old version: ' + JSON.stringify(requests));
                assert.equal(requests.filter(item => item.url === warmPolicy.url).length, warmRequests,
                    'The old file is still cached; this regression must not disable or clear HTTP caching');
                const body = page.locator('.is-editing [data-post-inplace-body]');
                await body.press('ControlOrMeta+End');
                await page.keyboard.type(' Text before upload.');
                const originalText = await body.textContent();
                await chooseImage(page, body);
                if (control) {
                    await page.locator('.post-inplace-edit-error:not([hidden])').waitFor();
                    assert.match(await page.locator('.post-inplace-edit-error').textContent(), /your text remains in the editor/u);
                    assert.equal(await body.textContent(), originalText);
                    assert.equal(uploads.length, 0);
                    assert.equal(await body.locator('.is-processing').count(), 0);
                    assert.equal(requests.filter(item => item.url === warmPolicy.url).length, warmRequests);
                    assert.deepEqual(await page.evaluate(() => optimizerCachePolicyViolations), []);
                    assert.deepEqual(errors, []);
                    console.log(`${browser.browserType().name()}: unversioned control reproduces the cached missing-export failure without uploading or losing text`);
                    continue;
                }
                let timer;
                try { await Promise.race([codecRequest, new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('New codec did not load')), 8000); })]); }
                finally { clearTimeout(timer); }
                await body.press('ControlOrMeta+End');
                await page.keyboard.type(' Text while codec loads.');
                assert.ok((await body.textContent()).includes('Text while codec loads.'), 'Processing does not block editing');
                releaseCodec();
                const picture = body.locator('.post-media-picture:not(.is-processing)');
                await picture.waitFor();
                assert.equal(uploads.at(-1).get('media_width'), '2', 'The new policy and codec run together, not stale cached code');
                await picture.locator('.post-caption').click();
                await page.keyboard.type('Caption survives upgrade.');
                await page.keyboard.press('Enter');
                await page.keyboard.type('Paragraph after upgraded image.');
                await page.getByRole('button', {name: 'Save', exact: true}).click();
                await page.waitForFunction(() => !document.querySelector('.post-card.is-editing')
                    || document.querySelector('.post-inplace-edit-error')?.textContent);
                assert.equal(await page.locator('.post-inplace-edit-error').textContent(), '',
                    'Saving after the upgrade must not fail');
                assert.equal(saves.length, 1);
                for (const text of ['Text before upload.', 'Text while codec loads.', 'Caption survives upgrade.', 'Paragraph after upgraded image.']) {
                    assert.ok(savedBody.includes(text), `Saving retains ${text}`);
                }
                assert.ok(requests.filter(item => item.generation === 1 && item.url.includes('image-optimizer'))
                    .every(item => /\?v=[a-f0-9]{64}$/u.test(item.url)), 'Every newly requested module, worker, library and Wasm is versioned');
                assert.deepEqual(await page.evaluate(() => optimizerCachePolicyViolations), []);
                await page.reload();
                await page.getByRole('button', {name: 'Edit', exact: true}).click();
                for (const text of ['Text before upload.', 'Text while codec loads.', 'Caption survives upgrade.', 'Paragraph after upgraded image.']) {
                    assert.ok((await body.textContent()).includes(text), `Reopening retains ${text}`);
                }
                assert.deepEqual(errors, []);
                assert.deepEqual(await page.evaluate(() => optimizerCachePolicyViolations), []);
                console.log(`${browser.browserType().name()}: ${legacy ? 'legacy unversioned' : 'previous release'} year-long cache survives upgrade; new policy, worker, Wasm, typing, caption and save/reopen work`);
            } finally {
                releaseCodec(); await context.close();
                await new Promise(resolve => server.close(resolve)); server = null;
            }
        }
    } finally {
        if (server) await new Promise(resolve => server.close(resolve));
        await rm(scratch, {recursive: true, force: true});
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        for (const engine of [chromium, firefox, webkit]) {
            if (process.env.EDITOR_TEST_BROWSER && process.env.EDITOR_TEST_BROWSER !== engine.name()) continue;
            const browser = await engine.launch();
            try { await runImageOptimizerCacheRegressions(browser); }
            finally { await browser.close(); }
        }
    } catch (error) { console.error(error); process.exitCode = 1; }
}
