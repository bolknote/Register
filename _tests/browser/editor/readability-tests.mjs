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
        const badge = panel.locator('.post-editor-readability-badge');
        const popover = panel.locator('.post-editor-readability-popover');
        const level = popover.locator('.post-editor-readability-level');
        const scale = popover.locator('.post-editor-readability-scale');
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
        assert.equal(await popover.isVisible(), false, 'Only the score is visible when writing');
        const bounds = await badge.boundingBox();
        assert.ok(bounds.x >= bodyBeforePanel.x + bodyBeforePanel.width + 15);
        assert.ok(bounds.x + bounds.width <= 1440 - 16);
        assert.equal(await editor.evaluate(body => body.getBoundingClientRect().width), bodyBeforePanel.width);
        await badge.hover();
        await popover.waitFor();
        assert.equal(await badge.getAttribute('aria-expanded'), 'true');
        assert.equal(await level.textContent(), 'Легко читать');
        assert.equal(await value('sentence').textContent(), '2,5');
        await popover.locator('summary').hover();
        assert.equal(await editor.evaluate(body => document.activeElement === body), true, 'Hover keeps the writing focus');
        await editor.hover();
        await popover.waitFor({state: 'hidden'});
        await badge.press('Enter');
        await editor.hover();
        assert.equal(await popover.isVisible(), true, 'Keyboard activation keeps the panel open');
        if (dictionaryRequested) {
            await dictionaryRequested;
            assert.equal(await value('rare').textContent(), '—');
            await heldDictionary.continue();
            await page.unroute(wordlistPath);
        }
        await value('rare').filter({hasText: /^\d+$/u}).waitFor();

        await popover.locator('summary').press('Enter');
        assert.equal(await value('words').textContent(), '5');
        assert.equal(await popover.locator('a[href*="wordfreq"]').count(), 0);
        await popover.locator('summary').press('Escape');
        await popover.waitFor({state: 'hidden'});
        assert.equal(await badge.evaluate(button => document.activeElement === button), true);
        assert.equal(await editor.getAttribute('contenteditable'), 'true', 'Escape only dismisses the panel');
        const longSentence = 'Я '.repeat(24) + 'думаю.';
        await editor.evaluate((body, sentence) => {
            body.innerHTML = '<p>' + sentence.replace('Я Я', 'Я\nЯ') + '</p><pre>Этот исходный код не входит в прозу.</pre>'
                + '<p hidden>Этот абзац скрыт.</p><p>Дети лепят снеговика.</p>';
        }, longSentence);
        assert.equal(await value('words').textContent(), '5', 'Values remain stable until the pause');
        await value('words').filter({hasText: /^28$/u}).waitFor({state: 'attached'});
        assert.equal(await value('long').textContent(), '1');

        await editor.evaluate(body => {
            body.dispatchEvent(new CompositionEvent('compositionstart', {bubbles: true}));
            body.textContent = 'Незавершённый ввод';
        });
        await page.waitForTimeout(750);
        assert.equal(await value('words').textContent(), '28');
        await editor.evaluate(body => body.dispatchEvent(new CompositionEvent('compositionend', {bubbles: true})));
        await value('words').filter({hasText: /^2$/u}).waitFor({state: 'attached'});

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
        const sideBounds = await badge.boundingBox();
        assert.equal(editedBounds.width, 1000);
        assert.ok(sideBounds.width >= 44 && sideBounds.width <= 50);
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
        await value('rare').filter({hasText: /^2$/u}).waitFor({state: 'attached'});
        assert.deepEqual(await page.evaluate(() => window.readabilityWordCounts), ['4']);

        await badge.click();
        const popupBounds = await popover.boundingBox();
        assert.ok(popupBounds.width >= 180 && popupBounds.width < 226 && popupBounds.x >= editedBounds.x + editedBounds.width,
            'The panel fits entirely in the right gutter when there is room');
        assert.equal(await badge.textContent(), '-24');
        assert.equal(await level.textContent(), 'Очень трудно читать');
        assert.equal(await scale.evaluate(node => node.style.getPropertyValue('--readability-score')), '0%');
        await edited.fill('В лесу и в поле.');
        await badge.filter({hasText: /^140$/u}).waitFor();
        assert.equal(await scale.evaluate(node => node.style.getPropertyValue('--readability-score')), '100%');
        assert.equal(await level.textContent(), 'Легко читать');
        await edited.fill('An ordinary English sentence.');
        await badge.filter({hasText: /^—$/u}).waitFor();
        assert.equal(await scale.isVisible(), false);
        assert.equal(await level.textContent(), 'Оценка недоступна');

        // Make a generic long note and a following page section to exercise
        // the upper and lower bounds of the sticky badge on the real layout.
        await edited.evaluate(body => {
            const paragraph = document.createElement('p');
            paragraph.textContent = 'Сегодня я включил радио и записал, что услышал. '.repeat(12);
            body.replaceChildren(...Array.from({length: 16}, () => paragraph.cloneNode(true)));
            document.querySelector('#footer').style.minHeight = '1000px';
        });
        await value('words').filter({hasText: /^1\s*536$/u}).waitFor({state: 'attached'});
        const scrollTogether = await badge.evaluate(async button => {
            const body = document.querySelector('[data-post-inplace-body][contenteditable="true"]');
            const before = body.getBoundingClientRect();
            scrollBy(0, before.top + 250);
            await new Promise(requestAnimationFrame);
            const inside = button.getBoundingClientRect();
            scrollBy(0, body.getBoundingClientRect().bottom + 40);
            await new Promise(requestAnimationFrame);
            return {inside: inside.top, after: button.getBoundingClientRect().bottom};
        });
        assert.ok(scrollTogether.inside >= 10 && scrollTogether.inside <= 16 && scrollTogether.after < 0, JSON.stringify(scrollTogether));
        await page.evaluate(() => scrollTo(0, 0));
        await page.setViewportSize({width: 1200, height: 480});
        await badge.waitFor({state: 'visible'});
        const narrow = await popover.locator('.post-editor-readability-content').evaluate(async content => {
            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            const bounds = content.getBoundingClientRect();
            const badge = content.parentElement.previousElementSibling.getBoundingClientRect();
            return {width: bounds.width, left: bounds.left, right: bounds.right, bottom: bounds.bottom,
                top: bounds.top, badgeLeft: badge.left, viewportWidth: document.documentElement.clientWidth,
                viewportHeight: document.documentElement.clientHeight};
        });
        assert.ok(narrow.width >= 180 && narrow.left < narrow.badgeLeft && narrow.right <= narrow.viewportWidth - 15,
            `The panel opens toward the text in a narrower gutter: ${JSON.stringify(narrow)}`);
        assert.ok(narrow.top >= 12 && narrow.bottom <= narrow.viewportHeight - 12, JSON.stringify(narrow));
        await edited.locator('p').first().click({position: {x: 10, y: 10}});
        await popover.waitFor({state: 'hidden'});

        await badge.press('Enter');
        await page.setViewportSize({width: 540, height: 900});
        await panel.waitFor({state: 'hidden'});
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth), false);
        await edited.fill('Текст менялся в узком окне.');
        await page.setViewportSize({width: 1440, height: 900});
        await value('words').filter({hasText: /^5$/u}).waitFor({state: 'attached'});
        assert.equal(await popover.isVisible(), false, 'Resizing out of the gutter dismisses the panel');
        assert.deepEqual(errors, []);
        console.log(browser.browserType().name() + ': readability follows scrolling, handles hover and keyboard, fits both gutters, and loads in the background');
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
