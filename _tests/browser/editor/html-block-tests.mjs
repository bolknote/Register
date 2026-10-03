import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {chromium, firefox, webkit} from 'playwright';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {readFile} from 'node:fs/promises';
import {createFixtureServer} from './server.mjs';
import {formData} from './save-tests.mjs';

export async function fulfillPreview(route, data) {
    const previewScript = (await readFile(new URL('../../../_assets/register/editor/html-preview.js', import.meta.url))).toString('base64');
    const previewCss = (await readFile(new URL('../../../_assets/register/editor/html-preview.css', import.meta.url))).toString('base64');
    const attribute = value => value.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;');
    // Match the isolated document contract; PHP integration tests cover the real endpoint.
    await route.fulfill({contentType: 'text/html', headers: {
        'Content-Security-Policy': "sandbox allow-scripts allow-forms; default-src * data: blob: 'unsafe-inline' 'unsafe-eval'; frame-ancestors 'self'",
    }, body: `<!doctype html><head><meta charset="utf-8"><link rel="stylesheet" href="data:text/css;base64,${previewCss}"></head><body>`
        + `<script src="data:text/javascript;base64,${previewScript}" data-preview-options="${attribute(JSON.stringify({
            key: data.get('html_preview_key'), theme: {font: data.get('html_font'), color: data.get('html_color'),
                backgroundColor: data.get('html_backgroundColor'), colorScheme: data.get('html_colorScheme')},
        }))}"></script>` + data.get('html_source')});
}

export async function runHtmlBlockAppearanceRegressions(browser, origin) {
    for (const colorScheme of ['dark', 'light']) {
        for (const mobile of [false, true]) {
            const context = await browser.newContext({colorScheme, hasTouch: mobile,
                viewport: mobile ? {width: 390, height: 844} : {width: 1280, height: 900}});
            const page = await context.newPage();
            page.setDefaultTimeout(10000);
            const errors = [];
            let previews = 0;
            page.on('pageerror', error => errors.push(String(error)));
            await page.route('**/recovery-fixture.css', route => route.fulfill({contentType: 'text/css',
                body: 'body { margin: 24px; } #content { width: min(960px, 100%); } @view-transition { navigation: none; }'}));
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            await page.route('**/_inplace/post/9', async route => {
                const data = await formData(route);
                assert.equal(data.get('inplace_action'), 'html_preview');
                previews++;
                await fulfillPreview(route, data);
            });
            try {
                await page.goto(origin + '/recovery.html');
                await page.getByRole('button', {name: 'Edit', exact: true}).click();
                await page.locator('[data-post-inplace-body]').click();
                await page.keyboard.press('Shift+F10');
                await page.getByRole('button', {name: 'HTML block', exact: true}).click();
                const block = page.locator('.post-html-block');
                const preview = block.locator('iframe');
                const code = page.getByRole('textbox', {name: 'HTML code', exact: true});
                assert.equal(await preview.isVisible(), false, 'An empty insertion has no blank preview strip');
                assert.equal(previews, 0, 'An empty insertion does not request a document');
                const controls = await block.locator('button').evaluateAll(buttons => buttons.map(button => {
                    const style = getComputedStyle(button);
                    const bounds = button.getBoundingClientRect();
                    const panel = button.closest('.post-html-block').getBoundingClientRect();
                    return {border: style.borderTopWidth, height: bounds.height, fits: bounds.left >= panel.left && bounds.right <= panel.right};
                }));
                assert.ok(controls.every(control => control.border === '0px'), 'HTML controls use the editor toolbar style, not outlined browser-like buttons');
                assert.ok(controls.every(control => control.fits && control.height >= (mobile ? 44 : 34)), 'Controls fit the block and have usable touch targets');
                if (process.env.EDITOR_HTML_APPEARANCE_DIR) await block.screenshot({
                    path: `${process.env.EDITOR_HTML_APPEARANCE_DIR}/${browser.browserType().name()}-${colorScheme}-${mobile ? 'mobile' : 'desktop'}-empty.png`,
                });

                const source = '<p id="sample">Preview content</p>';
                await code.fill(source);
                const frame = page.frameLocator('.post-html-block-preview');
                await frame.locator('#sample').waitFor();
                assert.equal(await preview.isVisible(), true);
                const theme = await block.evaluate(element => ({background: getComputedStyle(element).backgroundColor,
                    colorScheme: getComputedStyle(element).colorScheme}));
                assert.notEqual(theme.background, 'rgba(0, 0, 0, 0)');
                assert.deepEqual(await frame.locator('html').evaluate(element => ({background: getComputedStyle(element).backgroundColor,
                    colorScheme: getComputedStyle(element).colorScheme})), theme, 'A transparent document shares the block background and color scheme');
                assert.equal(await block.getAttribute('data-post-html-source'), source);

                await code.fill(' \n\t');
                assert.equal(await preview.isVisible(), false, 'Clearing the source immediately removes the empty area');
                assert.equal(await block.getAttribute('data-post-html-source'), ' \n\t', 'Hiding a blank preview never trims the author source');

                const authored = '<style>html { background: rgb(22, 44, 66); } body { background: rgb(90, 80, 70); }</style>\n<p id="authored">Authored background</p>';
                await code.fill(authored);
                await frame.locator('#authored').waitFor();
                assert.equal(await frame.locator('html').evaluate(element => getComputedStyle(element).backgroundColor), 'rgb(22, 44, 66)', 'Author styles override preview defaults');
                assert.equal(await frame.locator('body').evaluate(element => getComputedStyle(element).backgroundColor), 'rgb(90, 80, 70)');
                const done = block.getByRole('button', {name: 'Done', exact: true});
                if (mobile) await done.tap(); else await done.click();
                assert.equal(await code.isVisible(), false);
                await frame.locator('#authored').waitFor();
                assert.equal(await block.getAttribute('data-post-html-source'), authored);
                assert.ok(await page.evaluate(source => {
                    const state = editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
                    return editorTest.editableBodyHtml(state).includes(source);
                }, authored));
                assert.deepEqual(errors, []);
            } finally { await context.close(); }
        }
    }
    console.log(`${browser.browserType().name()}: HTML preview appearance, empty blocks, authored styles and touch controls passed in dark/light themes`);
}

export async function runHtmlBlockRegressions(browser, origin) {
    const context = await browser.newContext();
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    if (process.env.EDITOR_HTML_DEBUG) {
        page.on('console', message => console.log(message.type(), message.text()));
        page.on('requestfailed', request => console.log('request failed', request.url(), request.failure()));
    }
    const source = `<style>.sample { color: rgb(12, 34, 56); padding: 7px; }</style>
<!-- exact "quotes" & spacing -->
<section class="sample"><svg width="12" height="12"><circle cx="6" cy="6" r="5" /></svg><strong id="result">Before script</strong></section>
<script>document.getElementById('result').textContent = 'Rendered'; try { parent.document.body.dataset.leaked = 'yes'; } catch (_) { document.body.dataset.isolated = 'yes'; }</script>`;
    let savedBody = null;
    let initialCard = null;
    let publishedCard = null;
    let rejectSave = true;
    let previewCount = 0;
    // A successful save of executable HTML loads the authoritative document.
    // Model that GET too; returning the fixture's old post would lose the
    // saved body, and inserting its scripts with innerHTML would keep them inert.
    await page.route('**/recovery.html', async route => {
        if (publishedCard === null) return route.continue();
        const response = await route.fetch();
        const trusted = execFileSync(process.env.PHP_BIN || 'php', ['-r',
            'require $argv[1]; $response = new Symfony\\Component\\HttpFoundation\\Response(Register\\Core\\Http\\TrustedScriptNonceInjector::markTrustedHtml($argv[2]), 200, ["Content-Type" => "text/html"]); (new Register\\Core\\Http\\TrustedScriptNonceInjector())->injectIntoResponse($response, "HtmlSaveFixtureNonce"); echo $response->getContent();',
            fileURLToPath(new URL('../../../_vendor/autoload.php', import.meta.url)), publishedCard], {encoding: 'utf8'});
        await route.fulfill({response, headers: {...response.headers(),
            'content-security-policy': "default-src 'self'; script-src 'self' 'nonce-HtmlSaveFixtureNonce'; style-src 'self' 'nonce-HtmlSaveFixtureNonce'; img-src 'self' data: blob:; object-src 'none'"},
        body: (await response.text()).replace('<div class="live-post-feed"></div>',
            `<div class="live-post-feed">${trusted}</div>`)});
    });
    await page.route('**/_inplace/post/9', async route => {
        const data = await formData(route);
        if (data.get('inplace_action') === 'html_preview') {
            previewCount++;
            await fulfillPreview(route, data);
            return;
        }
        if (data.get('inplace_action') === 'ai') {
            assert.ok(!data.get('text').includes('<script>'));
            await route.fulfill({json: {success: true, action: 'ai', ai_action: 'proofread',
                result: data.get('text').replace('Last paragraph', 'Corrected paragraph')}});
            return;
        }
        assert.equal(data.get('inplace_action'), 'edit');
        savedBody = data.get('body');
        if (rejectSave) {
            await route.fulfill({status: 503, contentType: 'application/json', body: JSON.stringify({success: false, message: 'Save failed'})});
            return;
        }
        publishedCard = await page.evaluate(({html, body}) => {
            const template = document.createElement('template');
            template.innerHTML = html;
            const card = template.content.firstElementChild;
            const content = card.querySelector('[data-post-inplace-body]');
            content.innerHTML = body;
            card.querySelector('[name="body"]').textContent = body;
            card.querySelector('[name="revision"]').value = '2';
            card.querySelector('[data-post-inplace-title]').textContent = 'Server title';
            card.querySelector('[name="title"]').value = 'Server title';
            return card.outerHTML;
        }, {html: initialCard, body: savedBody});
        await route.fulfill({contentType: 'application/json', body: JSON.stringify({
            success: true, action: 'edit', title: 'Server title', revision: 2,
            body_html: `<div class="post body" data-post-inplace-body>${savedBody}</div>`,
            published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
            tags: [], scheduled: false, message: 'Saved',
        })});
    });
    const open = async () => {
        await page.goto(origin + '/recovery.html');
        await page.waitForFunction(() => window.editorTest);
    };
    const sourceValue = () => page.locator('[data-post-html-source]').getAttribute('data-post-html-source');
    try {
        await open();
        initialCard = await page.locator('.post-card').evaluate(card => card.outerHTML);
        await page.evaluate(() => {
            const body = document.querySelector('[data-post-inplace-body]');
            body.innerHTML = '<p>LeftRight</p><p>Last paragraph</p>';
            document.querySelector('[name="body"]').value = body.innerHTML;
        });
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        assert.equal(await page.locator('.post-editor-mode-tabs, .post-editor-html-source').count(), 0);
        await page.evaluate(() => {
            const p = document.querySelector('[data-post-inplace-body] p');
            document.querySelector('[data-post-inplace-body]').focus();
            const r = document.createRange(); r.setStart(p.firstChild, 4); r.collapse(true);
            getSelection().removeAllRanges(); getSelection().addRange(r);
        });
        await page.keyboard.press('Shift+F10');
        await page.getByRole('button', {name: 'HTML block', exact: true}).click();
        const code = page.getByRole('textbox', {name: 'HTML code', exact: true});
        await code.fill(source);
        await code.press('End');
        await code.press('Enter');
        await code.press('Backspace');
        assert.equal(await code.evaluate(el => el === document.activeElement), true);
        await page.waitForFunction(() => document.querySelector('iframe')?.style.height);
        const frame = page.frameLocator('.post-html-block-preview');
        await frame.getByText('Rendered', {exact: true}).waitFor();
        assert.equal(await frame.locator('.sample').evaluate(el => getComputedStyle(el).color), 'rgb(12, 34, 56)');
        assert.equal(await frame.locator('body').getAttribute('data-isolated'), 'yes');
        assert.equal(await page.locator('body').getAttribute('data-leaked'), null);
        assert.equal(await sourceValue(), source);
        const protectedAi = await page.evaluate(() => {
            const state = editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
            const html = editorTest.editableBodyHtml(state);
            const protectedHtml = RegisterEditorHtmlBlocks.protectForAi(html);
            return {
                sourceHidden: !protectedHtml.text.includes('<script>'),
                restored: protectedHtml.restore(protectedHtml.text.replace('Last paragraph', 'Corrected paragraph')),
                lostBlock: protectedHtml.restore('<p>No placeholder</p>'),
            };
        });
        assert.ok(protectedAi.sourceHidden);
        assert.ok(protectedAi.restored.includes(source));
        assert.ok(protectedAi.restored.endsWith('<p>Corrected paragraph</p>'));
        assert.equal(protectedAi.lostBlock, null);
        assert.deepEqual(await page.locator('[data-post-inplace-body] > p').allTextContents(), ['Left', 'Right', 'Last paragraph']);
        const modifier = await page.evaluate(() => /Mac|iPhone|iPad/u.test(navigator.platform) ? 'Meta' : 'Control');
        await code.press(modifier + '+s');
        await page.getByText('Save failed', {exact: true}).waitFor();
        assert.ok(savedBody.replaceAll('\r\n', '\n').includes(source), savedBody);
        assert.ok(savedBody.startsWith('<p>Left</p>'));
        assert.ok(savedBody.endsWith('<p>Right</p><p>Last paragraph</p>'));
        assert.ok(!/post-html-block-code|post-html-block-tools|post-html-block-preview|contenteditable/.test(savedBody));
        assert.equal(await sourceValue(), source);

        // The local copy retains all code and surrounding prose through a real reload.
        await open();
        await page.getByRole('button', {name: 'Restore text', exact: true}).click();
        await frame.getByText('Rendered', {exact: true}).waitFor();
        assert.equal(await sourceValue(), source);
        assert.deepEqual(await page.locator('[data-post-inplace-body] > p').allTextContents(), ['Left', 'Right', 'Last paragraph']);
        await page.getByRole('button', {name: 'Remove block', exact: true}).click();
        assert.equal(await page.locator('[data-post-html-source]').count(), 0);
        await page.keyboard.press(modifier + '+z');
        await frame.getByText('Rendered', {exact: true}).waitFor();
        assert.equal(await sourceValue(), source);

        rejectSave = false;
        await Promise.all([
            page.waitForNavigation({waitUntil: 'load'}),
            page.getByRole('button', {name: 'Save', exact: true}).click(),
        ]);
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        assert.equal(await page.locator('.post-html-block-tools').count(), 0);
        assert.equal(await page.locator('[data-post-inplace-body] #result').textContent(), 'Rendered',
            'Saving loads a working program without a manual refresh');
        assert.equal(await page.locator('[data-post-inplace-body] .sample').evaluate(element => getComputedStyle(element).color),
            'rgb(12, 34, 56)', 'Authored styles are authorized by the document CSP');
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        await frame.getByText('Rendered', {exact: true}).waitFor();
        assert.equal(await sourceValue(), source);
        assert.deepEqual(await page.locator('[data-post-inplace-body] > p').allTextContents(), ['Left', 'Right', 'Last paragraph']);
        assert.ok(previewCount >= 3);
        if (process.env.EDITOR_HTML_SCREENSHOT) await page.screenshot({path: process.env.EDITOR_HTML_SCREENSHOT});
        await page.locator('[data-post-inplace-body] > p').first().click({button: 'right'});
        await page.getByRole('button', {name: 'Proofread', exact: true}).click();
        await page.waitForFunction(() => !document.querySelector('.post-card.is-ai-working'));
        assert.equal(await sourceValue(), source);
        assert.deepEqual(await page.locator('[data-post-inplace-body] > p').allTextContents(), ['Left', 'Right', 'Corrected paragraph']);
        assert.equal(await page.locator('.post-html-block-tools .post-editor-ai-change').count(), 0);

        // Copy/paste an indivisible block through the editor's clipboard events,
        // rather than copying its live iframe and controls as ordinary HTML.
        const clipboard = await page.evaluate(() => {
            const body = document.querySelector('[data-post-inplace-body]');
            body.focus();
            const range = document.createRange();
            range.selectNode(body.querySelector('[data-post-html-source]'));
            getSelection().removeAllRanges(); getSelection().addRange(range);
            const data = new DataTransfer();
            const event = new Event('copy', {bubbles: true, cancelable: true});
            Object.defineProperty(event, 'clipboardData', {value: data});
            body.dispatchEvent(event);
            return {handled: event.defaultPrevented, html: data.getData('text/html'), text: data.getData('text/plain')};
        });
        assert.equal(clipboard.handled, true, 'HTML blocks need canonical clipboard serialization');
        assert.equal(clipboard.text, source, 'Plain-text copying must retain code, not toolbar labels');
        assert.ok(!/post-html-block-tools|post-html-block-preview|contenteditable/.test(clipboard.html));
        await page.evaluate(html => {
            const body = document.querySelector('[data-post-inplace-body]');
            body.focus();
            const range = document.createRange();
            range.selectNodeContents(body.lastElementChild); range.collapse(false);
            getSelection().removeAllRanges(); getSelection().addRange(range);
            const data = new DataTransfer(); data.setData('text/html', html);
            const event = new Event('paste', {bubbles: true, cancelable: true});
            Object.defineProperty(event, 'clipboardData', {value: data});
            body.dispatchEvent(event);
        }, clipboard.html);
        await page.locator('.post-html-block-preview').nth(1).waitFor();
        await page.frameLocator('.post-html-block-preview').nth(1).getByText('Rendered', {exact: true}).waitFor();
        assert.deepEqual(await page.locator('[data-post-html-source]').evaluateAll(nodes => nodes.map(node => node.dataset.postHtmlSource)), [source, source]);
        await page.keyboard.press(modifier + '+z');
        assert.equal(await page.locator('[data-post-html-source]').count(), 1);
        assert.equal(await sourceValue(), source);

        await page.evaluate(() => {
            const body = document.querySelector('[data-post-inplace-body]'); body.focus();
            const range = document.createRange(); range.selectNode(body.querySelector('[data-post-html-source]'));
            getSelection().removeAllRanges(); getSelection().addRange(range);
            const event = new Event('cut', {bubbles: true, cancelable: true});
            Object.defineProperty(event, 'clipboardData', {value: new DataTransfer()});
            body.dispatchEvent(event);
        });
        assert.equal(await page.locator('[data-post-html-source]').count(), 0);
        assert.equal(await page.locator('form[target^="register-html-"]').count(), 0, 'Cut must dispose the removed preview session');
        await page.keyboard.press(modifier + '+z');
        await frame.getByText('Rendered', {exact: true}).waitFor();
        assert.equal(await sourceValue(), source);

        await page.evaluate(() => {
            const body = document.querySelector('[data-post-inplace-body]'); body.focus();
            const range = document.createRange(); range.selectNodeContents(body);
            getSelection().removeAllRanges(); getSelection().addRange(range);
        });
        await page.keyboard.press('Shift+F10');
        await page.getByRole('button', {name: 'Inline code', exact: true}).click();
        assert.equal(await page.locator('.post-html-block-tools tt').count(), 0, 'Prose formatting must not alter HTML block controls');
        assert.equal(await sourceValue(), source);
        assert.deepEqual(await page.locator('[data-post-inplace-body] > p').evaluateAll(nodes => nodes.map(node =>
            [...node.querySelectorAll('tt')].map(code => code.textContent).join(''))), ['Left', 'Right', 'Corrected paragraph']);
        await page.keyboard.press(modifier + '+z');

        // Older whole-post HTML-mode drafts remain recoverable as one explicit block.
        await page.evaluate(source => {
            const state = editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
            editorTest.stopEditing(state);
            const store = RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1);
            store.list().forEach(record => store.remove(record));
            store.save({version: 1, id: 'legacy-raw-copy', target: '9', revision: 1, savedAt: Date.now(), snapshot: {
                title: 'Legacy HTML', body: '<p>Legacy prose</p>' + source,
                htmlSource: true, tags: '', date: '2026-09-06T15:00', slug: 'server-slug', mediaIds: [],
            }});
        }, source);
        await open();
        await page.getByRole('button', {name: 'Restore text', exact: true}).click();
        await frame.getByText('Rendered', {exact: true}).waitFor();
        assert.equal(await sourceValue(), '<p>Legacy prose</p>' + source);
        assert.equal(await page.locator('[data-post-inplace-body] > p').count(), 0);
        assert.deepEqual(errors, []);
        console.log(`${browser.browserType().name()}: HTML insertion, isolated rendering, failed save, recovery, undo and reopen passed`);
    } finally { await context.close(); }
    await runHtmlBlockAppearanceRegressions(browser, origin);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit]) {
            const browser = await engine.launch();
            try { await runHtmlBlockRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
