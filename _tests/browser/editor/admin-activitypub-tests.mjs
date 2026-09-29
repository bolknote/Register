import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const changed = 'Publication data changed. Build the preview again.';
const payload = text => ({
    success: true, message: 'Saving will send Update.', content_html: `<p>${text}</p>`,
    pretty_json: JSON.stringify({content: text}), owner_handle: '@author@example.test',
    canonical_url: 'https://example.test/page', provisional_message: '',
});
const button = page => page.getByRole('button', {name: 'Build ActivityPub preview', exact: true});
const status = page => page.locator('[data-activitypub-preview-status]');

async function withPage(browser, origin, codemirror, run) {
    const page = await browser.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    try {
        const previews = holdRequests(page, '**/admin-activitypub-preview');
        const saves = holdRequests(page, '**/admin-save*');
        await previews.installed;
        await saves.installed;
        await page.goto(origin + '/admin.html?id=9&activitypub=1&tags=1'
            + (codemirror ? '&codemirror=1&toolbar=1' : ''));
        await page.waitForFunction(() => window.adminEditorReady);
        await run(page, previews, saves);
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
}

async function checkRendered(page, text) {
    await page.waitForFunction(text => document.querySelector('[data-activitypub-preview-json]').textContent
        === JSON.stringify({content: text}), text);
    assert.equal(await page.locator('[data-activitypub-preview-result]').isVisible(), true);
    assert.equal(await status(page).textContent(), 'Saving will send Update.');
    assert.equal(await status(page).evaluate(element => element.classList.contains('is-error')), false);
    assert.equal(await button(page).isEnabled(), true);
    assert.equal(await page.locator('[data-activitypub-preview-metadata]').textContent(),
        '@author@example.test · https://example.test/page');
    await page.frameLocator('[data-activitypub-preview-frame]').getByText(text, {exact: true}).waitFor();
}

export async function runActivityPubPreviewRegressions(browser, origin) {
    for (const codemirror of [false, true]) {
        for (const [phase, edit] of [
            ['pending', 'body'], ['rendered', 'body'], ['pending', 'title'], ['rendered', 'tags'],
            ['pending', 'federation'], ['rendered', 'published'], ['rendered', 'reset'],
            ['pending', 'save'], ['rendered', 'save'],
        ]) {
            await withPage(browser, origin, codemirror, async (page, previews, saves) => {
                await button(page).click();
                const original = await previews.next();
                const initialData = await formData(original);
                assert.equal(initialData.get('entity_name'), 'Article');
                assert.equal(initialData.get('content_id'), '9');
                assert.equal(initialData.get('body'), 'Server body');
                if (phase === 'rendered') {
                    await original.fulfill({json: payload('Original representation')});
                    await checkRendered(page, 'Original representation');
                }

                if (edit === 'body') {
                    if (codemirror) {
                        await page.locator('.CodeMirror').click();
                        const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
                        await page.keyboard.press(`${modifier}+a`);
                        await page.keyboard.insertText('New body');
                        await page.waitForFunction(() => window.adminEditor.getValue() === 'New body');
                    } else await page.locator('[name="body"]').fill('New body');
                } else if (edit === 'title') await page.locator('[name="title"]').fill('New title');
                else if (edit === 'tags') await page.locator('.editor-tags-text-input').fill('New tag');
                else if (edit === 'federation') await page.getByLabel('Federation', {exact: true}).selectOption('disabled');
                else if (edit === 'published') await page.getByLabel('Published', {exact: true}).uncheck();
                else if (edit === 'reset') await page.evaluate(() => document.querySelector('form').reset());
                else {
                    await page.getByRole('button', {name: 'Save', exact: true}).click();
                    const save = await saves.next();
                    assert.equal((await formData(save)).get('body'), 'Server body');
                    await save.fulfill({json: {revision: 2, urlStatus: 'ok', urlTitle: '', url: '/page'}});
                    await page.waitForFunction(() => document.querySelector('[name="revision"]').value === '2');
                }

                assert.equal(await page.locator('[data-activitypub-preview-result]').isVisible(), false,
                    `${phase}/${edit}: changed publication data must invalidate the preview`);
                assert.equal(await button(page).isEnabled(), true, 'An obsolete request must not block rebuilding');
                assert.equal(await status(page).textContent(), changed);
                assert.equal(await page.locator('form').evaluate(form => form.inert), false);

                await button(page).click();
                const current = await previews.next();
                const currentData = await formData(current);
                assert.equal(currentData.get('body'), edit === 'body' ? 'New body' : 'Server body');
                assert.equal(currentData.get('title'), edit === 'title' ? 'New title' : 'Server title');
                assert.equal(currentData.get('tags'), edit === 'tags' ? 'old, New tag' : 'old');
                assert.equal(currentData.has('published'), edit !== 'published');
                assert.equal(currentData.get('activitypub_publication'), edit === 'federation' ? 'disabled' : 'inherit');
                assert.equal(currentData.get('revision'), edit === 'save' ? '2' : '1');
                if (phase === 'pending') {
                    await original.fulfill({json: payload('Obsolete representation')});
                    assert.equal(await button(page).isEnabled(), false, 'An obsolete completion must not finish the new request');
                }
                await current.fulfill({json: payload('Current representation')});
                await checkRendered(page, 'Current representation');
                assert.equal(await page.evaluate(() => Boolean(window.onbeforeunload())), !['save', 'reset'].includes(edit),
                    'Previewing must not mark editorial changes as saved');
                assert.equal(saves.count, 0, 'Previewing must not submit the editor');
            });
            console.log(`ActivityPub preview: ${codemirror ? 'CodeMirror' : 'textarea'} ${phase}/${edit} invalidates stale data and rebuilds from current fields`);
        }

        for (const failure of [false, true]) {
            await withPage(browser, origin, codemirror, async (page, previews) => {
                // Hold JSON decoding after fetch has completed: aborting the
                // network can no longer prevent this response or error settling.
                await page.evaluate(failure => {
                    const original = Response.prototype.json;
                    let first = true;
                    Response.prototype.json = async function (...args) {
                        const data = await original.apply(this, args);
                        if (this.url.includes('/admin-activitypub-preview') && first) {
                            first = false;
                            await new Promise(resolve => { window.releasePreviewJson = resolve; });
                            if (failure) throw new SyntaxError('Obsolete response failed to decode');
                        }
                        return data;
                    };
                }, failure);
                await button(page).click();
                await (await previews.next()).fulfill({json: payload('Obsolete decoded representation')});
                await page.waitForFunction(() => typeof window.releasePreviewJson === 'function');
                await page.locator('[name="title"]').fill('Current title');
                assert.equal(await button(page).isEnabled(), true);
                await button(page).click();
                const current = await previews.next();
                await current.fulfill({json: payload('Current decoded representation')});
                await checkRendered(page, 'Current decoded representation');
                await page.evaluate(() => window.releasePreviewJson());
                await page.waitForTimeout(50);
                await checkRendered(page, 'Current decoded representation');
            });
            console.log(`ActivityPub preview: ${codemirror ? 'CodeMirror' : 'textarea'} ignores an obsolete JSON ${failure ? 'failure' : 'result'} after rebuilding`);
        }

        await withPage(browser, origin, codemirror, async (page, previews, saves) => {
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            const save = await saves.next();
            await button(page).click();
            const old = await previews.next();
            assert.equal((await formData(old)).get('revision'), '1');
            await save.fulfill({json: {revision: 2, urlStatus: 'ok', urlTitle: '', url: '/page'}});
            await page.waitForFunction(() => document.querySelector('[name="revision"]').value === '2');
            assert.equal(await button(page).isEnabled(), true);
            assert.equal(await status(page).textContent(), changed);
            await old.fulfill({json: payload('Before saving')});
            assert.equal(await page.locator('[data-activitypub-preview-result]').isVisible(), false);
            await button(page).click();
            const current = await previews.next();
            assert.equal((await formData(current)).get('revision'), '2');
            await current.fulfill({json: payload('After saving')});
            await checkRendered(page, 'After saving');
            assert.equal(await page.evaluate(() => window.onbeforeunload()), undefined);
        });
        console.log(`ActivityPub preview: ${codemirror ? 'CodeMirror' : 'textarea'} invalidates a request started during a save when saving completes`);
    }
}
