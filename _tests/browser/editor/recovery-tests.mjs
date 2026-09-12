import assert from 'node:assert/strict';

export async function runRecoveryRegressions(browser, origin) {
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    const copies = () => page.evaluate(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1).list());
    const open = async (query = '') => {
        await page.goto(origin + '/recovery.html' + query);
        await page.waitForFunction(() => window.editorTest && window.RegisterPostRecovery);
    };
    const edited = () => page.locator('.post-card.is-editing');
    const waitForCopy = async () => {
        await page.waitForFunction(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1).list().length > 0);
    };
    const reset = async () => {
        await page.evaluate(() => {
            document.querySelectorAll('.post-card.is-editing').forEach(card => window.editorTest.editorStates.get(card)?.recovery?.stop(true));
            localStorage.clear();
        });
        await open();
    };
    try {
        await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        await open();
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        await edited().locator('[data-post-inplace-title]').fill('Unfinished title');
        await edited().locator('[data-post-inplace-body]').fill('Text before connection loss');
        await context.setOffline(true);
        await edited().locator('[data-post-inplace-body]').press('End');
        await edited().locator('[data-post-inplace-body]').press('Enter');
        await edited().locator('[data-post-inplace-body]').pressSequentially('Written offline');
        await edited().locator('.post-tags-text-input').fill('unfinished ? tag');
        await waitForCopy();
        await context.setOffline(false);
        await page.reload();
        assert.equal(await page.locator('[data-post-inplace-body]').textContent(), 'Server body 1');
        await page.getByRole('button', {name: 'Restore text'}).click();
        assert.equal(await edited().locator('[data-post-inplace-title]').textContent(), 'Unfinished title');
        assert.match(await edited().locator('[data-post-inplace-body]').textContent(), /Written offline/u);
        assert.equal(await edited().locator('.post-tags-text-input').inputValue(), 'old, unfinished ? tag');
        assert.ok(!(JSON.stringify(await copies())).includes('private-fixture-token'));
        assert.match(await edited().locator('.post-inplace-status').textContent(), /Text restored/u);
        page.once('dialog', dialog => dialog.accept());
        await edited().getByRole('button', {name: 'Cancel', exact: true}).click();
        assert.equal(await page.locator('[data-post-inplace-body]').textContent(), 'Server body 1');
        assert.equal(await page.locator('[data-post-inplace-title]').textContent(), 'Server title 1');
        assert.equal(await page.locator('.post-inplace-status').textContent(), '');
        assert.equal(await page.locator('.post-inplace-status').isVisible(), false);
        assert.equal(await page.locator('.post-inplace-status.is-editor-toast').count(), 0);
        console.log('recovery: existing text, unfinished tags and offline typing survive reload');

        await reset();
        await page.getByRole('button', {name: 'New post', exact: true}).click();
        await edited().locator('[data-post-inplace-body]').fill('New untitled text');
        await waitForCopy();
        await page.reload();
        assert.equal(await page.locator('[data-post-creating]').count(), 0);
        await page.getByRole('button', {name: 'Restore text'}).click();
        assert.equal(await edited().locator('[data-post-inplace-title]').textContent(), '');
        assert.equal(await edited().locator('[data-post-inplace-body]').textContent(), 'New untitled text');
        page.once('dialog', dialog => dialog.accept());
        await edited().getByRole('button', {name: 'Cancel', exact: true}).click();
        assert.equal((await copies()).length, 0);
        console.log('recovery: untitled new post restores and deliberate cancellation removes its copy');

        await reset();
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        await edited().locator('[data-post-inplace-body]').fill('Local revision one');
        await waitForCopy();
        await open('?revision=2');
        assert.match(await page.locator('.post-recovery-notice').textContent(), /newer version/u);
        assert.equal(await page.locator('[data-post-inplace-body]').textContent(), 'Server body 2');
        await page.getByRole('button', {name: 'Restore text'}).click();
        assert.equal(await edited().locator('[name="revision"]').inputValue(), '2');
        let submitted;
        await page.route('**/_inplace/post/9', async route => {
            submitted = new URLSearchParams();
            const body = route.request().postData() || '';
            assert.match(body, /name="revision"\r?\n\r?\n2/u);
            await route.fulfill({json: {
                success: true, action: 'edit', title: 'Server title 2', revision: 3,
                body_html: '<div class="post body" data-post-inplace-body><p>Local revision one</p></div>',
                published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
                tags: [], scheduled: false, message: 'Saved',
            }});
        });
        await edited().getByRole('button', {name: 'Save', exact: true}).click();
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        assert.ok(submitted);
        assert.equal((await copies()).length, 0);
        assert.equal(await page.locator('.post-inplace-status').textContent(), 'Saved');
        assert.equal(await page.locator('.post-inplace-status.is-editor-toast, .post-inplace-status.is-error').count(), 0);
        assert.equal(await page.locator('.post-inplace-edit-error').isVisible(), false);
        await page.unroute('**/_inplace/post/9');
        console.log('recovery: server changes require restoration choice, current revision is retained and save clears the copy');

        await reset();
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        await edited().locator('[data-post-inplace-title]').fill('Private account one title');
        await waitForCopy();
        await open('?account=2');
        assert.equal(await page.locator('.post-recovery-notice').count(), 0);
        assert.ok(!(await page.locator('body').textContent()).includes('Private account one title'));
        await open('?account=0');
        assert.equal(await page.locator('.post-recovery-notice').count(), 0);
        await open();
        assert.match(await page.locator('.post-recovery-notice').textContent(), /Private account one title/u);
        page.once('dialog', dialog => dialog.accept());
        await page.getByRole('button', {name: 'Delete local copy'}).click();
        assert.equal((await copies()).length, 0);
        console.log('recovery: account changes hide private drafts; explicit deletion removes them');

        await page.evaluate(() => {
            const store = window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1);
            store.save({version: 1, id: 'malicious', target: '9', revision: 1, savedAt: Date.now(), snapshot: {
                title: 'Safe title', body: '<p>Safe <strong>formatted</strong> text</p><img src="x" onerror="window.recoveryXss=1"><a href="javascript:window.recoveryXss=1">link</a><svg onload="window.recoveryXss=1"></svg><iframe srcdoc="bad"></iframe><script>window.recoveryXss=1</script>',
                tags: '', date: '2026-09-06T12:00', slug: '', mediaIds: [],
            }});
        });
        await page.reload();
        await page.getByRole('button', {name: 'Restore text'}).click();
        assert.equal(await edited().locator('.body script, .body iframe, .body svg, .body [onerror]').count(), 0);
        assert.equal(await edited().locator('.body strong').textContent(), 'formatted');
        assert.equal(await page.evaluate(() => window.recoveryXss), undefined);
        console.log('recovery: locally forged HTML cannot execute code and ordinary formatting survives');

        await reset();
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        await edited().locator('[data-post-inplace-body]').evaluate(body => { body.textContent = 'x'.repeat(550000); });
        await page.waitForFunction(() => document.querySelector('.post-inplace-status.is-error:not([hidden])')?.textContent.includes('local copy'));
        assert.equal((await copies()).length, 0);
        page.once('dialog', dialog => dialog.accept());
        await edited().getByRole('button', {name: 'Cancel', exact: true}).click();
        assert.equal(await page.locator('.post-inplace-status').textContent(), '');
        assert.equal(await page.locator('.post-inplace-status').isVisible(), false);
        console.log('recovery: oversized text reports local persistence failure visibly');

        await reset();
        let releases = 0;
        await page.route('**/_inplace/post/9', async route => {
            const request = route.request().postData() || '';
            if (request.includes('media_release')) releases++;
            await route.fulfill({json: {success: true, action: 'media', kind: 'audio', media_id: 501, url: '/recording.mp3', name: 'Recording'}});
        });
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        await page.evaluate(() => {
            const state = window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
            window.editorTest.insertMediaFiles(state, [new File(['audio'], 'recording.mp3', {type: 'audio/mpeg'})], null);
        });
        await page.waitForFunction(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1).list()[0]?.snapshot.mediaIds.includes(501));
        await page.reload();
        assert.equal(releases, 0, 'pagehide must not delete uploaded media referenced by the local copy');
        await page.getByRole('button', {name: 'Restore text'}).click();
        assert.equal(await edited().locator('audio').getAttribute('src'), '/recording.mp3');
        assert.deepEqual(await edited().evaluate(card => [...window.editorTest.editorStates.get(card).uploadedMediaIds]), [501]);
        await page.unroute('**/_inplace/post/9');
        console.log('recovery: completed uploads survive pagehide and rejoin the restored editor');

        await reset();
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        await edited().locator('[data-post-inplace-body]').fill('First tab text');
        await waitForCopy();
        const second = await context.newPage();
        await second.goto(origin + '/recovery.html');
        await second.getByRole('button', {name: 'Edit', exact: true}).click();
        await second.locator('.post-card.is-editing [data-post-inplace-body]').fill('Second tab text');
        await page.waitForFunction(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1).list().length === 2);
        assert.deepEqual((await copies()).map(record => record.snapshot.body.replace(/<[^>]+>/gu, '')).sort(), ['First tab text', 'Second tab text']);
        await second.close();
        console.log('recovery: simultaneous tabs retain independent copies');

        await reset();
        assert.equal(await page.locator('.post-inplace-edit-form input[name="slug"]').getAttribute('type'), 'hidden');
        assert.equal(await page.getByText('Post address', {exact: true}).count(), 0);
        console.log('editor: the quick editor keeps the canonical slug without exposing a rare address field');
        assert.deepEqual(errors, []);
    } finally {
        await context.close();
    }
}
