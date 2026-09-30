import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {formData, holdRequests} from './save-tests.mjs';

const value = page => page.evaluate(() => window.adminEditor.getValue());
const initial = '<p>Before</p>\n\n<p>After</p>';

async function withEditor(browser, origin, run) {
    const page = await browser.newPage();
    const errors = [];
    page.setDefaultTimeout(10000);
    page.on('pageerror', error => errors.push(String(error)));
    try {
        await page.goto(origin + '/admin.html?id=9&codemirror=1&toolbar=1');
        await page.waitForFunction(() => window.adminEditorReady);
        await run(page);
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
}

async function resetEditor(page, keyMap = 'pcDefault') {
    await page.evaluate(({initial, keyMap}) => {
        window.adminEditor.setValue(initial, true);
        const cm = document.querySelector('.CodeMirror').CodeMirror;
        cm.setOption('keyMap', keyMap);
        cm.setCursor({line: 1, ch: 0});
        cm.clearHistory();
        cm.focus();
    }, {initial, keyMap});
}

async function loadMediaManager(page, origin, manager) {
    // Use the real file-details renderer and Insert button without the
    // unrelated jstree folder browser and upload handlers.
    await page.route('**/media-manager-review.js', route => route.fulfill({
        contentType: 'text/javascript', body: manager.slice(0, manager.indexOf('$(function () {')),
    }));
    await page.evaluate(async () => {
        Object.assign(window, await import('/admin/editor/dialogs.js'));
        window.register_lang = {file: 'File: ', insert: 'Insert', size: 'Size: '};
        const details = document.createElement('div');
        details.id = 'media-file-details';
        document.body.append(details);
    });
    await page.addScriptTag({url: origin + '/media-manager-review.js'});
}

export async function runAdminMediaPathRegressions(browser, origin) {
    const manager = await readFile(new URL('../../../_admin/js/pictman.js', import.meta.url), 'utf8');
    for (const [name, kind, folder, absolute] of [
        ['photo#1.png', 'img', '', false],
        ['recording?1.mp3', 'audio', '', false],
        ['сцена & "100% #?".png', 'img', 'album #1?/nested/', false],
        ['recording%20&2.mp3', 'audio', '', false],
        ['photo?2#3.png', 'img', '', true],
    ]) {
        await withEditor(browser, origin, async page => {
            await resetEditor(page);
            await loadMediaManager(page, origin, manager);
            const path = '/pictures/' + folder + name;
            const raw = (absolute ? origin : '') + path;
            await page.evaluate(({name, raw, kind}) => {
                renderFileInformation(document.getElementById('media-file-details'), name, raw,
                    '', kind === 'img' ? '80*60' : '', '24');
            }, {name, raw, kind});
            const link = new URL(await page.locator('#media-file-details a').getAttribute('href'), origin);
            assert.equal(decodeURIComponent(link.pathname), path, 'The media-library link addresses the complete filename');
            assert.equal(link.search + link.hash, '', 'Filename characters must not become a query or fragment');

            await page.getByRole('button', {name: 'Insert', exact: true}).click();
            const expected = await value(page);
            const media = await page.evaluate(({html, kind}) => {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const element = doc.querySelector(kind);
                return {src: element?.getAttribute('src'), count: doc.querySelectorAll('img,audio').length,
                    title: element?.getAttribute('data-title')};
            }, {html: expected, kind});
            const inserted = new URL(media.src, origin);
            assert.equal(decodeURIComponent(inserted.pathname), path, 'Inserted HTML must retain the same file as the library');
            assert.equal(inserted.search + inserted.hash, '');
            assert.equal(inserted.href, link.href);
            assert.equal(media.count, 1);
            if (kind === 'audio') assert.equal(media.title, name.replace(/\.mp3$/, ''));

            // Verify the URL actually requested by a browser rendering the saved
            // markup, rather than checking only its source representation.
            // Use a fresh document/context so WebKit cannot reuse or coalesce
            // the media request already made by the library details panel.
            const previewPage = await browser.newPage();
            previewPage.setDefaultTimeout(10000);
            try {
                await previewPage.route('**/pictures/**', route => route.fulfill({status: 204}));
                await previewPage.goto(origin + '/recovery.html');
                // WebKit reports metadata audio loads as "other".
                const request = previewPage.waitForRequest(request => ['image', 'media', 'other'].includes(request.resourceType())
                    && new URL(request.url()).pathname.startsWith('/pictures/'));
                await previewPage.evaluate(html => {
                    const preview = document.createElement('div');
                    preview.innerHTML = html;
                    preview.querySelector('img')?.setAttribute('loading', 'eager');
                    document.body.append(preview);
                    preview.querySelector('audio')?.load();
                }, expected);
                const requested = new URL((await request).url());
                assert.equal(decodeURIComponent(requested.pathname), path);
                assert.equal(requested.search + requested.hash, '');
            } finally { await previewPage.close(); }

            await page.getByRole('button', {name: 'Undo', exact: true}).click();
            assert.equal(await value(page), initial);
            await page.getByRole('button', {name: 'Redo', exact: true}).click();
            assert.equal(await value(page), expected);
            await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide')));
            assert.equal(await page.evaluate(() => window.readAdminDraft('9')), expected);
            const saves = holdRequests(page, '**/admin-save?id=9');
            await saves.installed;
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            const save = await saves.next();
            assert.equal((await formData(save)).get('body').replace(/\r\n/g, '\n'), expected);
            await save.fulfill({status: 503, json: {message: 'Retry later'}});
        });
    }
    console.log('admin media paths: special filenames survive library links, insertion, browser requests, history, recovery and saving');
}

export async function runAdminAudioInsertionRegressions(browser, origin) {
    const manager = await readFile(new URL('../../../_admin/js/pictman.js', import.meta.url), 'utf8');
    const audio = '<audio controls preload="metadata" src="/pictures/voice.mp3" data-title="Voice"></audio>';
    for (const selection of ['', 'Caption <em>text</em>']) {
        await withEditor(browser, origin, async page => {
            const before = `<p>Before</p>\n${selection}\n<p>After</p>`;
            await page.evaluate(({before, selection}) => {
                adminEditor.setValue(before, true);
                const cm = document.querySelector('.CodeMirror').CodeMirror;
                cm.setSelection({line: 1, ch: 0}, {line: 1, ch: selection.length});
                cm.focus();
            }, {before, selection});
            await loadMediaManager(page, origin, manager);
            await page.evaluate(() => renderFileInformation(document.getElementById('media-file-details'),
                'Voice.mp3', '/pictures/voice.mp3', '', '', '24'));
            await page.getByRole('button', {name: 'Insert', exact: true}).click();
            const first = `<p>Before</p>\n${audio}${selection}\n<p>After</p>`;
            assert.equal(await value(page), first);
            // The inserted source remains selected when the library is opened
            // again. Inserting the same file must not toggle that audio off.
            await page.getByRole('button', {name: 'Insert', exact: true}).click();
            const expected = `<p>Before</p>\n${audio}${audio}${selection}\n<p>After</p>`;
            assert.equal(await value(page), expected, 'Repeated insertion retains existing audio and neighbouring text');
            await page.getByRole('button', {name: 'Undo', exact: true}).click();
            assert.equal(await value(page), first, 'Undo removes only the latest insertion');
            await page.getByRole('button', {name: 'Redo', exact: true}).click();
            assert.equal(await value(page), expected);
            await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide')));
            assert.equal(await page.evaluate(() => window.readAdminDraft('9')), expected);
            const saves = holdRequests(page, '**/admin-save?id=9');
            await saves.installed;
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            const save = await saves.next();
            assert.equal((await formData(save)).get('body').replace(/\r\n/g, '\n'), expected);
            await save.fulfill({status: 503, json: {message: 'Retry later'}});
        });
    }
    console.log('admin audio insertion: repeated library inserts retain audio, selected text, history, recovery and saved HTML');
}

export async function runAdminShortcutModifierRegressions(browser, origin) {
    for (const keyMap of ['pcDefault', 'macDefault']) {
        await withEditor(browser, origin, async page => {
            const saves = holdRequests(page, '**/admin-save?id=9');
            await saves.installed;
            for (const key of ['Control+Alt+q', 'Control+Alt+b', 'Control+Alt+e', 'Control+Alt+k',
                'Control+Meta+b', 'Control+Alt+s', 'Meta+Alt+s', 'Control+Meta+s']) {
                await resetEditor(page, keyMap);
                await page.keyboard.press(key);
                assert.equal(await value(page), initial, `${key} must not insert HTML commands`);
                assert.equal(saves.count, 0, `${key} must not save`);
                assert.deepEqual(await page.evaluate(() => document.querySelector('.CodeMirror').CodeMirror.historySize()),
                    {undo: 0, redo: 0}, `${key} must not add history entries`);
            }
            // Composition and AltGraph state cannot be generated by Playwright's
            // keyboard; deliver these browser event flags to the real listeners.
            for (const state of [{isComposing: true}, {modifierAltGraph: true}]) {
                for (const key of ['b', 's']) {
                    await resetEditor(page, keyMap);
                    await page.evaluate(({key, state}) => {
                        document.querySelector('.CodeMirror').CodeMirror.getInputField().dispatchEvent(new KeyboardEvent('keydown', {
                            bubbles: true, cancelable: true, ctrlKey: true, key, code: 'Key' + key.toUpperCase(),
                            keyCode: key.toUpperCase().charCodeAt(0), which: key.toUpperCase().charCodeAt(0), ...state,
                        }));
                    }, {key, state});
                    assert.equal(await value(page), initial, 'Composition and AltGraph must not format text');
                    assert.equal(saves.count, 0, 'Composition and AltGraph must not submit the form');
                }
            }
            await resetEditor(page, keyMap);
            await page.keyboard.press('Control+b');
            const formatted = initial.replace('\n\n', '\n<strong></strong>\n');
            assert.equal(await value(page), formatted, 'The exact formatting shortcut still works');
            await page.getByRole('button', {name: 'Undo', exact: true}).click();
            assert.equal(await value(page), initial);
            await page.getByRole('button', {name: 'Redo', exact: true}).click();
            assert.equal(await value(page), formatted);
            for (const [index, modifier] of ['Control', 'Meta'].entries()) {
                await page.keyboard.press(modifier + '+s');
                const save = await saves.next();
                assert.equal((await formData(save)).get('body').replace(/\r\n/g, '\n'), formatted);
                await save.fulfill({json: {revision: index + 2, urlStatus: 'ok', urlTitle: '', url: '/post'}});
                await page.waitForFunction(revision => document.querySelector('[name="revision"]').value === String(revision), index + 2);
            }
        });
    }
    console.log('admin shortcut modifiers: additional modifiers and composition do not format or save; exact shortcuts retain history and saving');
}
