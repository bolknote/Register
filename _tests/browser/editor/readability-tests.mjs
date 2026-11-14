import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';

const panelPath = '**/editor/readability/panel.js';
const wordlistPath = '**/editor/readability/ru-common.js';
const workerPath = '**/editor/readability/worker.js';

export async function runReadabilityRegressions(browser, origin) {
    const page = await browser.newPage({viewport: {width: 1440, height: 900}, reducedMotion: 'reduce'});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.setDefaultTimeout(10000);
    try {
        // Firefox exposes the worker entry request, but not its imports.
        const canHoldDictionary = browser.browserType().name() === 'chromium';
        let heldPanel;
        let heldDictionary;
        await page.route(panelPath, route => { heldPanel = route; });
        if (canHoldDictionary) await page.route(wordlistPath, route => { heldDictionary = route; });
        const panelRequested = page.waitForRequest(panelPath);
        await page.goto(origin + '/readability.html');
        const panel = page.locator('.post-editor-readability');
        const card = page.locator('.post-card[data-post-creating]');
        const editor = card.locator('[data-post-inplace-body]');
        const value = key => panel.locator('[data-readability-value="' + key + '"]');
        await page.getByRole('button', {name: 'New post', exact: true}).click();
        await panelRequested;
        assert.equal(await card.locator('[data-post-inplace-title]').evaluate(title => document.activeElement === title), true);
        assert.equal(await editor.getAttribute('contenteditable'), 'true');
        await editor.fill('Идёт снег. Дети лепят снеговика.');
        const bodyBeforePanel = await editor.boundingBox();
        assert.equal(await panel.count(), 0, 'An unfinished module download must not block editing');
        const dictionaryRequested = canHoldDictionary ? page.waitForRequest(wordlistPath) : null;
        await heldPanel.continue();
        await page.unroute(panelPath);
        await panel.waitFor({state: 'visible'});
        await value('flesch').filter({hasText: /^71$/u}).waitFor();
        assert.equal(await value('sentence').textContent(), '2,5');
        const bounds = await panel.boundingBox();
        assert.ok(bounds.x >= bodyBeforePanel.x + bodyBeforePanel.width + 15);
        assert.ok(bounds.x + bounds.width <= 1440 - 16);
        assert.equal(await editor.evaluate(body => body.getBoundingClientRect().width), bodyBeforePanel.width);
        assert.equal(await editor.evaluate(body => document.activeElement === body), true);
        if (dictionaryRequested) {
            await dictionaryRequested;
            assert.equal(await value('rare').textContent(), '—');
            await heldDictionary.continue();
            await page.unroute(wordlistPath);
        }
        await value('rare').filter({hasText: /^\d+$/u}).waitFor();

        await panel.locator('summary').click();
        assert.equal(await value('words').textContent(), '5');
        const longSentence = 'Я '.repeat(24) + 'думаю.';
        await editor.evaluate((body, sentence) => {
            body.innerHTML = '<p>' + sentence.replace('Я Я', 'Я\nЯ') + '</p><pre>Этот исходный код не входит в прозу.</pre>'
                + '<p hidden>Этот абзац скрыт.</p><p>Дети лепят снеговика.</p>';
        }, longSentence);
        assert.equal(await value('words').textContent(), '5', 'Values remain stable until the pause');
        await value('words').filter({hasText: /^28$/u}).waitFor();
        assert.equal(await value('long').textContent(), '1');

        await editor.evaluate(body => {
            body.dispatchEvent(new CompositionEvent('compositionstart', {bubbles: true}));
            body.textContent = 'Незавершённый ввод';
        });
        await page.waitForTimeout(750);
        assert.equal(await value('words').textContent(), '28');
        await editor.evaluate(body => body.dispatchEvent(new CompositionEvent('compositionend', {bubbles: true})));
        await value('words').filter({hasText: /^2$/u}).waitFor();

        await page.setViewportSize({width: 860, height: 900});
        await panel.waitFor({state: 'hidden'});
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth), false);
        await editor.fill('Текст менялся в узком окне.');
        await page.setViewportSize({width: 1440, height: 900});
        await value('words').filter({hasText: /^5$/u}).waitFor();

        await card.locator('.post-edit-cancel').click();
        await page.locator('[data-discard-changes-action="discard"]').click();
        await panel.waitFor({state: 'detached'});

        let heldWorker;
        await page.route(workerPath, route => { heldWorker = route; });
        await page.goto(origin + '/readability-layout.html');
        const workerRequested = page.waitForRequest(workerPath);
        await page.locator('.post-edit-start').press('Enter');
        await panel.waitFor({state: 'visible'});
        const edited = page.locator('.post-card.is-editing [data-post-inplace-body]');
        const editedBounds = await edited.boundingBox();
        const sideBounds = await panel.boundingBox();
        assert.equal(editedBounds.width, 1000);
        assert.ok(sideBounds.width >= 180 && sideBounds.width < 226, 'The normal 1000px layout uses a narrower panel');
        assert.ok(sideBounds.x >= editedBounds.x + editedBounds.width + 15);
        assert.ok(sideBounds.x + sideBounds.width <= await page.evaluate(() => document.documentElement.clientWidth - 16));
        await workerRequested;
        await value('words').evaluate(field => {
            window.readabilityWordCounts = [];
            new MutationObserver(() => window.readabilityWordCounts.push(field.textContent))
                .observe(field, {childList: true, characterData: true, subtree: true});
        });
        // While the first analysis is waiting for its worker, replace the
        // text. The response for the original article must never be painted.
        await edited.fill('Это редчайшее слово сверхпреобразователь.');
        await heldWorker.continue();
        await page.unroute(workerPath);
        await value('rare').filter({hasText: /^2$/u}).waitFor();
        assert.deepEqual(await page.evaluate(() => window.readabilityWordCounts), ['4']);
        assert.deepEqual(errors, []);
        console.log(browser.browserType().name() + ': readability loads in the background and follows prose, composition and available width');
    } finally {
        await page.close();
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit].filter(engine => !process.env.EDITOR_TEST_BROWSER
            || engine.name() === process.env.EDITOR_TEST_BROWSER)) {
            const browser = await engine.launch();
            try { await runReadabilityRegressions(browser, 'http://127.0.0.1:' + server.address().port); }
            finally { await browser.close(); }
        }
    } finally {
        await new Promise(resolve => server.close(resolve));
    }
}
