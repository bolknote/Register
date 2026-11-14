import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';

const panelPath = '**/editor/readability/panel.js';
const wordlistPath = '**/editor/readability/ru-common.js';

export async function runReadabilityRegressions(browser, origin) {
    const page = await browser.newPage({viewport: {width: 1440, height: 900}, reducedMotion: 'reduce'});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.setDefaultTimeout(10000);
    try {
        let panelRequested;
        let dictionaryRequested;
        const waitingPanel = new Promise(resolve => { panelRequested = resolve; });
        const waitingDictionary = new Promise(resolve => { dictionaryRequested = resolve; });
        await page.route(panelPath, route => { panelRequested(route); });
        await page.route(wordlistPath, route => { dictionaryRequested(route); });
        await page.goto(origin + '/readability.html');
        const panel = page.locator('.post-editor-readability');
        const card = page.locator('.post-card[data-post-creating]');
        const editor = card.locator('[data-post-inplace-body]');
        const value = key => panel.locator('[data-readability-value="' + key + '"]');
        await page.getByRole('button', {name: 'New post', exact: true}).click();
        const heldPanel = await waitingPanel;
        assert.equal(await card.locator('[data-post-inplace-title]').evaluate(title => document.activeElement === title), true);
        assert.equal(await editor.getAttribute('contenteditable'), 'true');
        await editor.fill('Идёт снег. Дети лепят снеговика.');
        const bodyBeforePanel = await editor.boundingBox();
        assert.equal(await panel.count(), 0, 'An unfinished module download must not block editing');
        await heldPanel.continue();
        await panel.waitFor({state: 'visible'});
        const heldDictionary = await waitingDictionary;
        assert.equal(await value('flesch').textContent(), '71');
        assert.equal(await value('sentence').textContent(), '2,5');
        assert.equal(await value('rare').textContent(), '—');
        const bounds = await panel.boundingBox();
        assert.ok(bounds.x >= bodyBeforePanel.x + bodyBeforePanel.width + 20);
        assert.ok(bounds.x + bounds.width <= 1440 - 16);
        assert.equal(await editor.evaluate(body => body.getBoundingClientRect().width), bodyBeforePanel.width);
        assert.equal(await editor.evaluate(body => document.activeElement === body), true);
        await heldDictionary.continue();
        await value('rare').filter({hasText: /^\d+$/u}).waitFor();

        await panel.locator('summary').click();
        assert.equal(await value('words').textContent(), '5');
        const longSentence = 'Я '.repeat(24) + 'думаю.';
        await editor.evaluate((body, sentence) => {
            body.innerHTML = '<p>' + sentence + '</p><pre>Этот исходный код не входит в прозу.</pre>'
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
        await page.locator('.post-edit-start').click();
        await panel.waitFor({state: 'visible'});
        const edited = page.locator('.post-card.is-editing [data-post-inplace-body]');
        await edited.fill('Это редчайшее слово сверхпреобразователь.');
        await value('rare').filter({hasText: /^2$/u}).waitFor();
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
