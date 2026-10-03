import assert from 'node:assert/strict';
import {formData} from './save-tests.mjs';
import {fulfillPreview} from './html-block-tests.mjs';

export async function runPublicRecoveryWarningRegressions(browser, origin) {
    const context = await browser.newContext();
    try {
        const first = await context.newPage();
        await first.goto(origin + '/recovery.html');
        await first.getByRole('button', {name: 'Edit', exact: true}).click();
        await first.locator('.is-editing [data-post-inplace-body]').fill('Restorable public text');
        await first.waitForFunction(() => RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1).list().length > 0);
        const page = await context.newPage();
        await page.goto(origin + '/recovery.html');
        await page.evaluate(() => {
            const setItem = Storage.prototype.setItem;
            Storage.prototype.setItem = function (key, value) {
                if (key.startsWith('register:post-recovery:')) throw new DOMException('Storage full', 'QuotaExceededError');
                return setItem.call(this, key, value);
            };
        });
        await page.getByRole('button', {name: 'Restore text', exact: true}).click();
        const status = page.locator('.is-editing .post-inplace-status');
        assert.match(await status.textContent(), /Unable to save a local copy/iu);
        assert.equal(await status.evaluate(element => element.classList.contains('is-error')), true);
        assert.equal(await page.locator('.is-editing [data-post-inplace-body]').textContent(), 'Restorable public text');
        assert.equal(await page.evaluate(() => RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1).list().length), 1);
        await page.evaluate(() => window.dispatchEvent(new Event('storage')));
        assert.match(await status.textContent(), /Unable to save a local copy/iu);
        console.log('public recovery: a failed restored-copy write retains its error and original copy');
    } finally { await context.close(); }
}

const commentForm = `<form id="comment-form">
    <div data-comment-editor class="comment-editor">
        <div class="comment-editor-toolbar" hidden></div>
        <div class="comment-editor-link-panel" hidden><input data-comment-link-input><button type="button" data-comment-link-remove>Remove</button></div>
        <div class="comment-editor-surface" role="textbox" contenteditable="true" hidden></div>
        <textarea name="text" class="comment-editor-source"></textarea>
    </div><input class="comment-form-id" value="post:9" type="hidden">
</form><script src="/comment-flow.js"></script><script src="/comment-editor.js" defer></script>`;

export async function runHtmlReloadLifecycleRegressions(browser, origin) {
    const context = await browser.newContext();
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    const dialogs = [];
    page.on('pageerror', error => errors.push(String(error)));
    page.on('dialog', async dialog => { dialogs.push(dialog.type()); await dialog.dismiss(); });
    let initialCard;
    let publishedCard = null;
    let gets = 0;
    await page.route('**/author-program.js', route => route.fulfill({contentType: 'text/javascript', body:
        `window.authorTicks = 0; setInterval(() => { window.authorTicks++; }, 25);
         document.body.addEventListener('click', () => { document.documentElement.dataset.authorClick = 'yes'; });`}));
    await page.route('**/recovery.html', async route => {
        gets++;
        const response = await route.fetch();
        let html = await response.text();
        if (publishedCard !== null) html = html.replace('<div class="live-post-feed"></div>', `<div class="live-post-feed">${publishedCard}</div>`);
        await route.fulfill({response, body: html.replace('</html>', commentForm + '</html>')});
    });
    await page.route('**/_inplace/post/9', async route => {
        const data = await formData(route);
        if (data.get('inplace_action') === 'html_preview') return fulfillPreview(route, data);
        assert.equal(data.get('inplace_action'), 'edit');
        const body = data.get('body');
        publishedCard = await page.evaluate(({original, body}) => {
            const template = document.createElement('template');
            template.innerHTML = original;
            const card = template.content.firstElementChild;
            card.querySelector('[data-post-inplace-body]').innerHTML = body;
            card.querySelector('[name="body"]').textContent = body;
            return card.outerHTML;
        }, {original: initialCard, body});
        await route.fulfill({json: {success: true, action: 'edit', revision: 2, title: data.get('title'),
            body_html: `<div class="post body" data-post-inplace-body>${body}</div>`,
            published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
            tags: [], scheduled: false, message: 'Saved'}});
    });
    try {
        await page.goto(origin + '/recovery.html');
        initialCard = await page.locator('.post-card').evaluate(card => card.outerHTML);
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        await page.locator('[data-post-inplace-body]').click();
        await page.keyboard.press('Shift+F10');
        await page.getByRole('button', {name: 'HTML block', exact: true}).click();
        await page.getByRole('textbox', {name: 'HTML code', exact: true}).fill('<p>Author program</p><script src="/author-program.js"></script>');
        await page.getByRole('button', {name: 'Done', exact: true}).click();
        const comment = page.locator('.comment-editor-surface');
        await comment.fill('Latest comment immediately before HTML save');
        await Promise.all([page.waitForNavigation({waitUntil: 'load'}), page.getByRole('button', {name: 'Save', exact: true}).click()]);
        await page.waitForFunction(() => window.authorTicks > 0);
        assert.equal(await page.locator('.comment-editor-source').inputValue(), 'Latest comment immediately before HTML save');
        assert.equal(await comment.textContent(), 'Latest comment immediately before HTML save', 'Recovery must also update an already enhanced comment surface');
        assert.deepEqual(dialogs, []);

        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        await page.getByRole('button', {name: 'Remove block', exact: true}).click();
        const before = gets;
        await comment.fill('Latest comment immediately before program removal');
        await Promise.all([page.waitForNavigation({waitUntil: 'load'}), page.getByRole('button', {name: 'Save', exact: true}).click()]);
        assert.equal(gets, before + 1);
        assert.equal(await page.locator('[data-post-html-source]').count(), 0);
        assert.equal(await comment.textContent(), 'Latest comment immediately before program removal');
        await page.locator('[data-post-inplace-body]').click();
        await page.waitForTimeout(150);
        assert.equal(await page.evaluate(() => window.authorTicks), undefined, 'Removed programs must stop running in the current document');
        assert.equal(await page.locator('html').getAttribute('data-author-click'), null, 'Removed document listeners must stop too');
        assert.deepEqual(errors, []);
        assert.deepEqual(dialogs, []);

        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        await page.locator('[data-post-inplace-body]').click();
        await page.keyboard.press('Shift+F10');
        await page.getByRole('button', {name: 'HTML block', exact: true}).click();
        await page.getByRole('textbox', {name: 'HTML code', exact: true}).fill('<p>New program</p><script src="/author-program.js"></script>');
        await page.getByRole('button', {name: 'Done', exact: true}).click();
        await page.evaluate(() => {
            window.failCommentStorage = true;
            const setItem = Storage.prototype.setItem;
            Storage.prototype.setItem = function (key, value) {
                if (window.failCommentStorage && key.startsWith('comment_text_')) throw new DOMException('Storage full', 'QuotaExceededError');
                return setItem.call(this, key, value);
            };
        });
        await comment.fill('Current comment retained while storage is unavailable');
        const warning = page.waitForEvent('dialog', {timeout: 10000});
        await page.getByRole('button', {name: 'Save', exact: true}).click();
        assert.equal((await warning).type(), 'beforeunload');
        await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
        assert.equal(await comment.textContent(), 'Current comment retained while storage is unavailable');
        await page.evaluate(() => { window.failCommentStorage = false; });
        await page.reload();
        assert.equal(await comment.textContent(), 'Current comment retained while storage is unavailable');
        assert.deepEqual(errors, []);
        assert.deepEqual(dialogs, ['beforeunload']);
        console.log('HTML reload: newest comment survives and removing the last program stops its timer/listeners');
    } finally { await context.close(); }
}
