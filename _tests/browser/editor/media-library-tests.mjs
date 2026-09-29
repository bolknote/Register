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
    <div id="folders"></div><button type="button" data-media-refresh>Refresh</button>
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

async function openLibrary(page, origin) {
    const errors = [];
    page.setDefaultTimeout(10000);
    page.on('pageerror', error => errors.push(String(error)));
    await page.addInitScript(() => {
        window.libraryMessages = [];
        window.PopupMessages = {show: message => window.libraryMessages.push(message)};
        window.DisplayError = message => window.libraryMessages.push(message);
    });
    const scripts = new Map(await Promise.all([
        ['lang.js', 'lang/en/ui.js'], ['ajax.js', 'js/ajax.js'], ['pictman.js', 'js/pictman.js'],
        ['jquery.js', 'lib/jquery.js'], ['jquery-tools.js', 'lib/jquery-tools.js'], ['jquery.jstree.js', 'lib/jquery.jstree.js'],
    ].map(async ([name, path]) => [name, await readFile(new URL('../../../_admin/' + path, import.meta.url), 'utf8')])));
    await page.route('**/library/*', route => route.fulfill({contentType: 'text/javascript',
        body: scripts.get(new URL(route.request().url()).pathname.split('/').pop()) || ''}));
    await page.route('**/media-library.html', route => route.fulfill({contentType: 'text/html', body: markup}));
    const files = ['image10.png', 'image2.png'];
    await page.route('**/library-api?*', route => {
        const action = new URL(route.request().url()).searchParams.get('action');
        if (action === 'load_folders') return route.fulfill({json: [
            {data: 'Pictures', attr: {id: 'node_1', 'data-path': '', 'data-csrf-token': 'fixture'}},
        ]});
        if (action === 'load_files') {
            return route.fulfill({json: files.map(name => ({data: name, attr: {'data-fname': name, 'data-dim': '80*60'}}))});
        }
        return route.fallback();
    });
    const uploads = holdRequests(page, '**/library-api?action=upload');
    await uploads.installed;
    await page.goto(origin + '/media-library.html');
    await page.waitForFunction(() => document.querySelectorAll('#files li[data-fname]').length === 2
        && document.querySelector('#folders .jstree-clicked'));
    await page.waitForFunction(() => !document.getElementById('loading_pict').classList.contains('is-active'));
    return {errors, files, uploads};
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
