import assert from 'node:assert/strict';

async function withContext(browser, run) {
    const context = await browser.newContext();
    const errors = [];
    context.on('page', page => {
        page.setDefaultTimeout(10000);
        page.on('pageerror', error => errors.push(String(error)));
        page.on('dialog', dialog => dialog.accept());
    });
    try {
        await run(context);
        assert.deepEqual(errors, []);
    } finally {
        await context.close();
    }
}

export async function runAdminRecoveryPresenceRegressions(browser, origin) {
    for (const codemirror of [false, true]) {
        const mode = codemirror ? 'CodeMirror' : 'textarea';
        const url = origin + '/admin.html?id=9' + (codemirror ? '&codemirror=1' : '');
        const open = async context => {
            const page = await context.newPage();
            await page.goto(url);
            await page.waitForFunction(() => window.adminEditorReady);
            return page;
        };
        const setBody = async (page, text) => {
            if (codemirror) await page.evaluate(value => window.adminEditor.setValue(value), text);
            else await page.locator('[name="body"]').fill(text);
        };

        await withContext(browser, async context => {
            const first = await open(context);
            await setBody(first, 'Independent draft in the original tab');
            const original = await first.evaluate(() => window.adminDrafts.list('9')[0]);
            const second = await open(context);
            await second.getByRole('button', {name: 'Restore draft', exact: true}).click();
            await setBody(second, 'Different draft in the restoring tab');
            assert.equal(await second.evaluate(id => window.adminDrafts.list('9').some(copy => copy.id === id), original.id), false);

            // No new input in the original tab: unloading must retain its own version.
            await first.reload();
            await first.waitForFunction(() => window.adminEditorReady);
            const copies = await second.evaluate(() => window.adminDrafts.list('9'));
            assert.equal(copies.find(copy => copy.id === original.id)?.snapshot.find(([name]) => name === 'body')?.[1],
                'Independent draft in the original tab');
            assert.equal(copies.some(copy => copy.snapshot.some(([name, value]) => name === 'body'
                && value === 'Different draft in the restoring tab')), true);
        });
        console.log(`admin recovery: restoring in another tab preserves the unchanged original draft on reload (${mode})`);

        await withContext(browser, async context => {
            const first = await open(context);
            await setBody(first, 'Draft kept while other copies fill storage');
            const original = await first.evaluate(() => window.adminDrafts.list('9')[0]);
            const second = await open(context);
            await second.evaluate(source => {
                for (let index = 0; index < 10; index++) {
                    window.adminDrafts.save({...source, id: 'pruned-copy-' + index, target: 'new',
                        savedAt: Date.now() + index + 1, snapshot: [['body', 'Other draft ' + index]]});
                }
            }, original);
            assert.equal(await second.evaluate(id => window.adminDrafts.list().some(copy => copy.id === id), original.id), false);

            await first.reload();
            await first.waitForFunction(() => window.adminEditorReady);
            assert.equal(await second.evaluate(id => window.adminDrafts.list('9').find(copy => copy.id === id)
                ?.snapshot.find(([name]) => name === 'body')?.[1], original.id), 'Draft kept while other copies fill storage');
            assert.equal(await second.evaluate(() => window.adminDrafts.list().length), 10);
        });
        console.log(`admin recovery: an unchanged active draft returns after storage pruning (${mode})`);

        await withContext(browser, async context => {
            const first = await open(context);
            await setBody(first, 'Draft still open after storage fails');
            const original = await first.evaluate(() => window.adminDrafts.list('9')[0]);
            const second = await open(context);
            await second.evaluate(copy => window.adminDrafts.remove(copy), original);
            await first.evaluate(() => {
                const setItem = Storage.prototype.setItem;
                Storage.prototype.setItem = function (key, value) {
                    if (key.startsWith('register:admin-recovery:')) throw new DOMException('Quota exceeded', 'QuotaExceededError');
                    return setItem.call(this, key, value);
                };
                window.dispatchEvent(new Event('pagehide'));
            });
            assert.match(await first.locator('.editor-recovery').textContent(), /local draft could not be saved/iu);
            assert.equal(await first.locator('[name="body"]').inputValue(), 'Draft still open after storage fails');
        });
        console.log(`admin recovery: failure to rewrite a missing copy warns without changing the form (${mode})`);

        await withContext(browser, async context => {
            const abandoned = await open(context);
            await setBody(abandoned, 'Explicitly discarded draft');
            await abandoned.close();
            const page = await open(context);
            assert.equal(await page.locator('[name="body"]').inputValue(), 'Server body');
            await page.getByRole('button', {name: 'Discard draft', exact: true}).click();
            await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
            assert.equal(await page.evaluate(() => window.adminDrafts.list('9').length), 0);
            await page.reload();
            await page.waitForFunction(() => window.adminEditorReady);
            assert.equal(await page.locator('.editor-recovery').count(), 0);
        });
        console.log(`admin recovery: explicit discard stays removed from an unchanged clean form (${mode})`);
    }
}
