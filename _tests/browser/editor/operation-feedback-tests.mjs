import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {formData, holdRequests} from './save-tests.mjs';

export async function runOperationFeedbackRegressions(browser, origin) {
    for (const admin of [false, true]) {
        const page = await browser.newPage({reducedMotion: 'reduce'});
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        try {
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            const requests = holdRequests(page, admin ? '**/admin-ai' : '**/_inplace/post/9');
            await requests.installed;
            await page.goto(origin + (admin ? '/admin.html?id=9&ai=1&codemirror=1&toolbar=1' : '/recovery.html'));
            if (admin) {
                await page.addStyleTag({url: '/admin-editor.css'});
                await page.waitForFunction(() => window.adminEditorReady);
                await page.evaluate(() => adminEditor.setValue('<p>A sentense.</p>', true));
            } else {
                await page.getByRole('button', {name: 'Edit', exact: true}).click();
                await page.locator('.is-editing [data-post-inplace-body]').fill('A sentense.');
            }
            for (const phase of ['applied', 'clean']) {
                if (admin) await page.locator('[data-ai-action="proofread"]').click();
                else {
                    await page.locator('.is-editing [data-post-inplace-body]').click({button: 'right'});
                    await page.locator('[data-context-ai-action="proofread"]').click();
                }
                const request = await requests.next();
                const data = await formData(request);
                const icon = page.locator(admin ? '.ai-tools-status-icon' : '.post-ai-feedback');
                const attribute = admin ? 'data-phase' : 'data-ai-state';
                assert.equal(await icon.getAttribute(attribute), 'working');
                assert.equal(await icon.locator(admin ? '.ai-tools-spinner' : '.post-save-spinner').isVisible(), true,
                    `${admin ? 'Admin' : 'Inline'} AI displays its processing spinner`);
                if (!admin) assert.equal(await page.locator('.post-inplace-status').isVisible(), false);
                const text = data.get('text');
                await request.fulfill({json: {success: true, action: 'ai', ai_action: 'proofread',
                    result: phase === 'applied' ? text.replace('sentense', 'sentence') : text}});
                await page.waitForFunction(({admin, phase}) => document.querySelector(admin ? '.ai-tools-status-icon' : '.post-ai-feedback')
                    ?.getAttribute(admin ? 'data-phase' : 'data-ai-state') === phase, {admin, phase});
                assert.equal(await icon.locator('svg').isVisible(), true);
                if (admin) {
                    const size = await page.locator('.ai-tools-status-label').boundingBox();
                    assert.ok(size.width <= 1 && size.height <= 1, 'AI descriptions remain available only to assistive technology');
                } else assert.equal(await page.locator('.post-inplace-status').isVisible(), false);
                await icon.waitFor({state: 'detached'});
            }
            assert.deepEqual(errors, []);
        } finally { await page.close(); }
    }

    const mobile = await browser.newPage({viewport: {width: 390, height: 800}, hasTouch: true});
    try {
        await mobile.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        await mobile.goto(origin + '/recovery.html?draft-tools=1');
        await mobile.getByRole('button', {name: 'New post', exact: true}).click();
        const draft = await mobile.locator('.is-editing .post-edit-draft').boundingBox();
        const cancel = await mobile.locator('.is-editing .post-edit-cancel').boundingBox();
        const body = await mobile.locator('.is-editing [data-post-inplace-body]').boundingBox();
        assert.ok(draft.y >= cancel.y + cancel.height + 5, 'The mobile draft button sits below the other tools');
        assert.ok(draft.x >= 0 && draft.x + draft.width <= 390, 'Draft saving stays within the viewport');
        assert.ok(body.y >= draft.y + draft.height, 'The extra row cannot cover authored text');
    } finally { await mobile.close(); }
    console.log(`${browser.browserType().name()}: AI processing/success/clean use icons in both editors; mobile draft tools keep text unobscured`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit].filter(engine => !process.env.EDITOR_TEST_BROWSER
            || engine.name() === process.env.EDITOR_TEST_BROWSER)) {
            const browser = await engine.launch();
            try { await runOperationFeedbackRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
