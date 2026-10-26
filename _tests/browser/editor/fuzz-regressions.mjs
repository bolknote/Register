import assert from 'node:assert/strict';
import {mkdir, readFile} from 'node:fs/promises';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {applyFuzzAction, openFuzzEditor, historyRoundTrip, saveRoundTrip, timed} from './fuzz-editing.mjs';
import {applyMediaFuzzAction, mediaState} from './fuzz-media-state.mjs';
import {fulfillPreview} from './html-block-tests.mjs';
import {formData} from './save-tests.mjs';

const corpus = [
    'delete-list-item-removes-following-paragraph',
    'code-freezes-after-caption',
    'list-detaches-image-caption',
    'paragraph-duplicates-tables',
    'drag-into-nested-caption-loses-image',
    'html-redo-select-all-escapes-editor',
    'caption-redo-moves-typing-to-end',
    'caption-cancel-backspace-navigates-away',
];

export async function runFuzzRegressions(browser, origin) {
    await mkdir(new URL('../../_output/editor-fuzz/', import.meta.url), {recursive: true});
    const tests = [];
    for (const name of corpus) {
        const test = JSON.parse(await readFile(new URL(`./fuzz-corpus/${name}.json`, import.meta.url), 'utf8'));
        tests.push({name, test});
    }
    const deletion = structuredClone(tests[0].test);
    deletion.operations[0].kind = 'backspace';
    tests.push({name: 'backspace-list-boundary', test: deletion});
    const picture = (name) => '<div class="post-picture post-media-picture"><img src="/responsive-image.svg"'
        + ' width="160" height="90" alt="' + name + '"><div class="post-caption">Caption ' + name + '</div></div>';
    const nested = '<blockquote><p>Before</p>' + picture('A') + '<p>After</p></blockquote>'
        + '<ul><li>List item' + picture('B') + '</li></ul>';
    for (const index of [1, 2]) {
        const test = structuredClone(tests[index].test);
        test.initial = nested;
        if (index === 2) test.operations[0].format = 'ordered-list';
        tests.push({name: index === 1 ? 'code-with-nested-attachments' : 'ordered-list-with-nested-attachments', test});
    }
    const table = structuredClone(tests[3].test);
    table.initial = table.initial.replace('<p>Cell A</p>', '<h2>Cell A</h2>').replace('<p>Cell D</p>', '<h3>Cell D</h3>');
    table.operations[0].start.selector = 'table:first-of-type td:first-child h2';
    table.operations[0].start.offset = 0;
    table.operations[0].end.selector = 'table:last-of-type td:last-child h3';
    tests.push({name: 'paragraph-converts-headings-without-duplicating-tables', test: table});
    const drag = structuredClone(tests[4].test);
    drag.html = '<blockquote>' + picture('A') + '</blockquote><ul><li>' + picture('B') + '</li></ul><p>After</p>';
    tests.push({name: 'drag-into-list-caption', test: drag});
    for (const {name, test} of tests) {
        const session = await openFuzzEditor(browser, origin, test.initial || test.html);
        try {
            if (test.runner === 'fuzz-media-state.mjs') {
                session.expectedImages = (await mediaState(session.page)).images.map(([name]) => name);
                await session.page.route('**/_inplace/post/9', async route => {
                    const data = await formData(route);
                    if (data.get('inplace_action') === 'html_preview') await fulfillPreview(route, data);
                    else await route.fallback();
                });
                for (const operation of test.operations) await timed(applyMediaFuzzAction(session, operation), 12000);
            } else {
                for (const operation of test.operations) await timed(applyFuzzAction(session, {...operation, ui: true}));
                if (name === 'code-freezes-after-caption' || name === 'code-with-nested-attachments') {
                    const text = await session.body.locator('pre').allTextContents();
                    assert.ok(text.includes('Before') && text.includes('After'));
                    assert.equal(await session.body.locator('pre img').count(), 0);
                }
                if (name === 'list-detaches-image-caption') assert.equal(await session.body.locator('li img').count(), 1, await session.body.innerHTML());
                if (name === 'ordered-list-with-nested-attachments') assert.equal(await session.body.locator('ol li img').count(), 2);
                if (name === 'paragraph-converts-headings-without-duplicating-tables') {
                    assert.equal(await session.body.locator('h2, h3').count(), 0);
                    assert.equal(await session.body.locator('table').count(), 2);
                }
                await timed(historyRoundTrip(session));
            }
            await timed(saveRoundTrip(session), 12000);
            assert.deepEqual(session.errors, [], test.name + ': browser errors');
        } finally { await timed(session.page.close(), 3000).catch(() => {}); }
        console.log(`${browser.browserType().name()} fuzz regression: ${name}`);
    }
}

async function main() {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit]) {
            if (process.env.EDITOR_TEST_BROWSER && process.env.EDITOR_TEST_BROWSER !== engine.name()) continue;
            const browser = await engine.launch();
            try { await runFuzzRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally {
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) await main();
