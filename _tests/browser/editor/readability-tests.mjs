import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';

const panelPath = '**/editor/readability/panel.js';
const wordlistPath = '**/editor/readability/ru-common.js';
const workerPath = '**/editor/readability/worker.js';

async function assertGaugeAlignment(card, badge) {
    const heading = await card.locator('.post.head').boundingBox();
    const save = await card.locator('.post-edit-save').boundingBox();
    const gauge = await badge.boundingBox();
    assert.ok(gauge.width >= 44 && gauge.width <= 50);
    assert.ok(gauge.x >= 0 && gauge.x + gauge.width <= heading.x);
    assert.ok(Math.abs(gauge.y - heading.y) <= 1, 'The gauge starts at the title');
    assert.ok(Math.abs(gauge.x + gauge.width / 2 - save.x - save.width / 2) <= 1,
        'The gauge shares the toolbar centerline');
}

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
        const score = badge.locator('[data-readability-value="flesch"]');
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
        await score.filter({hasText: /^71$/u}).waitFor();
        assert.equal(await badge.getAttribute('aria-label'), 'Индекс Флеша 71. Легко читать');
        assert.equal(await popover.isVisible(), false, 'Only the score is visible when writing');
        await assertGaugeAlignment(card, badge);
        assert.equal(await editor.evaluate(body => body.getBoundingClientRect().width), bodyBeforePanel.width);
        await badge.hover();
        await popover.waitFor();
        assert.equal(await badge.getAttribute('aria-expanded'), 'true');
        assert.equal(await level.textContent(), 'Легко читать');
        assert.equal(await popover.locator('[data-readability-value="flesch"]').textContent(), '71');
        assert.equal(await value('sentence').isVisible(), false);
        const badgeBounds = await badge.boundingBox();
        await page.mouse.move(badgeBounds.x + badgeBounds.width + 12, badgeBounds.y + badgeBounds.height / 2, {steps: 5});
        assert.equal(await popover.isVisible(), true, 'The pointer can cross from the gauge to its panel');
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
        await value('rare').filter({hasText: /^\d+$/u}).waitFor({state: 'attached'});

        await popover.locator('summary').press('Enter');
        await value('sentence').filter({hasText: /^2,5$/u}).waitFor();
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
        const editedCard = page.locator('.post-card.is-editing');
        const edited = editedCard.locator('[data-post-inplace-body]');
        const editedBounds = await edited.boundingBox();
        assert.equal(editedBounds.width, 1000);
        await assertGaugeAlignment(editedCard, badge);
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
        const sideBounds = await badge.boundingBox();
        const content = popover.locator('.post-editor-readability-content');
        const popupBounds = await content.boundingBox();
        assert.ok(popupBounds.width >= 180 && popupBounds.width <= 240 && popupBounds.x >= sideBounds.x + sideBounds.width + 5,
            'The popover opens to the right of the gauge');
        assert.equal(await score.textContent(), '-24');
        assert.equal(await level.textContent(), 'Очень трудно читать');
        const scorePosition = () => panel.evaluate(node => [node.style.getPropertyValue('--readability-score'),
            node.style.getPropertyValue('--readability-angle')]);
        assert.deepEqual(await scorePosition(), ['0%', '-90deg']);
        await edited.fill('В лесу и в поле.');
        await score.filter({hasText: /^140$/u}).waitFor();
        assert.deepEqual(await scorePosition(), ['100%', '90deg']);
        assert.equal(await level.textContent(), 'Легко читать');
        await edited.fill('An ordinary English sentence.');
        await score.filter({hasText: /^—$/u}).waitFor();
        assert.equal(await scale.isVisible(), false);
        assert.equal(await panel.getAttribute('data-score-available'), null);
        assert.equal(await level.textContent(), 'Оценка недоступна');

        await page.setViewportSize({width: 1024, height: 640});
        await edited.fill('Идёт снег. Дети лепят снеговика.');
        await score.filter({hasText: /^71$/u}).waitFor();
        // The short post's tag editor can sit under the popover.
        const metrics = popover.locator('summary');
        await metrics.click();
        await value('sentence').waitFor();
        await metrics.click();
        await page.setViewportSize({width: 1440, height: 900});

        // Make a generic long note and a following page section to exercise
        // the upper and lower bounds of the sticky badge on the real layout.
        await edited.evaluate(body => {
            const paragraph = document.createElement('p');
            paragraph.textContent = 'Сегодня я включил радио и записал, что услышал. '.repeat(12);
            body.replaceChildren(...Array.from({length: 16}, () => paragraph.cloneNode(true)));
            document.querySelector('#footer').style.minHeight = '1000px';
        });
        await value('words').filter({hasText: /^1\s*536$/u}).waitFor({state: 'attached'});
        await popover.waitFor();
        const scrollTogether = await editedCard.evaluate(async article => {
            const button = article.querySelector('.post-editor-readability-badge');
            const toolbar = article.querySelector('.post-inplace-tools');
            const gap = () => toolbar.getBoundingClientRect().top - button.getBoundingClientRect().bottom;
            scrollBy(0, article.getBoundingClientRect().top + 250);
            await new Promise(requestAnimationFrame);
            const inside = button.getBoundingClientRect();
            const insideGap = gap();
            scrollBy(0, article.getBoundingClientRect().bottom - 100);
            await new Promise(requestAnimationFrame);
            const bottomGap = gap();
            scrollBy(0, article.getBoundingClientRect().bottom + 40);
            await new Promise(requestAnimationFrame);
            return {inside: inside.top, insideGap, bottomGap, after: button.getBoundingClientRect().bottom};
        });
        assert.ok(scrollTogether.inside >= 10 && scrollTogether.inside <= 16 && scrollTogether.insideGap >= 6
            && scrollTogether.bottomGap >= 6 && scrollTogether.after < 0, JSON.stringify(scrollTogether));
        await popover.waitFor({state: 'hidden'});
        await page.setViewportSize({width: 881, height: 360});
        await page.evaluate(() => scrollTo(0, 0));
        await badge.press('Enter');
        await popover.locator('summary').press('Enter');
        await page.evaluate(() => scrollTo(0, 0));
        const contentHandle = await content.elementHandle();
        const popupFits = (beforeShift = Infinity) => page.waitForFunction(({panel, beforeShift}) => {
            const bounds = panel.getBoundingClientRect();
            const badge = panel.parentElement.previousElementSibling.getBoundingClientRect();
            return bounds.width >= 180 && bounds.left >= badge.right + 5 && bounds.right <= document.documentElement.clientWidth - 15
                && bounds.top >= 12 && bounds.bottom <= innerHeight - 12 && badge.top < beforeShift - 20;
        }, {panel: contentHandle, beforeShift}, {timeout: 3500});
        await popupFits();

        // A late layout change can move the entire post without changing its size.
        const beforeShift = (await badge.boundingBox()).y;
        await page.locator('#header').evaluate(header => { header.style.marginTop = '-28px'; });
        await popupFits(beforeShift);
        await edited.locator('p').first().click({position: {x: 320, y: 10}});
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
        console.log(browser.browserType().name() + ': readability aligns with the title and tools, follows scrolling, fits the viewport, and loads in the background');
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
