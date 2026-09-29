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
    <span data-media-count></span><div data-media-selection-bar hidden><span data-media-selected-count></span></div>
    <div id="brd"><div id="files"></div><div id="loadstatus"></div>
        <div data-media-empty hidden></div><div data-media-no-matches hidden></div></div>
    <div id="finfo"></div>
</section>
<script src="/library/lang.js"></script><script src="/admin-fetch.js"></script>
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
    for (const [name, reply, hasError] of [
        ['null JSON', {json: null}, true],
        ['invalid JSON', {body: 'not JSON'}, true],
        ['HTTP error', {status: 503, json: {message: 'Unavailable'}}, true],
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
