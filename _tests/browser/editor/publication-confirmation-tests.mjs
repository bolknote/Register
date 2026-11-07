import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {formData, holdRequests, confirmPublication} from './save-tests.mjs';

const now = new Date('2026-11-07T10:00:00Z');
function saved(data, draft, creating = true) {
    return {success: true, action: creating ? 'create' : 'edit', draft, date_mode: draft ? 'now' : 'fixed',
        id: 10, url: '/created', action_url: '/_inplace/post/10', token: 'fixture', revision: 2,
        title: data.get('title'), body: data.get('body'), slug: 'created',
        body_html: `<div class="post body" data-post-inplace-body>${data.get('body')}</div>`,
        published_at: now.getTime() / 1000, datetime: now.toISOString(), time: '7 November', tags: [], scheduled: false};
}

export async function runPublicationConfirmationRegressions(browser, origin) {
    async function withPage(run, creating = true) {
        const page = await browser.newPage({viewport: {width: 1280, height: 800}, timezoneId: 'Europe/Moscow'});
        page.setDefaultTimeout(8000);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.clock.setFixedTime(now);
        try {
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            const requests = holdRequests(page, '**/_inplace/post/*');
            await requests.installed;
            await page.goto(origin + '/recovery.html?draft-tools=1&publish-confirm=1');
            if (creating) await page.getByRole('button', {name: 'New post', exact: true}).click();
            const card = page.locator(creating ? '.post-card.is-editing' : '.post-card');
            if (creating) {
                await card.locator('[data-post-inplace-title]').fill('A deliberate publication <with text>');
                await card.locator('[data-post-inplace-body]').fill('Keep this exact unfinished text.');
            }
            await run(page, card, requests);
            assert.deepEqual(errors, []);
        } finally { await page.close(); }
    }

    await withPage(async (page, card, requests) => {
        await card.evaluate(card => {
            card.classList.add('is-draft-preview');
            card.dataset.postDateMode = 'now';
            card.querySelector('[name="title"]').value = 'Тест и пример';
            card.querySelector('[data-post-inplace-title]').textContent = 'Тест и\u00a0пример';
        });
        await card.locator('.post-edit-start').click();
        await card.locator('.post-edit-save').click();
        await page.getByRole('dialog', {name: 'Publish this post?', exact: true})
            .getByRole('button', {name: 'Continue editing'}).click();
        await page.waitForTimeout(350);
        assert.deepEqual(await page.evaluate(() => RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1).list()), [],
            'Cancelling publication cannot turn presentation-only typography into unsaved text');
        await card.locator('.post-edit-cancel').click();
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        assert.equal(await page.locator('.post-recovery-notice').count(), 0);
        assert.equal(requests.count, 0);
    }, false);

    await withPage(async (page, card, requests) => {
        const body = card.locator('[data-post-inplace-body]');
        const publish = card.getByRole('button', {name: 'Publish', exact: true});
        const dialog = page.getByRole('dialog', {name: 'Publish this post?', exact: true});
        await publish.click();
        await dialog.waitFor();
        assert.equal(await dialog.locator('[data-publish-title]').textContent(), 'A deliberate publication <with text>');
        assert.equal(await dialog.locator('[data-publish-title] > *').count(), 0, 'A title is displayed as text');
        assert.equal(await dialog.evaluate(node => node.matches(':modal')), true, 'Background content is inert');
        for (const button of await dialog.locator('button').all()) {
            assert.ok((await button.boundingBox()).height >= 44, 'Confirmation buttons keep a full touch target');
        }
        assert.equal(await dialog.locator('[data-publish-action="cancel"]').evaluate(node => node === document.activeElement), true);
        await page.keyboard.press('Shift+Tab');
        assert.equal(await dialog.locator('[data-publish-action="confirm"]').evaluate(node => node === document.activeElement), true);
        await page.keyboard.press('Tab');
        assert.equal(await dialog.locator('[data-publish-action="cancel"]').evaluate(node => node === document.activeElement), true);
        await body.evaluate(node => node.focus());
        assert.equal(await dialog.locator('[data-publish-action="cancel"]').evaluate(node => node === document.activeElement), true,
            'The editor cannot steal focus from confirmation');
        await page.keyboard.press('Control+s');
        await page.keyboard.press('Meta+s');
        await page.evaluate(() => document.querySelector('.is-editing form').requestSubmit());
        assert.equal(await page.locator('.post-publish-confirmation').count(), 1);
        assert.equal(requests.count, 0, 'No publication request precedes consent');
        await page.keyboard.press('Enter');
        await dialog.waitFor({state: 'detached'});
        assert.equal(await body.textContent(), 'Keep this exact unfinished text.');
        assert.equal(await card.count(), 1, 'Default Enter keeps editing');
        for (const cancel of ['escape', 'button', 'backdrop']) {
            await body.press('Control+s');
            await dialog.waitFor();
            if (cancel === 'escape') await page.keyboard.press('Escape');
            else if (cancel === 'button') await dialog.getByRole('button', {name: 'Continue editing'}).click();
            else await page.mouse.click(1, 1);
            await dialog.waitFor({state: 'detached'});
            assert.equal(requests.count, 0);
            assert.equal(await body.textContent(), 'Keep this exact unfinished text.');
            assert.equal(await card.evaluate(node => node.getAttribute('aria-busy')), null);
        }
        await body.click();
        await body.press('End');
        await body.press('Control+s');
        await dialog.waitFor();
        await page.keyboard.press('Escape');
        assert.equal(await body.evaluate(node => node === document.activeElement), true);
        await page.keyboard.type(' Continued.');
        assert.equal(await body.textContent(), 'Keep this exact unfinished text. Continued.');
        await body.press('Meta+s');
        await dialog.waitFor();
        await confirmPublication(page);
        const rejected = await requests.next();
        const data = await formData(rejected);
        assert.equal(data.get('publication'), 'publish');
        assert.ok(data.get('body').includes('Continued.'), data.get('body'));
        assert.equal(await card.evaluate(node => node.inert), true);
        await rejected.fulfill({status: 503, json: {success: false, message: 'Please retry'}});
        await card.locator('.post-inplace-edit-error:not([hidden])').waitFor();
        assert.equal(await body.textContent(), 'Keep this exact unfinished text. Continued.');
        await publish.click();
        await dialog.getByRole('button', {name: 'Continue editing'}).click();
        assert.equal(requests.count, 0, 'Retry also requires a fresh confirmation');

        await card.getByRole('button', {name: 'Save draft', exact: true}).click();
        const draftRequest = await requests.next();
        const draftData = await formData(draftRequest);
        assert.equal(draftData.get('publication'), 'draft');
        assert.equal(await page.locator('.post-publish-confirmation').count(), 0, 'Saving a private draft needs no confirmation');
        await draftRequest.fulfill({json: saved(draftData, true)});
        await page.waitForFunction(() => !document.querySelector('[data-post-save-state]'));
        await publish.click();
        await dialog.waitFor();
        assert.equal(requests.count, 0, 'An unchanged stored draft still needs confirmation before publication');
        await confirmPublication(page);
        const publishedRequest = await requests.next();
        const publishedData = await formData(publishedRequest);
        await publishedRequest.fulfill({json: saved(publishedData, false, false)});
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        await page.waitForFunction(() => !document.querySelector('[data-post-save-state]'));
        await page.locator('.post-card[data-post-id="10"] .post-edit-start').click();
        await body.fill('An ordinary edit of a published post.');
        await card.locator('.post-edit-save').click();
        const editRequest = await requests.next();
        assert.equal(await page.locator('.post-publish-confirmation').count(), 0, 'Ordinary edits are saved directly');
        await editRequest.fulfill({json: saved(await formData(editRequest), false, false)});
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
    });

    await withPage(async (page, card, requests) => {
        const chooseFuture = () => card.locator('.post-inplace-datetime').evaluate(node => {
            node.value = '2026-11-09T09:12';
            node.dispatchEvent(new Event('input', {bubbles: true}));
        });
        await chooseFuture();
        await card.locator('.post-edit-save').click();
        const dialog = page.getByRole('dialog', {name: 'Schedule publication?', exact: true});
        await dialog.waitFor();
        assert.match(await dialog.locator('[data-publish-message]').textContent(), /09:12/u);
        assert.equal(await dialog.getByRole('button', {name: 'Schedule', exact: true}).isVisible(), true);
        assert.equal(requests.count, 0);
        await page.keyboard.press('Escape');
        await card.getByRole('button', {name: 'Now', exact: true}).click();
        await card.locator('.post-edit-save').click();
        await page.getByRole('dialog', {name: 'Publish this post?', exact: true}).waitFor();
        await page.keyboard.press('Escape');
        await chooseFuture();
        await card.locator('.post-edit-save').click();
        await confirmPublication(page);
        const request = await requests.next();
        const data = await formData(request);
        assert.equal(data.get('date_mode'), 'fixed');
        assert.equal(Number(data.get('published_at')), Date.parse('2026-11-09T06:12:00Z') / 1000);
        await request.fulfill({status: 503, json: {success: false, message: 'Please retry'}});
        await card.locator('.post-inplace-edit-error:not([hidden])').waitFor();
    });

    await withPage(async (page, card, requests) => {
        const future = Date.parse('2026-11-09T06:12:00Z') / 1000;
        const chooseDate = value => card.locator('.post-inplace-datetime').evaluate((node, value) => {
            node.value = value;
            node.dispatchEvent(new Event('input', {bubbles: true}));
        }, value);
        await chooseDate('2026-11-09T09:12');
        await card.locator('.post-edit-save').click();
        await confirmPublication(page);
        const creation = await requests.next();
        const payload = {...saved(await formData(creation), false), scheduled: true, published_at: future,
            datetime: new Date(future * 1000).toISOString(), schedule_message: 'Scheduled post preview'};
        await creation.fulfill({json: payload});
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        await page.waitForFunction(() => !document.querySelector('[data-post-save-state]'));
        await page.locator('.post-card[data-post-id="10"] .post-edit-start').click();
        await card.locator('[data-post-inplace-body]').fill('An edit retaining the agreed publication date.');
        await card.locator('.post-edit-save').click();
        const edit = await requests.next();
        assert.equal(await page.locator('.post-publish-confirmation').count(), 0, 'Editing an agreed schedule keeps its confirmation');
        const editedBody = (await formData(edit)).get('body');
        await edit.fulfill({json: {...payload, action: 'edit', body: editedBody,
            body_html: `<div class="post body" data-post-inplace-body>${editedBody}</div>`}});
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        await page.waitForFunction(() => !document.querySelector('[data-post-save-state]'));
        await page.locator('.post-card[data-post-id="10"] .post-edit-start').click();
        await chooseDate('2026-11-06T09:12');
        await card.locator('.post-edit-save').click();
        await page.getByRole('dialog', {name: 'Publish this post?', exact: true}).waitFor();
        assert.equal(requests.count, 0, 'A scheduled post cannot become public immediately without confirmation');
        await page.keyboard.press('Escape');
        await chooseDate('2026-11-10T09:12');
        await card.locator('.post-edit-save').click();
        await page.getByRole('dialog', {name: 'Schedule publication?', exact: true}).waitFor();
        assert.equal(requests.count, 0, 'Changing an agreed schedule also needs confirmation');
        await page.keyboard.press('Escape');
    });

    await withPage(async (page, card, requests) => {
        await card.locator('.post-inplace-datetime').evaluate(node => {
            node.value = '2026-11-07T13:01';
            node.dispatchEvent(new Event('input', {bubbles: true}));
        });
        await card.locator('.post-edit-save').click();
        await page.getByRole('dialog', {name: 'Schedule publication?', exact: true}).waitFor();
        await page.clock.setFixedTime(new Date(now.getTime() + 120000));
        await confirmPublication(page);
        const dialog = page.getByRole('dialog', {name: 'Publish this post?', exact: true});
        await dialog.waitFor();
        assert.equal(requests.count, 0, 'A passed schedule date must not turn scheduling into immediate publication without fresh consent');
        assert.equal(await dialog.locator('[data-publish-action="cancel"]').evaluate(node => node === document.activeElement), true);
        await page.keyboard.press('Escape');
    });

    await withPage(async (page, card, requests) => {
        await card.locator('.post-edit-save').click();
        await page.getByRole('dialog', {name: 'Publish this post?', exact: true}).waitFor();
        await page.evaluate(() => document.dispatchEvent(new CustomEvent('register:navigation-will-update')));
        await page.waitForFunction(() => !document.querySelector('.post-publish-confirmation'));
        assert.equal(requests.count, 0, 'Leaving editing closes the pending dialog without publishing');
    });

    await withPage(async (page, card, requests) => {
        await page.locator('.post-publish-template').evaluate(node => node.remove());
        await card.locator('.post-edit-save').click();
        await card.locator('.post-inplace-edit-error:not([hidden])').waitFor();
        assert.equal(requests.count, 0, 'Missing confirmation markup must never silently publish');
        assert.equal(await card.locator('[data-post-inplace-body]').textContent(), 'Keep this exact unfinished text.');
        assert.equal(await card.evaluate(node => editorTest.editorStates.get(node).submitting), false);
    });
    console.log(`${browser.browserType().name()}: publication confirms title and date; safe default, cancellation, keyboard, retries, drafts and existing edits preserve text`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit].filter(engine => !process.env.EDITOR_TEST_BROWSER
            || engine.name() === process.env.EDITOR_TEST_BROWSER)) {
            const browser = await engine.launch();
            try { await runPublicationConfirmationRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
