import assert from 'node:assert/strict';
import {confirmPublication} from './save-tests.mjs';

export async function runDraftPostWorkflow(page, origin) {
    const label = page.context().browser().browserType().name();
    const title = 'Private inline draft ' + label;
    const text = 'A draft saved on the server before publication.';
    await page.goto(origin + '/');
    await page.locator('.post-create-start').click();
    const creating = page.locator('[data-post-creating]');
    await creating.locator('[data-post-inplace-title]').fill(title);
    await creating.locator('[data-post-inplace-body]').fill(text);
    const upload = page.waitForResponse(response => response.request().method() === 'POST'
        && response.request().postDataBuffer()?.includes(Buffer.from('name="media";')));
    await creating.locator('[data-post-inplace-body]').press('Shift+F10');
    const chooser = page.waitForEvent('filechooser');
    await page.locator('[data-context-action="media"]').click();
    await (await chooser).setFiles({name: 'draft.png', mimeType: 'image/png',
        buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==', 'base64')});
    assert.equal((await upload).status(), 200);
    await creating.locator('.post-media-picture:not(.is-processing) img').waitFor();
    const createdResponse = page.waitForResponse(async response => response.request().method() === 'POST'
        && new URL(response.url()).pathname === '/_inplace/post/new'
        && (await response.json().catch(() => null))?.action === 'create');
    await creating.getByRole('button', {name: 'Save draft', exact: true}).click();
    const created = await (await createdResponse).json();
    await page.waitForFunction(() => document.querySelector('.post-card.is-draft-preview.is-editing[data-post-id]:not([data-post-id="0"])'));
    const card = page.locator('.post-card.is-draft-preview.is-editing');
    const id = await card.getAttribute('data-post-id');
    await page.waitForFunction(() => !document.querySelector('[data-post-save-state]'));
    assert.equal(await card.locator('[data-post-inplace-body] img').count(), 1);
    assert.ok((await card.locator('[data-post-inplace-body]').textContent()).includes(text));
    assert.ok(created?.draft);
    assert.equal(created.date_mode, 'now');
    assert.equal(await card.locator('.post-inplace-edit-form').getAttribute('id'), 'post-inplace-edit-' + id);
    const dialog = card.locator('.post-delete-confirmation');
    assert.equal(await dialog.getAttribute('aria-labelledby'), 'post-delete-title-' + id);
    assert.equal(await dialog.locator('h2').getAttribute('id'), 'post-delete-title-' + id);
    if (process.env.EDITOR_DRAFT_SCREENSHOTS && label === 'chromium') {
        for (const [name, width] of [['desktop', 1280], ['mobile', 390]]) {
            await page.setViewportSize({width, height: 800});
            await card.locator('[data-post-inplace-title]').scrollIntoViewIfNeeded();
            await page.screenshot({path: process.env.EDITOR_DRAFT_SCREENSHOTS + '-' + name + '.png'});
        }
        await page.setViewportSize({width: 1280, height: 720});
    }
    const anonymous = await page.context().browser().newContext({javaScriptEnabled: false});
    try {
        const other = await anonymous.newPage();
        assert.equal((await other.goto(new URL(created.url, origin).href)).status(), 404);
        assert.equal((await other.locator('body').textContent()).includes(text), false);
    } finally { await anonymous.close(); }

    await page.goto(new URL(created.url, origin).href);
    const saved = page.locator(`.post-card[data-post-id="${id}"]`);
    assert.equal(await saved.getAttribute('data-post-date-mode'), 'now');
    assert.equal(await saved.locator('[data-post-inplace-body] img').count(), 1);
    await saved.locator('.post-edit-start').click();
    await saved.locator('[data-post-inplace-title]').fill(title + ' resumed');
    await saved.getByRole('button', {name: 'Save draft', exact: true}).click();
    await page.waitForFunction(() => !document.querySelector('[data-post-save-state]'));
    assert.equal(await saved.getAttribute('data-post-id'), id);
    assert.equal(await saved.evaluate(card => card.classList.contains('is-editing')), true);
    // A deliberately wrong browser clock must not schedule automatic publication.
    await page.clock.setFixedTime(new Date(Date.now() + 3600000));
    const publishedResponse = page.waitForResponse(async response => response.request().method() === 'POST'
        && new URL(response.url()).pathname === '/_inplace/post/' + id
        && (await response.json().catch(() => null))?.action === 'edit');
    const before = Math.floor(Date.now() / 1000);
    const navigation = page.waitForNavigation({waitUntil: 'domcontentloaded'});
    await saved.getByRole('button', {name: 'Publish', exact: true}).click();
    await confirmPublication(page);
    const published = await (await publishedResponse).json();
    assert.equal(published.draft, false);
    assert.equal(published.scheduled, false);
    assert.ok(published.published_at >= before && published.published_at <= Math.floor(Date.now() / 1000));
    await navigation;
    await page.locator('#comment-form').waitFor();
    assert.equal(await saved.locator('[data-post-inplace-body] img').count(), 1);
    assert.equal(await saved.evaluate(card => card.classList.contains('is-draft-preview')), false);
    assert.equal(await saved.locator('[data-post-inplace-title]').textContent(), title + ' resumed');
    console.log(`${label}: existing draft storage, image upload, private reload, repeated save and explicit publication use server time`);
}
