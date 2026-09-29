import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {formData, holdRequests} from './save-tests.mjs';

const bodyText = '<p>First paragraph</p>\n<img src="/layout-picture.svg" width="480" height="320" alt="Saved description">\n<p>Last paragraph</p>';
const longBody = Array.from({length: 50}, (_, i) => `<p>Paragraph ${i}</p>`).join('\n');
const previewTemplate = id => ({success: true, template: '<!doctype html><html><head>'
    + '<link rel="stylesheet" href="/preview-layout.css"></head><body data-template="' + id + '">'
    + '<!-- register_title --><!-- register_text --></body></html>'});

async function withPage(browser, origin, options, run) {
    const page = await browser.newPage({viewport: {width: options.mobile ? 760 : 1280, height: 900}});
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    try {
        if (options.layout) {
            for (const name of ['admin-override', 'register']) {
                const css = await readFile(new URL(`../../../_admin/css/${name}.css`, import.meta.url), 'utf8');
                await page.route(`**/layout-${name}.css`, route => route.fulfill({contentType: 'text/css', body: css}));
            }
            await page.route('**/layout-picture.svg', route => route.fulfill({contentType: 'image/svg+xml',
                body: '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="320"><rect width="480" height="320" fill="#8ab4bd"/></svg>'}));
        }
        await page.route('**/admin.html?*', async route => {
            const response = await route.fetch();
            let html = await response.text();
            if (options.layout) {
                // Same wrappers and styles as HtmlTextarea::getHtmlWithWrapper.
                html = html.replace('<form action="/admin-save">', `<form action="/admin-save" class="content-edit-content ${options.creating ? 'is-new' : 'is-edit'}">`)
                    .replace('<link rel="stylesheet" href="/admin-codemirror.css">', '<link rel="stylesheet" href="/layout-admin-override.css"><link rel="stylesheet" href="/layout-register.css"><link rel="stylesheet" href="/admin-codemirror.css">')
                    .replace('<label>Body <textarea id="body" name="body">Server body</textarea></label>',
                        '<section id="id-article-editor-block" class="editor-body-block"><div class="html-textarea-with-preview-wrapper">'
                        + '<div class="html-textarea-wrapper"><label for="body" class="visually-hidden">Body</label>'
                        + '<textarea id="body" name="body">Server body</textarea></div><div class="html-preview-wrapper">Preview</div></div></section>');
            }
            if (options.templates) html = html.replace('<button type="submit">Save</button>',
                '<label>Template <input name="template" value="first"></label><button type="submit">Save</button>');
            await route.fulfill({response, body: html});
        });
        await page.route('**/admin-fixture.js', async route => {
            const response = await route.fetch();
            let source = (await response.text()).replace("sUrl: '/admin-ajax'", "sUrl: '/admin-ajax?'")
                .replace("register_lang: {unsaved_exit: 'Unsaved changes'}", "register_lang: {unsaved_exit: 'Unsaved changes', unknown_error: 'Preview failed'}");
            if (options.layout) source = source
                .replace("Object.assign(editor.getWrapperElement().style, {position: 'relative', width: '500px', height: '160px'});", '')
                .replace('form.before(toolbar);', 'toolbar.innerHTML += \'<button type="button" data-editor-action="fullscreen">Fullscreen</button>\'; form.before(toolbar);');
            if (options.templates) source = source.replace("initArticleEditForm(form, null, 'Post', 'body', 'default');",
                "initArticleEditForm(form, null, 'Post', 'body', null);");
            await route.fulfill({response, body: source});
        });
        await page.route('**/preview-layout.css', route => route.fulfill({contentType: 'text/css', body: 'p {height:80px;margin:0}'}));
        const params = (options.creating ? '' : '&id=9') + (options.codemirror === false ? '' : '&codemirror=1&toolbar=1')
            + (options.layout ? '&alt=1' : '&preview=1');
        await run(page, origin + '/admin.html?fixture=layout-preview' + params);
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
}

export async function runAltLayoutRegressions(browser, origin) {
    for (const mode of ['desktop', 'mobile', 'new', 'fullscreen']) {
        await withPage(browser, origin, {layout: true, mobile: mode === 'mobile', creating: mode === 'new'}, async (page, url) => {
            const saves = holdRequests(page, '**/admin-save*');
            await saves.installed;
            await page.goto(url);
            await page.waitForFunction(() => window.adminEditorReady);
            if (mode === 'fullscreen') {
                await page.getByRole('button', {name: 'Fullscreen', exact: true}).click();
                await page.waitForFunction(() => document.fullscreenElement !== null);
            }
            await page.evaluate(body => {
                window.adminEditor.setValue(body, true);
                const cm = document.querySelector('.CodeMirror').CodeMirror;
                cm.setCursor({line: 1, ch: 8});
                cm.focus();
            }, bodyText);
            await page.waitForFunction(() => document.querySelector('.ai-image-alt-preview > img')?.naturalWidth === 480);
            const geometry = await page.evaluate(() => {
                const editor = document.querySelector('.CodeMirror');
                const panel = document.querySelector('.ai-image-alt-preview');
                const source = editor.getBoundingClientRect();
                const preview = panel.getBoundingClientRect();
                const wrapper = editor.parentElement.getBoundingClientRect();
                const line = editor.querySelector('.CodeMirror-line').getBoundingClientRect();
                return {
                    separate: source.bottom <= preview.top,
                    fits: preview.bottom <= wrapper.bottom + 1,
                    sourceHeight: source.height,
                    outsideEditable: !panel.closest('[contenteditable="true"]'),
                    sourceReceivesClicks: editor.contains(document.elementFromPoint(line.left + 35, line.top + 8)),
                };
            });
            assert.equal(geometry.separate, true, 'The alt panel must not cover source lines');
            assert.equal(geometry.fits, true, 'The panel stays within the editor in narrow and fullscreen layouts');
            assert.ok(geometry.sourceHeight > 100);
            assert.equal(geometry.outsideEditable, true);
            assert.equal(geometry.sourceReceivesClicks, true);
            await page.locator('.ai-image-alt-text').click();
            await page.locator('.ai-image-alt-input').fill('Updated description');
            await page.locator('.ai-image-alt-input').press('Control+s');
            const request = await saves.next();
            const expected = bodyText.replace('Saved description', 'Updated description');
            assert.equal((await formData(request)).get('body').replace(/\r\n/g, '\n'), expected);
            await request.fulfill({status: 503, json: {message: 'Retry later'}});
            await page.waitForFunction(() => document.getElementById('error').textContent === 'Retry later');
            await page.evaluate(() => document.querySelector('.CodeMirror').CodeMirror.setCursor({line: 0, ch: 3}));
            await page.waitForFunction(() => !document.querySelector('.ai-image-alt-preview'));
            await page.evaluate(() => document.querySelector('.CodeMirror').CodeMirror.focus());
            await page.keyboard.insertText('New ');
            await page.waitForFunction(expected => window.adminEditor.getValue() === '<p>New ' + expected.slice(3), expected);
            await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide')));
            assert.equal(await page.evaluate(creating => localStorage.getItem('register_content_draft:post:' + (creating ? 'new' : '9')), mode === 'new'), '<p>New ' + expected.slice(3));
        });
        console.log(`admin alt layout: ${mode} keeps source accessible, saves descriptions and resumes typing`);
    }
}

export async function runTemplateFieldRegressions(browser, origin) {
    for (const codemirror of [false, true]) {
        for (const event of ['input', 'change', 'timer']) {
            await withPage(browser, origin, {templates: true, codemirror}, async (page, url) => {
                await page.clock.install();
                const requests = [];
                await page.route('**/admin-ajax?*', route => {
                    const id = new URL(route.request().url()).searchParams.get('template_id');
                    requests.push(id);
                    return route.fulfill({json: previewTemplate(id)});
                });
                await page.goto(url);
                await page.waitForFunction(() => window.adminEditorReady
                    && document.getElementById('body-preview-frame').contentDocument.body.dataset.template === 'first');
                if (event === 'input') await page.getByLabel('Template', {exact: true}).fill('second');
                else await page.evaluate(event => {
                    const input = document.querySelector('[name="template"]');
                    input.value = 'second';
                    if (event === 'change') input.dispatchEvent(new Event('change', {bubbles: true}));
                }, event);
                await page.clock.fastForward(event === 'timer' ? 5100 : 400);
                await page.waitForLoadState('networkidle');
                assert.equal(await page.evaluate(() => document.getElementById('body-preview-frame').contentDocument.body.dataset.template), 'second');
                assert.deepEqual(requests, ['first', 'second']);
                assert.equal(await page.evaluate(() => localStorage.getItem('register_content_draft:post:9')), null, 'Changing the template must not create a body draft');
                assert.equal(await page.evaluate(() => Boolean(window.onbeforeunload())), true);
                const saves = holdRequests(page, '**/admin-save?id=9');
                await saves.installed;
                await page.getByRole('button', {name: 'Save', exact: true}).click();
                const request = await saves.next();
                const data = await formData(request);
                assert.equal(data.get('template'), 'second');
                assert.equal(data.get('body'), 'Server body');
                await request.fulfill({json: {revision: 2, urlStatus: 'ok', urlTitle: '', url: '/post'}});
                await page.waitForFunction(() => document.querySelector('[name="revision"]').value === '2');
                assert.equal(await page.evaluate(() => window.onbeforeunload()), undefined);
            });
            console.log(`admin template: ${codemirror ? 'CodeMirror' : 'textarea'} ${event} refreshes the preview and saves the field`);
        }
        await withPage(browser, origin, {templates: true, codemirror}, async (page, url) => {
            await page.clock.install();
            const requests = holdRequests(page, '**/admin-ajax?*');
            await requests.installed;
            await page.goto(url);
            await page.waitForFunction(() => window.adminEditorReady);
            await (await requests.next()).fulfill({json: previewTemplate('first')});
            await page.waitForFunction(() => document.getElementById('body-preview-frame').contentDocument.body.dataset.template === 'first');
            await page.getByLabel('Template', {exact: true}).fill('second');
            await page.clock.fastForward(400);
            const stale = await requests.next();
            await page.getByLabel('Template', {exact: true}).fill('third');
            await page.clock.fastForward(400);
            await (await requests.next()).fulfill({json: previewTemplate('third')});
            await page.waitForFunction(() => document.getElementById('body-preview-frame').contentDocument.body.dataset.template === 'third');
            await stale.fulfill({json: previewTemplate('second')});
            await page.waitForLoadState('networkidle');
            assert.equal(await page.evaluate(() => document.getElementById('body-preview-frame').contentDocument.body.dataset.template), 'third');
        });
        console.log(`admin template: ${codemirror ? 'CodeMirror' : 'textarea'} ignores an obsolete template response`);
    }
}

export async function runPreviewDocumentRegressions(browser, origin) {
    for (const fault of [false, true]) {
        await withPage(browser, origin, {templates: true}, async (page, url) => {
            await page.route('**/admin-ajax?*', route => {
                const id = new URL(route.request().url()).searchParams.get('template_id');
                return route.fulfill(id === 'broken' ? {status: 503, json: {success: false}} : {json: previewTemplate(id)});
            });
            await page.goto(url);
            await page.waitForFunction(() => window.adminEditorReady);
            await page.evaluate(body => window.adminEditor.setValue(body), longBody);
            await page.waitForFunction(() => document.getElementById('body-preview-frame').contentDocument.querySelectorAll('#preview-text-wrapper p').length === 50);
            const scrollBothWays = async () => {
                await page.locator('.CodeMirror').hover();
                await page.mouse.wheel(0, 1);
                await page.evaluate(() => document.querySelector('.CodeMirror').CodeMirror.scrollTo(null, 450));
                await page.waitForFunction(() => document.getElementById('body-preview-frame').contentDocument.scrollingElement.scrollTop > 100);
                await page.waitForTimeout(400);
                // Click the visible frame viewport; locating its full body would
                // scroll that document to the top before the pointer event.
                await page.locator('#body-preview-frame').click({position: {x: 10, y: 10}});
                await page.evaluate(() => { document.getElementById('body-preview-frame').contentDocument.scrollingElement.scrollTop = 0; });
                await page.waitForFunction(() => window.adminEditor.getScrollTop() === 0);
                await page.waitForTimeout(400);
                assert.equal(await page.evaluate(() => window.adminEditor.getScrollTop()), 0, 'Old animations must not restart after synchronization settles');
            };
            await scrollBothWays();
            for (const id of ['second', ...(fault ? ['broken'] : []), 'third', 'first']) {
                await page.evaluate(() => { window.oldPreviewRoot = document.getElementById('body-preview-frame').contentDocument.documentElement; });
                await page.getByLabel('Template', {exact: true}).fill(id);
                // Updating a title also triggers replacement on the old implementation,
                // so this regression isolates rebinding from template field detection.
                await page.locator('[name="title"]').fill('Title for ' + id);
                if (id === 'broken') {
                    await page.frameLocator('#body-preview-frame').locator('.editor-preview-error').waitFor();
                    continue;
                }
                await page.waitForFunction(id => document.getElementById('body-preview-frame').contentDocument.body.dataset.template === id, id);
                await page.waitForFunction(() => document.getElementById('body-preview-frame').contentDocument.querySelector('p')?.offsetHeight === 80);
                assert.equal(await page.evaluate(() => window.oldPreviewRoot.isConnected), false);
                await scrollBothWays();
            }
            assert.equal(await page.evaluate(() => window.adminEditor.getValue()), longBody);
        });
        console.log(`admin preview: repeated document replacement${fault ? ' and error recovery' : ''} preserves bidirectional scrolling`);
    }
}

export async function runPreviewLineRegressions(browser, origin) {
    const paragraph = i => `<p>Paragraph ${i}</p>`;
    for (const [mode, prefix, renderParagraph] of [
        ['opening', '', i => `<p\n class="paragraph">Paragraph ${i}</p>`],
        ['closing', '', i => `<p>Paragraph ${i}</p\n>`],
        ['mixed', '<!-- Multiline\ncomment -->\n<br\n>\n', i => `<p\n title="A > B">\nParagraph ${i}</p\n>`],
        ['quoted HTML', '', i => `<p title="A > <span>\ninside attribute">Paragraph ${i}</p>`],
        ['raw style', '<style>/* <p>\n<div> */</style>\n', paragraph],
        ['raw script', '<script>const example = "<div>";\n// <p>\n</script>\n', paragraph],
        ['raw textarea', '<textarea hidden><p>\n<div></textarea>\n', paragraph],
        ['omitted end tags', '', i => `<p>Paragraph ${i}`],
        ['comparison text', 'Comparison: a < b.\n', paragraph],
        ['authored markers', '', i => `<p DATA-REGISTER-SOURCE-LINE="original" data-register-source-line-="also original">Paragraph ${i}</p>`],
        ['nested paragraphs', '', i => `<div title="A > <span>"><p>Paragraph ${i}</p></div>`],
    ]) {
        await withPage(browser, origin, {templates: true}, async (page, url) => {
            await page.route('**/admin-ajax?*', route => route.fulfill({json: previewTemplate('first')}));
            await page.goto(url);
            await page.waitForFunction(() => window.adminEditorReady);
            let body = prefix;
            const lines = [];
            for (let i = 0; i < 50; i++) {
                lines.push(body.split('\n').length - 1);
                body += renderParagraph(i) + (i < 49 ? '\n' : '');
            }
            await page.evaluate(body => window.adminEditor.setValue(body, true), body);
            await page.waitForFunction(() => document.getElementById('body-preview-frame').contentDocument.querySelectorAll('#preview-text-wrapper p').length === 50);
            await page.waitForFunction(() => document.getElementById('body-preview-frame').contentDocument.querySelector('p')?.offsetHeight === 80);
            assert.deepEqual(await page.evaluate(() => Array.from(document.getElementById('body-preview-frame').contentDocument
                .querySelectorAll('#preview-text-wrapper > .line[data-line]'), block => Number(block.dataset.line))), lines);
            assert.equal(await page.evaluate(() => Array.from(document.getElementById('body-preview-frame').contentDocument
                .querySelectorAll('#preview-text-wrapper *')).flatMap(node => Array.from(node.attributes))
                .filter(attribute => attribute.name.startsWith('data-register-source-line')).length), mode === 'authored markers' ? 100 : 0,
            'Temporary source markers must not reach the preview or replace authored attributes');

            await page.locator('.CodeMirror').hover();
            await page.mouse.wheel(0, 1);
            await page.evaluate(line => {
                const cm = document.querySelector('.CodeMirror').CodeMirror;
                cm.scrollTo(null, cm.heightAtLine(line, 'local') - cm.getScrollInfo().clientHeight / 2);
            }, lines[20]);
            await page.waitForFunction(() => document.getElementById('body-preview-frame').contentDocument.scrollingElement.scrollTop > 100);
            await page.waitForTimeout(400);
            const previewMiddle = await page.evaluate(() => {
                const doc = document.getElementById('body-preview-frame').contentDocument;
                const middle = doc.scrollingElement.clientHeight / 2;
                return Array.from(doc.querySelectorAll('#preview-text-wrapper p')).findIndex(p => {
                    const rect = p.getBoundingClientRect();
                    return rect.top <= middle && rect.bottom >= middle;
                });
            });
            assert.ok(Math.abs(previewMiddle - 20) <= 1, `Source paragraph 20 should stay visible, got ${previewMiddle}`);

            await page.locator('#body-preview-frame').click({position: {x: 10, y: 10}});
            await page.evaluate(() => {
                const doc = document.getElementById('body-preview-frame').contentDocument;
                doc.scrollingElement.scrollTop = doc.querySelectorAll('#preview-text-wrapper p')[30].offsetTop - doc.scrollingElement.clientHeight / 2;
            });
            await page.waitForTimeout(400);
            const sourceMiddle = await page.evaluate(() => {
                const cm = document.querySelector('.CodeMirror').CodeMirror;
                return cm.lineAtHeight(cm.getScrollInfo().top + cm.getScrollInfo().clientHeight / 2, 'local');
            });
            assert.ok(sourceMiddle >= lines[29] && sourceMiddle <= lines[31], `Preview paragraph 30 should keep its source visible, got line ${sourceMiddle}`);
            assert.equal(await page.evaluate(() => window.adminEditor.getValue()), body);
            const saves = holdRequests(page, '**/admin-save*');
            await saves.installed;
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            const save = await saves.next();
            assert.equal((await formData(save)).get('body').replace(/\r\n/g, '\n'), body, 'Preview markers must not enter the saved source');
            await save.fulfill({json: {revision: 2, urlStatus: 'ok', urlTitle: '', url: '/post'}});
        });
        console.log(`admin preview: ${mode} retains source lines, bidirectional scrolling and saved HTML`);
    }
}
