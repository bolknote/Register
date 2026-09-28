import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const picture = '<div class="post-picture post-media-picture"><img src="/history-image.svg" alt="Existing">'
    + '<div class="post-caption">Saved caption</div></div>';
const originalBody = '<p>Original body</p>' + picture + '<p>After image</p>';

function editorState(page) {
    return page.evaluate(() => {
        const state = window.editorTest.editorStates.get(document.querySelector('.post-card.is-editing'));
        return {html: window.editorTest.editableBodyHtml(state), title: state.title.textContent, history: state.history.length};
    });
}

async function save(page, requests, expected) {
    await page.waitForFunction(expected => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
        .list().some(copy => copy.snapshot.body === expected.html && copy.snapshot.title === expected.title), expected);
    await page.getByRole('button', {name: 'Save', exact: true}).click();
    const request = await requests.next();
    const data = await formData(request);
    assert.equal(data.get('inplace_action'), 'edit');
    assert.equal(data.get('body'), expected.html);
    assert.equal(data.get('title'), expected.title);
    await request.fulfill({json: {
        success: true, action: 'edit', revision: 2, title: data.get('title'),
        body_html: `<div class="post body" data-post-inplace-body>${data.get('body')}</div>`,
        published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
        tags: [], scheduled: false, message: 'Saved',
    }});
    await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
    assert.equal(await page.evaluate(() => localStorage.length), 0);
}

async function replyAlt(request, result) {
    assert.equal((await formData(request)).get('inplace_action'), 'ai_alt');
    await request.fulfill({json: {success: true, action: 'ai_alt', result}});
}

async function waitForAlt(page) {
    await page.waitForFunction(() => window.editorTest.editorStates
        .get(document.querySelector('.post-card.is-editing')).aiAltTasks.size === 0);
}

export async function runFieldHistoryRegressions(browser, origin) {
    async function withPage(body, ai, run) {
        const page = await browser.newPage();
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        try {
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            await page.route('**/history-image.svg', route => route.fulfill({contentType: 'image/svg+xml', body:
                '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="100"><rect width="160" height="100" fill="gray"/></svg>',
            }));
            await page.route('**/recovery-fixture.js', async route => {
                const response = await route.fetch();
                await route.fulfill({response, body: (await response.text())
                    .replace('aiAltEnabled: false', `aiAltEnabled: ${ai}`)
                    .replace("const body = creating ? '' : `<p>Server body ${revision}</p>`;",
                        "const body = creating ? '' : " + JSON.stringify(body) + ';'),
                });
            });
            const requests = holdRequests(page, '**/_inplace/post/9');
            await requests.installed;
            await page.goto(origin + '/recovery.html');
            await page.waitForFunction(() => Array.from(document.querySelectorAll('.post-card img'))
                .every(image => image.complete && image.naturalWidth > 0));
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
            await run(page, requests, modifier);
            assert.deepEqual(errors, []);
        } finally {
            await page.close();
        }
    }

    for (const source of ['keyboard', 'beforeinput']) {
        await withPage(originalBody, false, async (page, requests, modifier) => {
            const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
            const title = page.locator('.post-card.is-editing [data-post-inplace-title]');
            const before = await editorState(page);
            await body.locator(':scope > p').first().click({clickCount: 3});
            await page.keyboard.type('Earlier body edit');
            const edited = await editorState(page);
            await title.click();
            await page.keyboard.press(`${modifier}+a`);
            await page.keyboard.insertText('Changed title');
            const travel = async direction => {
                if (source === 'keyboard') await title.press(`${modifier}+${direction === 'redo' ? 'Shift+' : ''}z`);
                else assert.equal(await title.evaluate((title, direction) => {
                    const event = new InputEvent('beforeinput', {
                        inputType: direction === 'undo' ? 'historyUndo' : 'historyRedo', bubbles: true, cancelable: true,
                    });
                    title.dispatchEvent(event);
                    return event.defaultPrevented;
                }, direction), true);
            };
            for (let i = 0; i < 3; i++) await travel('undo');
            assert.deepEqual(await editorState(page), edited, 'Exhausting title undo must leave the body and its history untouched');
            assert.equal(await title.evaluate(title => title.contains(getSelection().anchorNode)), true);
            await travel('redo');
            assert.equal(await title.textContent(), 'Changed title');
            await body.press(`${modifier}+z`);
            assert.equal((await editorState(page)).html, before.html);
            assert.equal(await title.textContent(), 'Changed title');
            await body.press(`${modifier}+Shift+z`);
            assert.equal((await editorState(page)).html, edited.html);
            await save(page, requests, await editorState(page));

            // Starting another session must not revive the previous title stack
            // or leave duplicate listeners attached to the same title element.
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            await title.press(`${modifier}+z`);
            assert.equal(await title.textContent(), 'Changed title');
            await title.fill('Next session');
            await title.press(`${modifier}+z`);
            assert.equal(await title.textContent(), 'Changed title');
            await title.press(`${modifier}+Shift+z`);
            assert.equal(await title.textContent(), 'Next session');
        });
    }
    console.log('title history: keyboard and native-menu undo stay local, preserve body redo, save and reset between sessions');

    await withPage(originalBody, false, async (page, requests, modifier) => {
        const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
        const title = page.locator('.post-card.is-editing [data-post-inplace-title]');
        const before = await editorState(page);
        await body.locator(':scope > p').first().click({button: 'right'});
        await page.locator('[data-context-ai-action="title"]').click();
        const ai = await requests.next();
        assert.equal((await formData(ai)).get('ai_action'), 'title');
        await ai.fulfill({json: {success: true, action: 'ai', ai_action: 'title', result: 'Suggested title'}});
        await page.waitForFunction(() => !document.querySelector('.post-card.is-ai-working'));
        assert.equal(await title.textContent(), 'Suggested title');
        await title.press(`${modifier}+z`);
        assert.deepEqual(await editorState(page), before);
        await title.press(`${modifier}+Shift+z`);
        assert.equal(await title.textContent(), 'Suggested title');
        await save(page, requests, await editorState(page));
    });
    console.log('title history: AI suggestions can be undone and redone independently of the body');

    await withPage(originalBody, false, async (page, requests, modifier) => {
        const title = page.locator('.post-card.is-editing [data-post-inplace-title]');
        await title.fill('Recovered title');
        await page.waitForFunction(() => window.RegisterPostRecovery.createStore(localStorage, '/_inplace/tags', 1)
            .list().some(copy => copy.snapshot.title === 'Recovered title'));
        await page.reload();
        await page.getByRole('button', {name: 'Restore text', exact: true}).click();
        const recovered = await editorState(page);
        assert.equal(recovered.title, 'Recovered title');
        await title.press(`${modifier}+z`);
        assert.equal(await title.textContent(), 'Server title 1');
        assert.equal((await editorState(page)).html, recovered.html);
        await title.press(`${modifier}+Shift+z`);
        assert.deepEqual(await editorState(page), recovered);
        await save(page, requests, recovered);
    });
    console.log('title history: restoring a recovery copy is undoable without changing the restored body');

    for (const history of ['undo', 'redo']) {
        await withPage(originalBody.replace('alt="Existing"', 'alt=""'), true, async (page, requests, modifier) => {
            const ai = await requests.next();
            const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
            await body.locator(':scope > p').first().fill('Changed body');
            await body.press(`${modifier}+z`);
            if (history === 'redo') await body.press(`${modifier}+Shift+z`);
            const beforeReply = await editorState(page);
            await replyAlt(ai, 'Generated description');
            await waitForAlt(page);
            assert.equal(await body.locator('img').getAttribute('alt'), 'Generated description');
            const after = await editorState(page);
            assert.equal(after.html, beforeReply.html.replace('alt=""', 'alt="Generated description"'));
            assert.equal(requests.count, 0, 'Restoring history must retain the pending request, without generating again');
            await body.press(`${modifier}+z`);
            assert.equal((await editorState(page)).html, beforeReply.html);
            await body.press(`${modifier}+Shift+z`);
            assert.equal((await editorState(page)).html, after.html);
            await save(page, requests, after);
        });
    }
    console.log('image history: delayed descriptions survive undo/redo, remain undoable, and persist in recovery and saves');

    for (const restore of [false, true]) {
        const bodyHtml = '<p>Original body</p>' + picture.repeat(2).replaceAll('alt="Existing"', 'alt=""') + '<p>After image</p>';
        await withPage(bodyHtml, true, async (page, requests, modifier) => {
            const firstAi = await requests.next();
            const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
            await body.focus();
            await body.locator('.post-media-picture').first().evaluate(picture => {
                const range = document.createRange();
                range.selectNode(picture);
                getSelection().removeAllRanges();
                getSelection().addRange(range);
            });
            await page.keyboard.press('Backspace');
            assert.equal(await body.locator('img').count(), 1);
            if (restore) await body.press(`${modifier}+z`);
            await replyAlt(firstAi, 'First description');
            const secondAi = await requests.next();
            assert.deepEqual(await body.locator('img').evaluateAll(images => images.map(image => image.alt)),
                restore ? ['First description', ''] : ['']);
            await replyAlt(secondAi, 'Second description');
            await waitForAlt(page);
            assert.deepEqual(await body.locator('img').evaluateAll(images => images.map(image => image.alt)),
                restore ? ['First description', 'Second description'] : ['Second description']);
            assert.equal(requests.count, 0);
            await save(page, requests, await editorState(page));
        });
    }
    console.log('image history: identical occurrences retain their own requests, while deleted images cannot steal another description');

    await withPage(originalBody.replace('alt="Existing"', 'alt=""'), true, async (page, requests, modifier) => {
        const ai = await requests.next();
        const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
        await body.locator(':scope > p').first().fill('Changed body');
        await body.press(`${modifier}+z`);
        await body.locator(':scope > p').last().click();
        await body.locator('img').click({button: 'right'});
        await page.locator('[data-context-image-alt-input]').fill('Manual description');
        await waitForAlt(page);
        await replyAlt(ai, 'Stale description');
        assert.equal(await body.locator('img').getAttribute('alt'), 'Manual description');
        await body.locator(':scope > p').last().click();
        await save(page, requests, await editorState(page));
    });
    console.log('image history: editing alt after undo still cancels the pending AI reply and saves the manual description');
}
