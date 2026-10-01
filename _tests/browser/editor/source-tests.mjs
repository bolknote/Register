import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {formData} from './save-tests.mjs';

export async function runSourceRegressions(browser, origin) {
    const context = await browser.newContext();
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.stack || String(error)));
    page.on('dialog', dialog => { errors.push(`Native dialog: ${dialog.type()}`); dialog.dismiss(); });
    await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
    const raw = '<p class="intro" style="text-align: left">До картинки &amp; текст.</p>\n'
        + '<!-- authored spacing -->\n<div class="post-picture post-media-picture"><img src="/image.png" alt="Картинка">'
        + '<div class="post-caption">Подпись</div></div>\n<p>После картинки.</p>\n<p>Последний абзац.</p>';
    const tabs = () => page.getByRole('tab');
    const readHtml = () => page.evaluate(() => document.querySelector('.CodeMirror').CodeMirror.getValue());
    async function open(html = raw) {
        await page.goto(origin + '/recovery.html');
        await page.evaluate(html => {
            localStorage.clear();
            document.querySelector('[name="body"]').value = html;
            document.querySelector('[data-post-inplace-body]').innerHTML = html;
        }, html);
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
    }
    async function htmlMode() {
        await tabs().filter({hasText: /^HTML$/u}).click();
        await page.waitForFunction(() => document.querySelector('.post-card.is-html-editing .CodeMirror'));
        await page.locator('.post-editor-source .CodeMirror').waitFor({state: 'visible'});
        assert.ok((await page.locator('.post-editor-source .CodeMirror').boundingBox()).height >= 280);
    }
    async function visualMode() {
        await tabs().filter({hasText: /^Editor$/u}).click();
        await page.waitForFunction(() => !document.querySelector('.post-card.is-html-editing'));
    }
    async function replaceHtml(html) {
        await page.evaluate(() => {
            const cm = document.querySelector('.CodeMirror').CodeMirror;
            cm.focus();
            cm.setSelection({line: 0, ch: 0}, {line: cm.lastLine(), ch: cm.getLine(cm.lastLine()).length});
        });
        await page.keyboard.insertText(html);
        await page.waitForFunction(html => document.querySelector('.CodeMirror').CodeMirror.getValue() === html, html);
        assert.equal(await readHtml(), html);
    }
    try {
        await open();
        assert.equal(await page.locator('script[src*="codemirror"]').count(), 0);
        await page.evaluate(() => {
            const node = document.querySelector('[data-post-inplace-body] > p:last-child').firstChild;
            document.querySelector('[data-post-inplace-body]').focus();
            getSelection().setBaseAndExtent(node, 4, node, 8);
        });
        const historyLength = await page.evaluate(() => window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing')).history.length);
        for (let index = 0; index < 3; index++) {
            await htmlMode();
            assert.equal(await readHtml(), raw);
            assert.ok(await page.locator('.CodeMirror .cm-tag').count());
            await visualMode();
            assert.equal(await page.evaluate(() => getSelection().toString()), 'едни');
        }
        assert.equal(await page.evaluate(() => window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing')).history.length), historyLength);
        assert.equal(await page.evaluate(() => localStorage.length), 0);
        console.log('source: repeated switches preserve exact authored HTML, visual selection and undo history without creating a draft');

        await page.locator('[data-post-inplace-body] > p:last-child').click();
        await page.keyboard.press('End');
        await page.keyboard.type(' Typed.');
        await htmlMode();
        const beforeHtmlEdit = await readHtml();
        assert.match(beforeHtmlEdit, /Typed/u);
        const edited = beforeHtmlEdit.replace('После картинки.', '<strong>Правка в HTML.</strong>');
        await replaceHtml(edited);
        await page.keyboard.press('Meta+z');
        assert.equal(await readHtml(), beforeHtmlEdit);
        await page.keyboard.press('Meta+Shift+z');
        assert.equal(await readHtml(), edited);
        await visualMode();
        assert.equal(await page.locator('[data-post-inplace-body] strong').textContent(), 'Правка в HTML.');
        await page.locator('[data-post-inplace-body]').press('Meta+z');
        assert.equal(await page.locator('[data-post-inplace-body] strong').count(), 0);
        assert.match(await page.locator('[data-post-inplace-body]').textContent(), /Typed/u);
        await page.locator('[data-post-inplace-body]').press('Meta+Shift+z');
        await htmlMode();
        assert.equal(await readHtml(), edited);
        console.log('source: real typing, HTML undo/redo and visual undo/redo share the same text without losing images, captions or paragraphs');

        let posted = '';
        let fail = true;
        await page.route('**/_inplace/post/9', async route => {
            const data = await formData(route);
            posted = data.get('body').replaceAll('\r\n', '\n');
            if (fail) return route.fulfill({status: 422, json: {success: false, message: 'Retry the save'}});
            return route.fulfill({json: {
                success: true, action: 'edit', title: 'Server title 1', revision: 2,
                body_html: `<div class="post body" data-post-inplace-body>${posted}</div>`,
                published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
                tags: [], scheduled: false, message: 'Saved',
            }});
        });
        await page.keyboard.press('Meta+s');
        await page.waitForFunction(() => document.querySelector('.post-inplace-error')?.textContent === 'Retry the save');
        assert.equal(posted, edited);
        assert.equal(await readHtml(), edited);
        fail = false;
        await page.keyboard.press('Meta+s');
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        assert.equal(posted, edited);
        assert.equal(await page.locator('.post-editor-mode-tabs, .post-editor-source').count(), 0);
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        await htmlMode();
        assert.equal(await readHtml(), edited);
        console.log('source: Cmd+S saves exact HTML, a failed save retains the complete source, and reopening preserves it');

        await page.unroute('**/_inplace/post/9');
        await open();
        await htmlMode();
        const unsupported = '<p style="margin: 2em" data-custom="kept">Весь текст</p>\n<script>window.sourceExecuted=true</script>';
        await replaceHtml(unsupported);
        await tabs().filter({hasText: /^Editor$/u}).click();
        assert.equal(await page.locator('.post-card.is-html-editing').count(), 1);
        assert.equal(await readHtml(), unsupported);
        assert.match(await page.locator('.post-inplace-status').textContent(), /HTML/u);
        await page.reload();
        await page.getByRole('button', {name: 'Restore text', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('.post-card.is-html-editing .CodeMirror'));
        assert.equal(await readHtml(), unsupported);
        assert.equal(await page.evaluate(() => Boolean(window.sourceExecuted)), false);
        console.log('source: unsupported HTML stays intact, restoration opens it as inert source text and never executes it');

        await page.setViewportSize({width: 390, height: 844});
        const layout = await page.evaluate(() => {
            const tabs = document.querySelector('.post-editor-mode-tabs').getBoundingClientRect();
            const source = document.querySelector('.post-editor-source').getBoundingClientRect();
            return {above: tabs.bottom <= source.top, horizontal: getComputedStyle(document.querySelector('[data-editor-mode]')).writingMode,
                fits: document.documentElement.scrollWidth <= innerWidth};
        });
        assert.deepEqual(layout, {above: true, horizontal: 'horizontal-tb', fits: true});

        await page.setViewportSize({width: 1280, height: 900});
        await open('<p>Initial.</p>');
        let load;
        await page.route('**/_admin/lib/codemirror/codemirror.min.js', route => { load = route; });
        await tabs().filter({hasText: /^HTML$/u}).click();
        await page.waitForFunction(() => document.querySelector('.post-editor-mode-tabs[aria-busy="true"]'));
        while (!load) await new Promise(resolve => setTimeout(resolve, 10));
        await page.locator('[data-post-inplace-body] p').click();
        await page.keyboard.press('End');
        await page.keyboard.type(' Written while loading.');
        await load.continue();
        await page.locator('.post-editor-source .CodeMirror').waitFor({state: 'visible'});
        assert.match(await readHtml(), /Written while loading/u);
        await page.unroute('**/_admin/lib/codemirror/codemirror.min.js');
        console.log('source: typing while CodeMirror loads remains in the HTML source');

        await open();
        await page.getByRole('button', {name: 'Cancel', exact: true}).click();
        assert.equal(await page.getByRole('dialog', {name: 'Discard unsaved changes'}).count(), 0);
        await page.getByRole('button', {name: 'New post', exact: true}).click();
        await page.locator('.post-card.is-editing [data-post-inplace-title]').fill('New HTML post');
        await htmlMode();
        const created = '<p>Создано в <strong>HTML</strong>.</p>\n<p>Второй абзац.</p>';
        await replaceHtml(created);
        await page.route('**/_inplace/post/new', async route => {
            const data = await formData(route);
            assert.equal(data.get('body').replaceAll('\r\n', '\n'), created);
            await route.fulfill({json: {
                success: true, action: 'create', id: 10, title: 'New HTML post', revision: 1,
                body_html: `<div class="post body" data-post-inplace-body>${created}</div>`,
                published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
                tags: [], scheduled: false, message: 'Saved', url: '/all/new-html-post',
                action_url: '/_inplace/post/10', token: 'created-fixture',
            }});
        });
        await page.keyboard.press('Control+s');
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        assert.equal(await page.locator('.post-card[data-post-id="10"] [data-post-inplace-body]').innerHTML(), created);
        console.log('source: a new post written in HTML saves with Ctrl+S and appears immediately');

        const mediaSource = "<!-- keep spacing -->\n<p class='lead'>Before</p>\n"
            + "<div class='post-picture post-media-picture'><img data-post-media-id='501' src='/old.png' alt='Caption > text'></div>\n";
        await open(mediaSource);
        await htmlMode();
        const mediaEdited = mediaSource + '<p>After</p>';
        await replaceHtml(mediaEdited);
        await page.evaluate(() => { window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing')).uploadedMediaIds.add(501); });
        await page.route('**/_inplace/post/9', async route => {
            const data = await formData(route);
            if (data.get('inplace_action') === 'media_redate') {
                return route.fulfill({json: {success: true, media: [{media_id: 501, url: '/new.png'}]}});
            }
            const expected = mediaEdited.replace("src='/old.png'", 'src="/new.png"');
            assert.equal(data.get('body').replaceAll('\r\n', '\n'), expected);
            await route.fulfill({json: {
                success: true, action: 'edit', title: 'Server title 1', revision: 2,
                body_html: `<div class="post body" data-post-inplace-body>${expected}</div>`,
                published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
                tags: [], scheduled: false, message: 'Saved',
            }});
        });
        await page.keyboard.press('Meta+s');
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        assert.equal(await page.locator('[data-post-inplace-body] img').getAttribute('src'), '/new.png');
        console.log('source: naming an uploaded image updates only its source attribute and keeps the rest of authored HTML exact');
        assert.deepEqual(errors, []);
    } finally {
        await context.close();
    }
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit].filter(engine => !process.env.EDITOR_TEST_BROWSER || engine.name() === process.env.EDITOR_TEST_BROWSER)) {
            const browser = await engine.launch();
            try { await runSourceRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally {
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
}
