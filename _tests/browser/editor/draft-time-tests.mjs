import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {formData, holdRequests} from './save-tests.mjs';

const initialTime = new Date('2026-11-07T10:00:00Z');

function response(data, creating, draft, revision = 2) {
    const timestamp = Math.floor(initialTime.getTime() / 1000);
    return {success: true, action: creating ? 'create' : 'edit', draft, date_mode: draft ? data.get('date_mode') : 'fixed',
        revision, title: data.get('title'), body: data.get('body'), slug: 'saved-draft',
        body_html: `<div class="post body" data-post-inplace-body>${data.get('body')}</div>`,
        published_at: timestamp, datetime: initialTime.toISOString(), time: '7 November',
        tags: [], scheduled: false, message: 'Saved',
        ...(creating ? {id: 10, url: '/saved-draft', action_url: '/_inplace/post/10', token: 'fixture'} : {})};
}

export async function runDraftTimeRegressions(browser, origin) {
    const page = await browser.newPage({viewport: {width: 1280, height: 800}, reducedMotion: 'reduce', timezoneId: 'Europe/Moscow'});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.setDefaultTimeout(10000);
    await page.clock.setFixedTime(initialTime);
    try {
        await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        const requests = holdRequests(page, '**/_inplace/post/*');
        await requests.installed;
        await page.goto(origin + '/recovery.html?draft-tools=1');
        await page.getByRole('button', {name: 'New post', exact: true}).click();
        const card = page.locator('.post-card.is-editing');
        const title = card.locator('[data-post-inplace-title]');
        const body = card.locator('[data-post-inplace-body]');
        const date = card.locator('.post-inplace-datetime');
        await title.fill('A server draft');
        await body.fill('First text.');
        const before = await date.inputValue();
        await page.clock.setFixedTime(new Date(initialTime.getTime() + 1800000));
        await page.waitForFunction(before => document.querySelector('.post-card.is-editing .post-inplace-datetime').value !== before, before);
        const automatic = await date.inputValue();
        assert.match(automatic, /T13:30(?::00)?$/u, 'An untouched new post follows the current time');
        await date.evaluate(input => input.focus());
        await page.clock.setFixedTime(new Date(initialTime.getTime() + 1860000));
        await page.keyboard.press('Tab');
        await body.click();
        await page.waitForFunction(() => /T13:31(?::00)?$/u.test(document.querySelector('.post-card.is-editing .post-inplace-datetime').value)).catch(async error => {
            throw new Error(JSON.stringify(await page.evaluate(() => {
                const state = editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
                return {now: new Date().toISOString(), autoDate: state.autoDate, dateDirty: state.dateDirty,
                    value: state.dateInput.value, active: document.activeElement.outerHTML.slice(0, 180)};
            })), {cause: error});
        });
        await date.evaluate(input => {
            input.value = '2026-11-03T09:15:00';
            input.dispatchEvent(new Event('input', {bubbles: true}));
        });
        await page.clock.setFixedTime(new Date(initialTime.getTime() + 3600000));
        await page.waitForTimeout(1100);
        assert.match(await date.inputValue(), /^2026-11-03T09:15(?::00)?$/u, 'A manual choice remains fixed');
        await card.getByRole('button', {name: 'Now', exact: true}).click();
        await page.waitForFunction(() => /T14:00(?::00)?$/u.test(document.querySelector('.post-card.is-editing .post-inplace-datetime').value));
        const draft = card.getByRole('button', {name: 'Save draft', exact: true});
        const draftBox = await draft.boundingBox();
        const cancelBox = await card.locator('.post-edit-cancel').boundingBox();
        assert.ok(draftBox.y >= cancelBox.y + cancelBox.height + 5, 'Draft saving sits below the other tools');
        await draft.click();
        const first = await requests.next();
        const firstData = await formData(first);
        assert.equal(firstData.get('publication'), 'draft');
        assert.equal(firstData.get('date_mode'), 'now');
        await first.fulfill({json: response(firstData, true, true)});
        await page.waitForFunction(() => document.querySelector('.post-card.is-draft-preview.is-editing[data-post-id="10"]'));
        await page.waitForFunction(() => !document.querySelector('[data-post-save-state]'));
        assert.equal(await card.getByRole('button', {name: 'Publish', exact: true}).isVisible(), true);
        assert.equal(await body.textContent(), 'First text.');
        await body.click();
        await page.keyboard.press('End');
        await page.keyboard.type(' More.');
        const secondText = await body.textContent();
        await draft.click();
        const second = await requests.next();
        assert.ok(second.request().url().endsWith('/_inplace/post/10'), 'The same stored draft is updated');
        const secondData = await formData(second);
        assert.equal(secondData.get('revision'), '2');
        await second.fulfill({json: response(secondData, false, true, 3)});
        await page.waitForFunction(() => !document.querySelector('[data-post-save-state]'));
        await body.click();
        const modifier = await page.evaluate(() => /Mac|iPhone|iPad|iPod/u.test(navigator.platform) ? 'Meta' : 'Control');
        await page.keyboard.press(modifier + '+z');
        assert.notEqual(await body.textContent(), secondText, 'Saving a draft retains its undo timeline');
        await page.keyboard.press(modifier + '+Shift+z');
        assert.equal(await body.textContent(), secondText);
        await card.getByRole('button', {name: 'Publish', exact: true}).click();
        const publish = await requests.next();
        const publishData = await formData(publish);
        assert.equal(publishData.get('publication'), 'publish');
        assert.equal(publishData.get('date_mode'), 'now');
        await publish.fulfill({json: response(publishData, false, false, 4)});
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        assert.equal(await page.locator('.post-card.is-draft-preview').count(), 0);
        assert.equal(await page.locator('.post-draft-notice').count(), 0);
        await page.waitForFunction(() => !document.querySelector('[data-post-save-state]'));
        await page.locator('.post-card[data-post-id="10"] .post-edit-start').click();
        assert.equal(await page.locator('.post-card.is-editing .post-edit-draft').isVisible(), false);
        const publishedDate = await date.inputValue();
        await page.clock.setFixedTime(new Date(initialTime.getTime() + 7200000));
        await page.waitForTimeout(1100);
        assert.equal(await date.inputValue(), publishedDate, 'Editing a published post preserves its publication date');
        assert.deepEqual(errors, []);
    } finally { await page.close(); }

    const recovery = await browser.newPage({reducedMotion: 'reduce', timezoneId: 'Europe/Moscow'});
    try {
        await recovery.clock.setFixedTime(initialTime);
        await recovery.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        await recovery.goto(origin + '/recovery.html?draft-tools=1');
        await recovery.getByRole('button', {name: 'New post', exact: true}).click();
        await recovery.locator('.post-card.is-editing [data-post-inplace-title]').fill('Recovered automatic time');
        await recovery.locator('.post-card.is-editing [data-post-inplace-body]').fill('Keep this text.');
        await recovery.waitForFunction(() => Object.keys(localStorage).some(key => key.startsWith('register:post-recovery:')));
        await recovery.clock.setFixedTime(new Date(initialTime.getTime() + 7200000));
        await recovery.reload();
        await recovery.getByRole('button', {name: 'Restore text', exact: true}).click();
        assert.match(await recovery.locator('.post-card.is-editing .post-inplace-datetime').inputValue(), /T15:00(?::00)?$/u,
            'Restoring a local copy preserves automatic time instead of pinning its old date');
        assert.equal(await recovery.locator('.post-card.is-editing [data-post-inplace-body]').textContent(), 'Keep this text.');
    } finally { await recovery.close(); }
    console.log(`${browser.browserType().name()}: ticking/manual/restored time, private draft saves, retained undo and explicit publication`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit].filter(engine => !process.env.EDITOR_TEST_BROWSER
            || engine.name() === process.env.EDITOR_TEST_BROWSER)) {
            const browser = await engine.launch();
            try { await runDraftTimeRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
