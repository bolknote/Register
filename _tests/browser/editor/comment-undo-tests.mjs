import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';

const comment = `<article id="4" class="comment-item" data-comment-id="1" data-moderation-state="visible">
    <span id="comment-1" class="comment-stable-anchor"></span>
    <nav class="comment-moderation"><form class="comment-moderation-action" method="post" action="/comment-moderate"
        data-moderation-action="delete" data-confirm-title="Delete this comment?"
        data-confirm="Restoration is available for ten minutes." data-confirm-context="Comment #1 — Author">
        <input name="moderation_action" type="hidden" value="delete">
        <input name="comment_id" type="hidden" value="1">
        <input name="comment_anchor" type="hidden" value="4">
        <input name="return_to" type="hidden" value="/comment.html">
        <input name="moderation_token" type="hidden" value="csrf">
        <button type="submit" aria-label="Delete comment">Delete</button>
    </form></nav><div class="comment-body">A normal comment.</div>
</article>`;

async function assertSingleRecovery(page, inline) {
    await page.locator(inline ? '.comment-undo-inline' : '.comment-undo-history').waitFor();
    assert.equal(await page.locator('.comment-undo-notice:visible').count(), 1,
        'A deleted comment must have only one visible recovery block');
    assert.equal(await page.locator('.comment-undo-history').isVisible(), !inline);
    assert.equal(await page.locator('.comment-undo-inline:visible').count(), inline ? 1 : 0);
}

export async function runCommentUndoRegressions(browser, origin) {
    const page = await browser.newPage({reducedMotion: 'reduce'});
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const requests = [];
    let deleted = false;
    let failRestore = true;
    try {
        await page.route('**/comment.html', async route => {
            const response = await route.fetch();
            await route.fulfill({response, body: (await response.text()).replace('<div id="fixture"></div>',
                '<div id="fixture"><div class="comment-thread">' + (deleted ? '' : comment) + '</div></div>')});
        });
        await page.route('**/comment-moderate', async route => {
            const request = route.request();
            const form = await new Request(request.url(), {
                method: 'POST', headers: request.headers(), body: request.postDataBuffer(),
            }).formData();
            requests.push(Object.fromEntries(form));
            if (form.get('moderation_action') === 'delete') {
                deleted = true;
                const now = Date.now();
                await route.fulfill({json: {success: true, action: 'delete', undo_token: 'signed-1',
                    undo_comment_id: 1, undo_context: 'Comment #1 — Author', undo_excerpt: 'A normal comment.',
                    undo_server_time: now, undo_expires_at: now + 600000}});
            } else if (failRestore) {
                await route.fulfill({status: 503, json: {success: false}});
            } else {
                deleted = false;
                await route.fulfill({json: {success: true, action: 'restore'}});
            }
        });
        await page.goto(origin + '/comment.html');
        await page.getByRole('button', {name: 'Delete comment', exact: true}).focus();
        await page.keyboard.press('Enter');
        await page.locator('dialog[open] .comment-action-confirm').click();
        await assertSingleRecovery(page, true);
        assert.equal(await page.locator('dialog[open]').count(), 0);

        for (let attempt = 0; attempt < 2; attempt++) {
            await page.locator('.comment-undo-toggle').click();
            await assertSingleRecovery(page, false);
            await page.evaluate(() => document.dispatchEvent(new CustomEvent('register:live-synchronized')));
            await assertSingleRecovery(page, false);
            await page.locator('.comment-undo-collapse').click();
            await assertSingleRecovery(page, true);
        }

        await page.reload();
        await assertSingleRecovery(page, true);
        await page.locator('.comment-undo-toggle').click();
        await page.reload();
        await assertSingleRecovery(page, false);
        await page.locator('.comment-undo-collapse').click();

        // Live updates may omit a deleted leaf entirely; its inline Undo survives.
        await page.evaluate(() => {
            document.querySelector('.comment-thread').replaceChildren();
            document.dispatchEvent(new CustomEvent('register:live-synchronized'));
        });
        await assertSingleRecovery(page, true);
        await page.locator('.comment-undo-inline .comment-undo-restore').click();
        await page.waitForFunction(() => document.querySelector('.comment-undo-inline .comment-undo-message')
            ?.textContent.includes('Try again'));
        await assertSingleRecovery(page, true);
        assert.equal(await page.locator('.comment-undo-inline .comment-undo-restore').evaluate(button => button === document.activeElement), true,
            'A failed restoration keeps keyboard focus on the visible retry button');

        failRestore = false;
        await page.keyboard.press('Enter');
        await page.waitForFunction(() => JSON.parse(sessionStorage.getItem('register.commentUndo.v2')).entries[0].state === 'restored');
        assert.equal(await page.locator('.comment-undo-history').isVisible(), false,
            'Inline restoration must not open the history panel');
        await page.evaluate(html => {
            document.querySelector('.comment-thread').innerHTML = html;
            document.dispatchEvent(new CustomEvent('register:live-synchronized'));
        }, comment);
        assert.equal(await page.locator('.comment-undo-inline:visible').count(), 0);
        assert.equal(await page.locator('.comment-item').evaluate(item => item === document.activeElement), true);
        assert.deepEqual(requests.map(request => request.moderation_action), ['delete', 'restore', 'restore']);
        assert.equal(requests[2].undo_token, 'signed-1');
        assert.deepEqual(errors, []);
        console.log('comment recovery: deletion, history, reload, live patches and retry use a single visible recovery block');
    } finally {
        await page.close();
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit]) {
            const browser = await engine.launch();
            try {
                console.log(engine.name());
                await runCommentUndoRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            } finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
