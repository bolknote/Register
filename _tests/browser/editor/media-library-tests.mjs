import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {formData, holdRequests} from './save-tests.mjs';

const markup = `<!doctype html><html lang="en"><meta charset="utf-8"><title>Media library</title>
<section data-picture-manager data-ajax-url="/library-api?" data-picture-prefix="/pictures"
    data-max-file-size="1048576" data-empty-directory="Empty directory" data-file-count="{{ visible }}/{{ total }}">
    <form id="uploadForm"><div id="loading_pict"></div><span id="fold_name"></span>
        <input type="file" name="pictures[]" multiple><span data-media-upload-selection></span>
        <input type="hidden" name="dir"><input type="hidden" name="csrf_token">
    </form>
    <div id="folders"></div><span data-media-folder-path></span><button type="button" data-media-refresh>Refresh</button>
    <input type="search" data-media-search><select data-media-type><option value="all">All</option></select>
    <span data-media-count></span><div data-media-selection-bar hidden><span data-media-selected-count></span>
        <button type="button" data-media-delete>Delete files</button></div>
    <div id="brd"><div id="files"></div><div id="loadstatus"></div>
        <div data-media-empty hidden></div><div data-media-no-matches hidden></div></div>
    <div id="finfo"></div>
</section>
<dialog data-admin-confirm-dialog><form method="dialog">
    <h2 data-admin-confirm-title></h2><p data-admin-confirm-message></p>
    <button value="cancel" data-admin-confirm-cancel>Cancel</button>
    <button value="confirm" data-admin-confirm-submit>Delete permanently</button>
</form></dialog>
<script src="/library/lang.js"></script><script src="/admin-fetch.js"></script>
<script src="/library/confirm.js"></script>
<script src="/library/ajax.js"></script><script src="/library/jquery.js"></script>
<script src="/library/jquery-tools.js"></script><script src="/library/jquery.jstree.js"></script>
<script src="/library/pictman.js"></script></html>`;

async function openLibrary(page, origin, folders = []) {
    const errors = [];
    page.setDefaultTimeout(10000);
    page.on('pageerror', error => errors.push(String(error)));
    await page.addInitScript(() => {
        window.libraryMessages = [];
        window.PopupMessages = {show: message => window.libraryMessages.push(message)};
        window.DisplayError = message => window.libraryMessages.push(message);
        window.libraryInsertedImages = [];
        window.ReturnImage = (...args) => window.libraryInsertedImages.push(args);
    });
    const scripts = new Map(await Promise.all([
        ['lang.js', 'lang/en/ui.js'], ['ajax.js', 'js/ajax.js'], ['pictman.js', 'js/pictman.js'],
        ['jquery.js', 'lib/jquery.js'], ['jquery-tools.js', 'lib/jquery-tools.js'], ['jquery.jstree.js', 'lib/jquery.jstree.js'],
    ].map(async ([name, path]) => [name, await readFile(new URL('../../../_admin/' + path, import.meta.url), 'utf8')])));
    const adminLib = await readFile(new URL('../../../_admin/js/lib.js', import.meta.url), 'utf8');
    scripts.set('confirm.js', adminLib.slice(adminLib.indexOf('window.AdminConfirm'), adminLib.indexOf('window.PopupMessages')));
    await page.route('**/library/*', route => route.fulfill({contentType: 'text/javascript',
        body: scripts.get(new URL(route.request().url()).pathname.split('/').pop()) || ''}));
    await page.route('**/media-library.html', route => route.fulfill({contentType: 'text/html', body: markup}));
    const files = ['image10.png', 'image2.png'];
    const filesByPath = new Map([['', files], ...folders.map(folder => [folder.attr['data-path'], ['photo.png']])]);
    await page.route('**/library-api?*', route => {
        const action = new URL(route.request().url()).searchParams.get('action');
        if (action === 'load_folders') return route.fulfill({json: [
            {data: 'Pictures', attr: {id: 'node_1', 'data-path': '', 'data-csrf-token': 'fixture'}, children: folders},
        ]});
        if (action === 'load_files') {
            const names = filesByPath.get(new URL(route.request().url()).searchParams.get('path')) || [];
            return route.fulfill({json: names.map(name => ({data: name, attr: {'data-fname': name, 'data-dim': '80*60'}}))});
        }
        return route.fallback();
    });
    const uploads = holdRequests(page, '**/library-api?action=upload');
    await uploads.installed;
    await page.goto(origin + '/media-library.html');
    await page.waitForFunction(() => document.querySelectorAll('#files li[data-fname]').length === 2
        && document.querySelector('#folders .jstree-clicked'));
    await page.waitForFunction(() => !document.getElementById('loading_pict').classList.contains('is-active'));
    return {errors, files, filesByPath, uploads};
}

async function upload(page, name, drop) {
    if (!drop) {
        await page.locator('input[type="file"]').setInputFiles({name, mimeType: 'image/png', buffer: Buffer.from(name)});
        return;
    }
    await page.evaluate(name => {
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(new File([name], name, {type: 'image/png'}));
        document.getElementById('brd').dispatchEvent(new DragEvent('drop', {bubbles: true, cancelable: true, dataTransfer}));
    }, name);
}

const busy = page => page.locator('#loading_pict').evaluate(element => element.classList.contains('is-active'));
const nextTask = page => page.evaluate(() => new Promise(resolve => setTimeout(resolve, 0)));

export async function runMediaLibrarySortRegressions(browser, origin) {
    const page = await browser.newPage();
    try {
        const library = await openLibrary(page, origin);
        const filenames = () => page.locator('#files li[data-fname]').evaluateAll(nodes => nodes.map(node => node.dataset.fname));
        assert.deepEqual(await filenames(), ['image2.png', 'image10.png'], 'Numbered files use natural order');
        library.files.push('image1.png', 'image20.png', 'image9007199254740993.png', 'image9007199254740992.png');
        await page.getByRole('button', {name: 'Refresh', exact: true}).click();
        await page.waitForFunction(() => document.querySelectorAll('#files li[data-fname]').length === 6);
        assert.deepEqual(await filenames(), ['image1.png', 'image2.png', 'image10.png', 'image20.png',
            'image9007199254740992.png', 'image9007199254740993.png'], 'Refreshing retains numeric order without rounding long numbers');
        assert.deepEqual(library.errors, []);
    } finally { await page.close(); }
    console.log('media library: numbered filenames retain natural order before and after refresh');
}

export async function runMediaLibraryUploadRegressions(browser, origin) {
    for (const [name, firstDrop, secondDrop, reverse] of [
        ['file selections', false, false, false], ['reversed file selections', false, false, true],
        ['drops', true, true, false], ['reversed drops', true, true, true],
        ['selection then drop', false, true, false], ['drop then selection', true, false, false],
    ]) {
        const page = await browser.newPage();
        try {
            const library = await openLibrary(page, origin);
            await upload(page, 'first.png', firstDrop);
            const first = await library.uploads.next();
            await upload(page, 'second.png', secondDrop);
            const second = await library.uploads.next();
            assert.equal((await formData(first)).get('pictures[]').name, 'first.png');
            assert.equal((await formData(second)).get('pictures[]').name, 'second.png');
            assert.equal(await busy(page), true);
            const [completed, pending] = reverse ? [second, first] : [first, second];
            library.files.push(reverse ? 'second.png' : 'first.png');
            const response = page.waitForResponse(response => response.url() === completed.request().url());
            await completed.fulfill({json: {success: true}});
            await response;
            await nextTask(page);
            assert.equal(await busy(page), true, 'Completing one batch must not finish another pending upload');
            assert.equal(await page.locator('[data-media-upload-selection]').textContent(),
                !secondDrop && !reverse ? 'second.png' : '',
                'An earlier reply must not clear a newer file selection');

            // A normal jQuery folder refresh must not hide a pending fetch upload.
            await page.getByRole('button', {name: 'Refresh', exact: true}).click();
            await page.waitForFunction(() => document.querySelectorAll('#files li[data-fname]').length === 3);
            assert.equal(await busy(page), true, 'Refreshing the library retains upload progress');
            library.files.push(reverse ? 'first.png' : 'second.png');
            await pending.fulfill({json: {success: true}});
            await page.waitForFunction(() => document.querySelectorAll('#files li[data-fname]').length === 4);
            await page.waitForFunction(() => !document.getElementById('loading_pict').classList.contains('is-active'));
            assert.deepEqual(library.errors, []);
        } finally { await page.close(); }
        console.log(`media library: overlapping ${name} retain progress and refresh every completed upload`);
    }
}

export async function runMediaLibraryFailureRegressions(browser, origin) {
    const fallback = 'Unable to upload files. Please try again.';
    for (const [name, reply, expected] of [
        ['network', null, fallback],
        ['HTML error', {status: 500, contentType: 'text/html', body: '<h1>Unavailable</h1>'}, fallback],
        ['invalid JSON', {body: 'not JSON'}, fallback],
        ['empty errors', {json: {success: false, errors: []}}, fallback],
        ['missing payload', {json: null}, fallback],
        ['HTTP failure', {status: 503, json: {success: true}}, fallback],
        ['server message', {status: 422, json: {success: false, message: 'The file is too large.'}}, 'The file is too large.'],
        ['server errors', {json: {success: false, errors: ['First error', 'Second error']}}, 'First error\nSecond error'],
    ]) {
        const page = await browser.newPage();
        try {
            const library = await openLibrary(page, origin);
            await upload(page, 'retry.png', false);
            const request = await library.uploads.next();
            if (reply) await request.fulfill(reply);
            else await request.abort('failed');
            await page.waitForFunction(() => !document.getElementById('loading_pict').classList.contains('is-active'));
            assert.deepEqual(await page.evaluate(() => window.libraryMessages), [expected],
                `${name}: failed uploads must show one useful error`);
            assert.equal(await page.locator('input[type="file"]').evaluate(input => input.files.length), 0,
                'The chooser must accept the same file again after failure');
            assert.equal(await page.locator('[data-media-upload-selection]').textContent(), '');
            await upload(page, 'retry.png', false);
            const retry = await library.uploads.next();
            assert.equal((await formData(retry)).get('pictures[]').name, 'retry.png');
            library.files.push('retry.png');
            await retry.fulfill({json: {success: true}});
            await page.waitForFunction(() => document.querySelectorAll('#files li[data-fname]').length === 3);
            assert.deepEqual(library.errors, []);
        } catch (error) {
            throw new Error(`media upload ${name}: ${error.message}`, {cause: error});
        } finally { await page.close(); }
    }
    console.log('media library: network, HTTP and malformed responses report one error and allow retrying the same file');
}

export async function runMediaLibraryFolderRegressions(browser, origin) {
    const folder = name => ({data: name, attr: {'data-path': '/' + name, 'data-csrf-token': name + '-token'}});
    for (const stale of ['success', 'empty', 'network', 'http-error', 'same-folder', 'round-trip']) {
        const page = await browser.newPage();
        try {
            const library = await openLibrary(page, origin, [folder('first'), folder('second')]);
            const loads = holdRequests(page, '**/library-api?action=load_files&path=*');
            await loads.installed;
            await page.locator('#folders [data-path="/first"] > a').click();
            const first = await loads.next();
            assert.equal(new URL(first.request().url()).searchParams.get('path'), '/first');
            if (stale === 'same-folder') await page.getByRole('button', {name: 'Refresh', exact: true}).click();
            else await page.locator('#folders [data-path="/second"] > a').click();
            let second = await loads.next();
            let selectedPath = stale === 'same-folder' ? '/first' : '/second';
            assert.equal(new URL(second.request().url()).searchParams.get('path'), selectedPath);
            if (stale === 'round-trip') {
                await page.locator('#folders [data-path="/first"] > a').click();
                const latest = await loads.next();
                await second.fulfill({json: []});
                second = latest;
                selectedPath = '/first';
            }
            assert.equal(await page.locator('#files').getAttribute('aria-busy'), 'true',
                'Cancelling the old request must retain the current loading state');
            assert.equal(await page.locator('#files li[data-fname]').count(), 0,
                'Files from another folder must not be actionable while the new list is pending');
            assert.deepEqual(await page.evaluate(() => window.libraryMessages), [], 'Cancellation must not show an error');
            await second.fulfill({json: [{data: 'second.png', attr: {'data-fname': 'second.png', 'data-dim': '80*60'}}]});
            await page.waitForFunction(() => document.querySelector('#files [data-fname="second.png"]'));
            if (stale === 'network') await first.abort('failed');
            else if (stale === 'http-error') await first.fulfill({status: 503, json: {message: 'Old failure'}});
            else await first.fulfill({json: stale === 'empty' ? [] : [
                {data: 'first.png', attr: {'data-fname': 'first.png', 'data-dim': '80*60'}},
            ]});
            await page.waitForFunction(() => jQuery.active === 0);
            await nextTask(page);
            assert.deepEqual(await page.locator('#files li[data-fname]').evaluateAll(nodes => nodes.map(node => node.dataset.fname)),
                ['second.png'], `${stale}: a late folder reply must not replace the selected folder`);
            assert.equal(await page.locator('#loadstatus').textContent(), '', 'An outdated failure must not replace the current status');
            assert.deepEqual(await page.evaluate(() => window.libraryMessages), []);
            await page.locator('#files [data-fname="second.png"] > a').click();
            assert.equal(new URL(await page.locator('#finfo a').getAttribute('href'), origin).pathname, '/pictures' + selectedPath + '/second.png');
            assert.deepEqual(library.errors, []);
        } catch (error) {
            throw new Error(`media folder ${stale}: ${error.message}`, {cause: error});
        } finally { await page.close(); }
    }
    console.log('media library: late folder successes, empty lists and failures retain the selected folder and its file URLs');
}

export async function runMediaLibraryFolderMutationRegressions(browser, origin) {
    for (const action of ['rename', 'move']) {
        for (const selection of ['folder', 'child', 'other']) {
            const page = await browser.newPage();
            try {
                const folders = [
                    {data: 'first', state: 'open', attr: {'data-path': '/first', 'data-csrf-token': 'first-token'}, children: [
                        {data: 'child', attr: {'data-path': '/first/child', 'data-csrf-token': 'child-token'}},
                    ]},
                    {data: 'second', attr: {'data-path': '/second', 'data-csrf-token': 'second-token'}},
                ];
                const library = await openLibrary(page, origin, folders);
                library.filesByPath.set('/first/child', ['photo.png']);
                await page.locator('#folders [data-path="/first"] > a').click();
                await page.waitForFunction(() => document.querySelector('#files [data-fname="photo.png"]'));
                await page.locator('#files [data-fname="photo.png"] > a').click();
                assert.equal(new URL(await page.locator('#finfo a').getAttribute('href'), origin).pathname, '/pictures/first/photo.png');
                const mutations = holdRequests(page, `**/library-api?action=${action}_folder&*`);
                await mutations.installed;
                if (action === 'rename') {
                    // Open the production tree's inline rename input, then use
                    // native typing and Enter to submit the new folder name.
                    await page.evaluate(() => jQuery('#folders').jstree('rename', jQuery('#folders [data-path="/first"]')));
                    await page.locator('#folders input').fill('renamed');
                    await page.locator('#folders input').press('Enter');
                } else {
                    // Use the real tree operation called by its drag handler.
                    await page.evaluate(() => jQuery('#folders').jstree('move_node',
                        jQuery('#folders [data-path="/first"]'), jQuery('#folders [data-path="/second"]'), 'last'));
                }
                const request = await mutations.next();
                const newPath = action === 'rename' ? '/renamed' : '/second/first';
                const current = selection === 'child' ? '/first/child' : selection === 'other' ? '/second' : '/first';
                if (selection !== 'folder') {
                    await page.locator(`#folders [data-path="${current}"] > a`).click();
                    await page.waitForFunction(() => jQuery.active === 0 && document.querySelector('#files [data-fname="photo.png"]'));
                    await page.locator('#files [data-fname="photo.png"] > a').click();
                }
                const expected = selection === 'other' ? '/second' : newPath + (selection === 'child' ? '/child' : '');
                library.filesByPath.set(newPath, ['photo.png']);
                library.filesByPath.set(newPath + '/child', ['photo.png']);
                await request.fulfill({json: {success: true, new_path: newPath, csrf_token: 'first-token'}});
                await page.waitForFunction(newPath => document.querySelector(`#folders [data-path="${newPath}"]`), newPath);
                const previousLink = await page.locator('#finfo a').evaluateAll(links => links[0]?.getAttribute('href') || null);
                assert.ok(previousLink === null || new URL(previousLink, origin).pathname === '/pictures' + expected + '/photo.png',
                    `${action}/${selection}: the old folder URL must not remain insertable after a successful change`);
                await page.waitForFunction(() => jQuery.active === 0 && document.querySelector('#files [data-fname="photo.png"]'));
                await page.locator('#files [data-fname="photo.png"] > a').click();
                assert.equal(new URL(await page.locator('#finfo a').getAttribute('href'), origin).pathname, '/pictures' + expected + '/photo.png');
                assert.equal(await page.locator('[data-media-folder-path]').textContent(), expected);
                await page.getByRole('button', {name: '← Insert', exact: true}).click();
                assert.equal(await page.evaluate(() => window.libraryInsertedImages.at(-1)[0]), '/pictures' + expected + '/photo.png');
                await upload(page, 'after.png', false);
                const uploadRequest = await library.uploads.next();
                const uploaded = await formData(uploadRequest);
                assert.equal(uploaded.get('dir'), expected);
                assert.equal(uploaded.get('csrf_token'), selection === 'child' ? 'child-token' : selection === 'other' ? 'second-token' : 'first-token');
                library.filesByPath.get(expected).push('after.png');
                await uploadRequest.fulfill({json: {success: true}});
                await page.waitForFunction(() => document.querySelector('#files [data-fname="after.png"]'));
                assert.deepEqual(library.errors, []);
            } catch (error) {
                throw new Error(`media folder ${action}/${selection}: ${error.message}`, {cause: error});
            } finally { await page.close(); }
        }
    }
    console.log('media library: renamed and moved folders retain the selected branch, current URLs and upload destination');
}

export async function runMediaLibraryFolderFailureRegressions(browser, origin) {
    for (const [name, reply, hasError, messages] of [
        ['null JSON', {json: null}, true],
        ['invalid JSON', {body: 'not JSON'}, true],
        ['HTTP error', {status: 503, json: {message: 'Unavailable'}}, true],
        ['HTML authentication error', {status: 401, contentType: 'text/html', body: '<h1>Sign in</h1>'}, true, ['<h1>Sign in</h1>']],
        ['HTML permission error', {status: 403, contentType: 'text/html', body: '<h1>Forbidden</h1>'}, true, ['<h1>Forbidden</h1>']],
        ['null permission error', {status: 403, json: null}, true, ['null']],
        ['malformed permission errors', {status: 403, json: {errors: {file: 'Forbidden'}}}, true, ['{"errors":{"file":"Forbidden"}}']],
        ['authentication message', {status: 401, json: {message: 'Sign in again.'}}, true, ['Sign in again.']],
        ['permission messages', {status: 403, json: {errors: [null, 'Permission denied.', {}, '']}}, true, ['Permission denied.']],
        ['network', null, true],
        ['empty directory', {json: {message: 'Empty directory'}}, false],
        ['empty list', {json: []}, false],
    ]) {
        const page = await browser.newPage();
        let library;
        try {
            library = await openLibrary(page, origin, [
                {data: 'first', attr: {'data-path': '/first', 'data-csrf-token': 'first-token'}},
            ]);
            const pattern = '**/library-api?action=load_files&path=*';
            const loads = holdRequests(page, pattern);
            await loads.installed;
            await page.locator('#folders [data-path="/first"] > a').click();
            const request = await loads.next();
            if (reply) await request.fulfill(reply);
            else await request.abort('failed');
            await page.waitForFunction(() => jQuery.active === 0);
            assert.deepEqual(library.errors, [], `${name}: invalid file responses must not throw in the browser`);
            if (messages) assert.deepEqual(await page.evaluate(() => window.libraryMessages), messages,
                `${name}: authentication failures must show a useful error exactly once`);
            assert.equal(await page.locator('#files').getAttribute('aria-busy'), null);
            assert.equal(await page.locator('#files li[data-fname]').count(), 0);
            assert.equal(await page.locator('#loadstatus').textContent(), hasError ? 'Unknown error' : '');
            assert.equal(await page.locator('[data-media-empty]').evaluate(element => element.hidden), hasError,
                'A failed request must not be presented as an empty folder');
            await page.unroute(pattern);
            await page.getByRole('button', {name: 'Refresh', exact: true}).click();
            await page.waitForFunction(() => jQuery.active === 0 && document.querySelector('#files [data-fname="photo.png"]'));
            assert.equal(await page.locator('#loadstatus').textContent(), '');
            assert.equal(await page.locator('[data-media-empty]').evaluate(element => element.hidden), true);
            await page.locator('#files [data-fname="photo.png"] > a').click();
            assert.equal(new URL(await page.locator('#finfo a').getAttribute('href'), origin).pathname, '/pictures/first/photo.png');
            assert.deepEqual(library.errors, []);
        } catch (error) {
            throw new Error(`media folder load ${name}: ${error.message}; browser errors: ${library?.errors.join('; ')}`, {cause: error});
        } finally { await page.close(); }
    }
    console.log('media library: current folder failures and empty lists clear loading state and allow a successful refresh');
}

export async function runMediaLibraryLiteralNameRegressions(browser, origin) {
    for (const kind of ['file', 'folder']) {
        const page = await browser.newPage();
        try {
            const name = kind === 'file' ? 'снимок %s $& $$.png' : 'архив %s $& $$';
            const library = await openLibrary(page, origin, kind === 'folder' ? [
                {data: name, attr: {id: 'literal-folder', 'data-path': '/' + name, 'data-csrf-token': 'folder-token'}},
            ] : []);
            if (kind === 'file') {
                library.files.splice(0, library.files.length, name);
                await page.getByRole('button', {name: 'Refresh', exact: true}).click();
                await page.waitForFunction(name => document.querySelectorAll('#files li[data-fname]').length === 1
                    && document.querySelector('#files li[data-fname]').dataset.fname === name, name);
                await page.locator('#files li[data-fname] > a').click();
            } else {
                await page.locator('#literal-folder > a').click();
                await page.waitForFunction(() => jQuery.active === 0 && document.querySelector('#files [data-fname="photo.png"]'));
            }
            const deletes = holdRequests(page, `**/library-api?action=delete_${kind === 'file' ? 'files' : 'folder'}&*`);
            await deletes.installed;
            const deleteButton = kind === 'file' ? page.getByRole('button', {name: 'Delete files', exact: true})
                : page.locator('#context_delete');
            const dialog = page.locator('[data-admin-confirm-dialog]');
            await deleteButton.click();
            assert.equal(await dialog.locator('[data-admin-confirm-message]').textContent(), kind === 'file'
                ? `The following will be permanently deleted: ${name}. This action cannot be undone.`
                : `“${name}” and all nested items will be permanently deleted. This action cannot be undone.`);
            await dialog.locator('[data-admin-confirm-cancel]').click();
            assert.equal(deletes.count, 0, 'Cancelling must keep the literal name intact without deleting it');
            assert.equal(await page.locator(kind === 'file' ? '#files li[data-fname]' : '#literal-folder').count(), 1);
            await deleteButton.click();
            await dialog.locator('[data-admin-confirm-submit]').click();
            const request = await deletes.next();
            const params = new URL(request.request().url()).searchParams;
            assert.equal(params.get('path'), kind === 'file' ? '' : '/' + name);
            if (kind === 'file') assert.deepEqual(params.getAll('fname[]'), [name]);
            assert.equal((await formData(request)).get('csrf_token'), kind === 'file' ? 'fixture' : 'folder-token');
            if (kind === 'file') library.files.length = 0;
            else library.filesByPath.delete('/' + name);
            const done = page.waitForResponse(response => response.url() === request.request().url()).then(response => response.finished());
            await request.fulfill({json: {success: true}});
            await done;
            await nextTask(page);
            await page.waitForFunction(() => jQuery.active === 0);
            assert.equal(await page.locator(kind === 'file' ? '#files li[data-fname]' : '#literal-folder').count(), 0);
            assert.deepEqual(library.errors, []);
            assert.deepEqual(await page.evaluate(() => window.libraryMessages), []);
        } catch (error) {
            throw new Error(`media library literal ${kind} name: ${error.message}`, {cause: error});
        } finally { await page.close(); }
    }
    console.log('media library: deletion dialogs preserve placeholders and dollar signs in file and folder names');
}

export async function runMediaLibraryFileSelectionRegressions(browser, origin) {
    const page = await browser.newPage();
    try {
        const library = await openLibrary(page, origin);
        const first = page.locator('#files [data-fname="image2.png"] > a');
        const second = page.locator('#files [data-fname="image10.png"] > a');
        await first.click();
        await second.click({modifiers: ['Shift']});
        assert.equal(await page.locator('#files a.jstree-clicked').count(), 2);
        assert.equal(await page.locator('#finfo a').count(), 0);
        await page.locator('[data-media-search]').fill('image2');
        assert.equal(await page.locator('#files a.jstree-clicked').count(), 1);
        assert.equal(await page.locator('#finfo a').count(), 1,
            'Filtering back to one selected file must restore its information and insertion action');
        assert.equal(new URL(await page.locator('#finfo a').getAttribute('href'), origin).pathname, '/pictures/image2.png');
        await page.getByRole('button', {name: '← Insert', exact: true}).click();
        assert.equal(await page.evaluate(() => window.libraryInsertedImages.at(-1)[0]), '/pictures/image2.png');
        await page.locator('[data-media-search]').fill('missing');
        assert.equal(await page.locator('#finfo a').count(), 0);
        assert.equal(await page.locator('[data-media-selection-bar]').evaluate(element => element.hidden), true);
        assert.deepEqual(library.errors, []);
    } finally { await page.close(); }
    console.log('media library: reducing multiple selections to one restores that file’s details and insertion action');
}

export async function runMediaLibraryFileRenameRegressions(browser, origin) {
    for (const [nextAction, success] of [
        ['stay', true], ['refresh', true], ['other-folder', true], ['round-trip', true],
        ['other-folder', false], ['refresh', false],
    ]) {
        const page = await browser.newPage();
        try {
            const library = await openLibrary(page, origin, [
                {data: 'second', attr: {'data-path': '/second', 'data-csrf-token': 'second-token'}},
            ]);
            const renames = holdRequests(page, '**/library-api?action=rename_file&*');
            await renames.installed;
            await page.locator('#files [data-fname="image2.png"] > a').click();
            await page.evaluate(() => jQuery('#files').jstree('rename', jQuery('#files [data-fname="image2.png"]')));
            await page.locator('#files input').fill('renamed.png');
            await page.locator('#files input').press('Enter');
            const request = await renames.next();
            const params = new URL(request.request().url()).searchParams;
            assert.equal(params.get('path'), '/image2.png');
            assert.equal(params.get('name'), 'renamed.png');
            assert.equal((await formData(request)).get('csrf_token'), 'fixture');
            if (nextAction === 'refresh') {
                await page.getByRole('button', {name: 'Refresh', exact: true}).click();
                await page.waitForFunction(() => jQuery.active === 0 && document.querySelector('#files [data-fname="image2.png"]'));
            } else if (nextAction !== 'stay') {
                await page.locator('#folders [data-path="/second"] > a').click();
                await page.waitForFunction(() => document.querySelector('#files [data-fname="photo.png"]'));
                await page.locator('#files [data-fname="photo.png"] > a').click();
                if (nextAction === 'round-trip') {
                    await page.locator('#folders [data-path=""] > a').click();
                    await page.waitForFunction(() => jQuery.active === 0 && document.querySelector('#files [data-fname="image2.png"]'));
                }
            }
            if (success) library.files.splice(library.files.indexOf('image2.png'), 1, 'renamed.png');
            const response = page.waitForResponse(response => response.url() === request.request().url());
            await request.fulfill(success ? {json: {success: true, new_name: 'renamed.png'}}
                : {status: 409, json: {success: false, message: 'That filename is already taken.'}});
            await (await response).finished();
            await nextTask(page);
            if (nextAction === 'other-folder') {
                assert.equal(await page.locator('#files a.jstree-clicked').count(), 1,
                    'Finishing a rename elsewhere must retain the current selection');
                assert.equal(new URL(await page.locator('#finfo a').getAttribute('href'), origin).pathname, '/pictures/second/photo.png');
            } else {
                const expectedName = success ? 'renamed.png' : 'image2.png';
                await page.waitForFunction(name => jQuery.active === 0 && document.querySelector(`#files [data-fname="${name}"]`), expectedName);
                assert.equal(await page.locator(`#files [data-fname="${success ? 'image2.png' : 'renamed.png'}"]`).count(), 0,
                    'A refresh before the rename response must not leave the old filename actionable');
                await page.locator(`#files [data-fname="${expectedName}"] > a`).click();
                await page.getByRole('button', {name: '← Insert', exact: true}).click();
                assert.equal(await page.evaluate(() => window.libraryInsertedImages.at(-1)[0]), '/pictures/' + expectedName);
            }
            assert.deepEqual(await page.evaluate(() => window.libraryMessages), success ? [] : ['That filename is already taken.']);
            assert.deepEqual(library.errors, []);
        } catch (error) {
            throw new Error(`media file rename ${nextAction}/${success}: ${error.message}`, {cause: error});
        } finally { await page.close(); }
    }
    console.log('media library: file rename replies reconcile refreshed lists and retain unrelated folder selections');
}

export async function runMediaLibraryFileMoveRegressions(browser, origin) {
    for (const [selectedFolder, outcome, allFiles] of [
        ['', 'success', false], ['/second', 'success', false], ['/other', 'success', false],
        ['', 'success', true], ['', 'rejected', false], ['', 'network', true], ['/second', 'partial', true],
    ]) {
        const page = await browser.newPage();
        try {
            const library = await openLibrary(page, origin, ['second', 'other'].map(name => ({
                data: name, attr: {'data-path': '/' + name, 'data-csrf-token': name + '-token'},
                ...(allFiles && name === 'second' ? {children: [
                    {data: 'nested', attr: {'data-path': '/second/nested', 'data-csrf-token': 'nested-token'}},
                ]} : {}),
            })));
            const moves = holdRequests(page, '**/library-api?action=move_files&*');
            await moves.installed;
            await page.locator('#files [data-fname="image2.png"] > a').click();
            if (allFiles) {
                await page.locator('#files [data-fname="image10.png"] > a').click({modifiers: ['Shift']});
            }
            // This is the production tree operation invoked by a cross-tree drop.
            await page.evaluate(() => jQuery('#folders').jstree('move_node',
                jQuery('#files').jstree('get_selected'), jQuery('#folders [data-path="/second"]'), 'last'));
            const request = await moves.next();
            const params = new URL(request.request().url()).searchParams;
            assert.equal(params.get('spath'), '');
            assert.equal(params.get('dpath'), '/second');
            assert.deepEqual(params.getAll('fname[]').sort(), allFiles ? ['image10.png', 'image2.png'] : ['image2.png']);
            const body = await formData(request);
            assert.equal(body.get('csrf_token'), 'fixture');
            assert.equal(body.get('destination_csrf_token'), 'second-token');
            assert.equal(await page.locator('#finfo a').count(), 0, 'A moved file must not retain an insertable source URL');
            assert.equal(await page.locator('#folders [data-fname]').count(), 0, 'File drops must not add fake folders');
            assert.equal(await page.locator('[data-media-count]').textContent(), allFiles ? '0/0' : '1/1');
            if (selectedFolder) {
                await page.locator(`#folders [data-path="${selectedFolder}"] > a`).click();
                await page.waitForFunction(() => jQuery.active === 0 && document.querySelector('#files [data-fname="photo.png"]'));
                if (selectedFolder === '/other') await page.locator('#files [data-fname="photo.png"] > a').click();
            }
            const moved = outcome === 'success' ? (allFiles ? ['image2.png', 'image10.png'] : ['image2.png'])
                : outcome === 'partial' ? ['image2.png'] : [];
            for (const name of moved) {
                library.files.splice(library.files.indexOf(name), 1);
                library.filesByPath.get('/second').push(name);
            }
            const failed = outcome === 'rejected' || outcome === 'partial';
            const done = outcome === 'network' ? page.waitForEvent('requestfailed', failed => failed.url() === request.request().url())
                : page.waitForResponse(response => response.url() === request.request().url()).then(response => response.finished());
            if (outcome === 'network') await request.abort('failed');
            else await request.fulfill(failed ? {status: 409, json: {success: false, message: 'Destination already contains a file.'}}
                : {json: {success: true}});
            await done;
            await nextTask(page);
            await page.waitForFunction(() => jQuery.active === 0);
            assert.equal(await page.locator('#folders a.jstree-clicked').evaluate(anchor => anchor.parentElement.dataset.path), selectedFolder,
                'A completed file move must retain the current folder selection');
            assert.equal(await page.evaluate(() => getCurDir()), selectedFolder);
            assert.deepEqual(await page.locator('#files li[data-fname]').evaluateAll(nodes => nodes.map(node => node.dataset.fname)),
                selectedFolder === '/second' ? ['image2.png', 'photo.png'] : selectedFolder ? ['photo.png']
                    : outcome !== 'success' ? ['image2.png', 'image10.png'] : allFiles ? [] : ['image10.png']);
            assert.equal(await page.locator('#folders [data-path="/second/nested"]').count(), allFiles ? 1 : 0);
            assert.deepEqual(await page.evaluate(() => window.libraryMessages), failed ? ['Destination already contains a file.'] : []);
            if (selectedFolder === '/other') {
                assert.equal(await page.locator('#files a.jstree-clicked').count(), 1);
                assert.equal(new URL(await page.locator('#finfo a').getAttribute('href'), origin).pathname, '/pictures/other/photo.png');
            }
            assert.deepEqual(library.errors, []);
        } catch (error) {
            throw new Error(`media file move ${selectedFolder || 'root'}/${outcome}/${allFiles}: ${error.message}`, {cause: error});
        } finally { await page.close(); }
    }
    console.log('media library: moving files clears source actions, keeps folder navigation and refreshes the current list');
}

export async function runMediaLibraryFileDeleteRegressions(browser, origin) {
    for (const [nextAction, outcome] of [
        ['refresh', 'success'], ['stay', 'success'], ['round-trip', 'success'], ['other-folder', 'success'],
        ['other-folder', 'rejected'], ['refresh', 'network'], ['stay', 'rejected'], ['all', 'success'],
    ]) {
        const page = await browser.newPage();
        try {
            const library = await openLibrary(page, origin, [
                {data: 'second', attr: {'data-path': '/second', 'data-csrf-token': 'second-token'}},
            ]);
            const deletes = holdRequests(page, '**/library-api?action=delete_files&*');
            await deletes.installed;
            await page.locator('#files [data-fname="image2.png"] > a').click();
            if (nextAction === 'all') await page.locator('#files [data-fname="image10.png"] > a').click({modifiers: ['Shift']});
            await page.getByRole('button', {name: 'Delete files', exact: true}).click();
            const dialog = page.locator('[data-admin-confirm-dialog]');
            assert.match(await dialog.locator('[data-admin-confirm-message]').textContent(), /image2\.png/u);
            if (nextAction === 'stay' && outcome === 'success') {
                await dialog.getByRole('button', {name: 'Cancel', exact: true}).click();
                assert.equal(deletes.count, 0, 'Cancelling the dialog must not send a delete request');
                assert.equal(await page.locator('#files [data-fname="image2.png"]').count(), 1);
                await page.getByRole('button', {name: 'Delete files', exact: true}).click();
            }
            await dialog.locator('[data-admin-confirm-submit]').click();
            const request = await deletes.next();
            const params = new URL(request.request().url()).searchParams;
            assert.equal(params.get('path'), '');
            assert.deepEqual(params.getAll('fname[]').sort(), nextAction === 'all' ? ['image10.png', 'image2.png'] : ['image2.png']);
            assert.equal((await formData(request)).get('csrf_token'), 'fixture');
            if (nextAction === 'stay' || nextAction === 'all') {
                assert.equal(await page.locator('[data-media-count]').textContent(), nextAction === 'all' ? '0/0' : '1/1',
                    'Removing a file updates its count while the request is pending');
            } else if (nextAction === 'refresh') {
                await page.getByRole('button', {name: 'Refresh', exact: true}).click();
                await page.waitForFunction(() => jQuery.active === 0 && document.querySelector('#files [data-fname="image2.png"]'));
            } else {
                await page.locator('#folders [data-path="/second"] > a').click();
                await page.waitForFunction(() => document.querySelector('#files [data-fname="photo.png"]'));
                await page.locator('#files [data-fname="photo.png"] > a').click();
                if (nextAction === 'round-trip') {
                    await page.locator('#folders [data-path=""] > a').click();
                    await page.waitForFunction(() => jQuery.active === 0 && document.querySelector('#files [data-fname="image2.png"]'));
                }
            }
            if (outcome === 'success') {
                if (nextAction === 'all') library.files.length = 0;
                else library.files.splice(library.files.indexOf('image2.png'), 1);
            }
            const done = outcome === 'network' ? page.waitForEvent('requestfailed', failed => failed.url() === request.request().url())
                : page.waitForResponse(response => response.url() === request.request().url()).then(response => response.finished());
            if (outcome === 'network') await request.abort('failed');
            else await request.fulfill(outcome === 'success' ? {json: {success: true}}
                : {status: 422, json: {success: false, message: 'Unable to delete the file.'}});
            await done;
            await nextTask(page);
            await page.waitForFunction(() => jQuery.active === 0);
            if (nextAction === 'other-folder') {
                assert.equal(await page.locator('#files a.jstree-clicked').count(), 1,
                    'Completing a deletion elsewhere must retain the current file selection');
                assert.equal(new URL(await page.locator('#finfo a').getAttribute('href'), origin).pathname, '/pictures/second/photo.png');
            } else if (nextAction === 'all') {
                assert.equal(await page.locator('#files li[data-fname]').count(), 0);
                assert.equal(await page.locator('[data-media-empty]').evaluate(element => element.hidden), false);
                assert.equal(await page.locator('#finfo a').count(), 0);
                await upload(page, 'after-delete.png', false);
                const uploadRequest = await library.uploads.next();
                library.files.push('after-delete.png');
                await uploadRequest.fulfill({json: {success: true}});
                await page.waitForFunction(() => document.querySelector('#files [data-fname="after-delete.png"]'));
            } else {
                assert.deepEqual(await page.locator('#files li[data-fname]').evaluateAll(nodes => nodes.map(node => node.dataset.fname)),
                    outcome === 'success' ? ['image10.png'] : ['image2.png', 'image10.png'],
                    'A deletion reply must reconcile lists refreshed while it was pending');
                assert.equal(await page.locator('[data-media-count]').textContent(), outcome === 'success' ? '1/1' : '2/2');
                await page.locator('#files [data-fname="image10.png"] > a').click();
                await page.getByRole('button', {name: '← Insert', exact: true}).click();
                assert.equal(await page.evaluate(() => window.libraryInsertedImages.at(-1)[0]), '/pictures/image10.png');
            }
            assert.deepEqual(await page.evaluate(() => window.libraryMessages), outcome === 'rejected' ? ['Unable to delete the file.'] : []);
            assert.deepEqual(library.errors, []);
        } catch (error) {
            throw new Error(`media file delete ${nextAction}/${outcome}: ${error.message}`, {cause: error});
        } finally { await page.close(); }
    }
    console.log('media library: confirmed deletions reconcile refreshed lists and retain unrelated selections on success or failure');
}

export async function runMediaLibraryFolderCreateRegressions(browser, origin) {
    for (const [selection, outcome] of [
        ['new', 'success'], ['parent', 'success'], ['other', 'success'],
        ['other', 'rejected'], ['new', 'rejected'], ['other', 'network'], ['new', 'null'], ['created', 'rejected'],
    ]) {
        const page = await browser.newPage();
        try {
            const library = await openLibrary(page, origin, [
                {data: 'second', attr: {'data-path': '/second', 'data-csrf-token': 'second-token'}},
            ]);
            const creates = holdRequests(page, '**/library-api?action=create_subfolder&*');
            await creates.installed;
            await page.locator('#context_add').click();
            await page.locator('#folders input').fill('new');
            await page.locator('#folders input').press('Enter');
            const request = await creates.next();
            assert.equal(new URL(request.request().url()).searchParams.get('path'), '');
            assert.equal((await formData(request)).get('csrf_token'), 'fixture');
            if (selection === 'created') {
                await page.locator('#context_add').click();
                await page.locator('#folders input').fill('kept');
                await page.locator('#folders input').press('Enter');
                const otherCreate = await creates.next();
                library.filesByPath.set('/kept', ['photo.png']);
                await otherCreate.fulfill({json: {success: true, path: '/kept', name: 'kept', csrf_token: 'kept-token'}});
                await page.locator('#folders [data-path="/kept"] > a').click();
                await page.waitForFunction(() => document.querySelector('#files [data-fname="photo.png"]'));
                await page.locator('#files [data-fname="photo.png"] > a').click();
            } else if (selection === 'new') {
                await page.locator('#folders li:not([data-path]) > a').click();
            } else if (selection === 'other') {
                await page.locator('#folders [data-path="/second"] > a').click();
                await page.waitForFunction(() => document.querySelector('#files [data-fname="photo.png"]'));
                await page.locator('#files [data-fname="photo.png"] > a').click();
            }
            library.filesByPath.set('/new1', []);
            const done = outcome === 'network' ? page.waitForEvent('requestfailed', failed => failed.url() === request.request().url())
                : page.waitForResponse(response => response.url() === request.request().url()).then(response => response.finished());
            if (outcome === 'network') await request.abort('failed');
            else await request.fulfill(outcome === 'success' ? {json: {success: true, path: '/new1', name: 'new1', csrf_token: 'new-token'}}
                : outcome === 'null' ? {json: null} : {status: 409, json: {success: false, message: 'Unable to create the folder.'}});
            await done;
            await nextTask(page);
            await page.waitForFunction(() => jQuery.active === 0);
            const expected = selection === 'created' ? '/kept' : selection === 'other' ? '/second'
                : selection === 'new' && outcome === 'success' ? '/new1' : '';
            assert.equal(await page.evaluate(() => getCurDir()), expected,
                'Completing folder creation must synchronize the selected folder path');
            assert.equal(await page.locator('#folders a.jstree-clicked').evaluate(anchor => anchor.parentElement.dataset.path), expected);
            assert.equal(await page.locator('[data-media-folder-path]').textContent(), expected || 'Pictures');
            assert.equal(await page.locator('#folders li:not([data-path])').count(), 0);
            assert.equal(await page.locator('#folders [data-path="/new1"]').count(), outcome === 'success' ? 1 : 0);
            if (selection === 'other' || selection === 'created') {
                assert.equal(await page.locator('#files a.jstree-clicked').count(), 1);
                assert.equal(new URL(await page.locator('#finfo a').getAttribute('href'), origin).pathname, '/pictures' + expected + '/photo.png');
            } else if (expected === '/new1') {
                assert.equal(await page.locator('#files li[data-fname]').count(), 0, 'The selected new folder must not show parent files');
                assert.equal(await page.locator('#fold_name').textContent(), 'new1');
            }
            await upload(page, 'created.png', false);
            const uploadRequest = await library.uploads.next();
            const body = await formData(uploadRequest);
            assert.equal(body.get('dir'), expected);
            assert.equal(body.get('csrf_token'), expected === '/new1' ? 'new-token' : expected === '/second' ? 'second-token'
                : expected === '/kept' ? 'kept-token' : 'fixture');
            library.filesByPath.get(expected).push('created.png');
            await uploadRequest.fulfill({json: {success: true}});
            await page.waitForFunction(() => document.querySelector('#files [data-fname="created.png"]'));
            assert.deepEqual(await page.evaluate(() => window.libraryMessages), outcome === 'rejected' ? ['Unable to create the folder.'] : []);
            assert.deepEqual(library.errors, []);
        } catch (error) {
            throw new Error(`media folder create ${selection}/${outcome}: ${error.message}`, {cause: error});
        } finally { await page.close(); }
    }
    console.log('media library: folder creation synchronizes pending selections and preserves unrelated selections after errors');
}

export async function runMediaLibraryFolderRollbackRegressions(browser, origin, actions = ['rename', 'move', 'delete']) {
    for (const action of actions) {
        for (const [selection, outcome] of [
            ['other', 'rejected'], ['created', 'rejected'], ['current', 'network'], ['current', 'null'], ['other', 'network'],
        ]) {
            const page = await browser.newPage();
            try {
                const library = await openLibrary(page, origin, [
                    {data: 'source', state: 'open', attr: {'data-path': '/source', 'data-csrf-token': 'source-token'}, children: [
                        {data: 'first', state: 'open', attr: {'data-path': '/source/first', 'data-csrf-token': 'first-token'}, children: [
                            {data: 'child', attr: {'data-path': '/source/first/child', 'data-csrf-token': 'child-token'}},
                        ]},
                    ]},
                    {data: 'target', attr: {'data-path': '/target', 'data-csrf-token': 'target-token'}},
                ]);
                library.filesByPath.set('/source/first', ['photo.png']);
                library.filesByPath.set('/source/first/child', ['photo.png']);
                await page.locator('#folders [data-path="/source/first"] > a').click();
                await page.waitForFunction(() => jQuery.active === 0 && document.querySelector('#files [data-fname="photo.png"]'));
                const mutations = holdRequests(page, `**/library-api?action=${action}_folder&*`);
                await mutations.installed;
                const mutateFolder = async () => {
                    if (action === 'rename') {
                        await page.evaluate(() => jQuery('#folders').jstree('rename', jQuery('#folders [data-path="/source/first"]')));
                        await page.locator('#folders input').fill('renamed');
                        await page.locator('#folders input').press('Enter');
                    } else if (action === 'move') {
                        await page.evaluate(() => jQuery('#folders').jstree('move_node',
                            jQuery('#folders [data-path="/source/first"]'), jQuery('#folders [data-path="/target"]'), 'last'));
                    } else {
                        await page.locator('#context_delete').click();
                        await page.locator('[data-admin-confirm-submit]').click();
                    }
                };
                await mutateFolder();
                const request = await mutations.next();
                const params = new URL(request.request().url()).searchParams;
                assert.equal(params.get(action === 'move' ? 'spath' : 'path'), '/source/first');
                assert.equal((await formData(request)).get('csrf_token'), 'first-token');
                let expectedPath = action === 'delete' ? '/source' : '/source/first';
                let expectedToken = action === 'delete' ? 'source-token' : 'first-token';
                if (selection !== 'current') {
                    await page.locator('#folders [data-path="/target"] > a').click();
                    expectedPath = '/target';
                    expectedToken = 'target-token';
                }
                if (selection === 'created') {
                    const creates = holdRequests(page, '**/library-api?action=create_subfolder&*');
                    await creates.installed;
                    await page.locator('#context_add').click();
                    await page.locator('#folders input').fill('kept');
                    await page.locator('#folders input').press('Enter');
                    const create = await creates.next();
                    library.filesByPath.set('/target/kept', ['photo.png']);
                    await create.fulfill({json: {success: true, path: '/target/kept', name: 'kept', csrf_token: 'kept-token'}});
                    await page.locator('#folders [data-path="/target/kept"] > a').click();
                    expectedPath = '/target/kept';
                    expectedToken = 'kept-token';
                }
                await page.waitForFunction(() => jQuery.active === 0 && document.querySelector('#files [data-fname="photo.png"]'));
                await page.locator('#files [data-fname="photo.png"] > a').click();
                const done = outcome === 'network' ? page.waitForEvent('requestfailed', failed => failed.url() === request.request().url())
                    : page.waitForResponse(response => response.url() === request.request().url()).then(response => response.finished());
                if (outcome === 'network') await request.abort('failed');
                else await request.fulfill(outcome === 'null' ? {json: null}
                    : {status: 409, json: {success: false, message: 'The folder operation failed.'}});
                await done;
                await nextTask(page);
                await page.waitForFunction(() => jQuery.active === 0);
                assert.deepEqual(await page.evaluate(() => jQuery('#folders').jstree('get_selected').map(function () {
                    return this.dataset.path;
                }).get()), [expectedPath], 'A failed folder operation must retain the current tree selection');
                assert.equal(await page.evaluate(() => getCurDir()), expectedPath);
                assert.equal(await page.locator('[data-media-folder-path]').textContent(), expectedPath);
                assert.equal(await page.locator('#folders a.jstree-clicked').evaluate(anchor => anchor.parentElement.dataset.path), expectedPath);
                assert.equal(await page.locator('#folders [data-path="/source/first"]').count(), 1);
                assert.equal(await page.locator('#folders [data-path="/source/first"]').evaluate(node => node.parentElement.parentElement.dataset.path), '/source');
                assert.equal(await page.evaluate(() => jQuery('#folders').jstree('get_text', jQuery('#folders [data-path="/source/first"]'))), 'first');
                assert.equal(await page.locator('#folders [data-path="/source/first/child"]').count(), 1);
                assert.equal(await page.locator('#folders [data-path="/target/kept"]').count(), selection === 'created' ? 1 : 0);
                assert.equal(await page.locator('#context_buttons').count(), 1);
                assert.equal(await page.locator('#context_buttons').evaluate(node => node.closest('li').dataset.path), expectedPath);
                assert.equal(await page.locator('#files a.jstree-clicked').count(), 1);
                await page.getByRole('button', {name: '← Insert', exact: true}).click();
                assert.equal(await page.evaluate(() => window.libraryInsertedImages.at(-1)[0]), '/pictures' + expectedPath + '/photo.png');
                await upload(page, 'after-error.png', false);
                const uploadRequest = await library.uploads.next();
                const body = await formData(uploadRequest);
                assert.equal(body.get('dir'), expectedPath);
                assert.equal(body.get('csrf_token'), expectedToken);
                library.filesByPath.get(expectedPath).push('after-error.png');
                await uploadRequest.fulfill({json: {success: true}});
                await page.waitForFunction(() => document.querySelector('#files [data-fname="after-error.png"]'));
                assert.equal(mutations.count, 0, 'Restoring the UI must not repeat the failed server mutation');
                assert.deepEqual(await page.evaluate(() => window.libraryMessages), outcome === 'rejected' ? ['The folder operation failed.'] : []);
                if (selection === 'current' && outcome === 'network') {
                    // The selected anchor also contains the add/delete buttons.
                    // Click its leading icon rather than a context button.
                    await page.locator('#folders [data-path="/source/first"] > a').click({position: {x: 3, y: 3}});
                    await page.waitForFunction(() => jQuery.active === 0);
                    assert.equal(await page.locator('#folders input').count(), 0);
                    await mutateFolder();
                    const retry = await mutations.next();
                    const retryPath = action === 'rename' ? '/source/renamed' : '/target/first';
                    library.filesByPath.set(retryPath, ['photo.png']);
                    library.filesByPath.set(retryPath + '/child', ['photo.png']);
                    const retried = page.waitForResponse(response => response.url() === retry.request().url()).then(response => response.finished());
                    await retry.fulfill({json: action === 'delete' ? {success: true}
                        : {success: true, new_path: retryPath, csrf_token: 'first-token'}});
                    await retried;
                    await nextTask(page);
                    await page.waitForFunction(() => jQuery.active === 0);
                    assert.equal(await page.locator('#folders [data-path="/source/first"]').count(), 0);
                    assert.equal(await page.evaluate(() => getCurDir()), action === 'delete' ? '/source' : retryPath);
                    if (action !== 'delete') assert.deepEqual(await page.locator(`#folders [data-path="${retryPath}"] li`)
                        .evaluateAll(nodes => nodes.map(node => node.dataset.path)), [retryPath + '/child']);
                }
                assert.deepEqual(library.errors, []);
            } catch (error) {
                throw new Error(`media folder rollback ${action}/${selection}/${outcome}: ${error.message}`, {cause: error});
            } finally { await page.close(); }
        }
    }
    console.log('media library: failed folder renames, moves and deletes restore only the affected folder and retain later work');
}
