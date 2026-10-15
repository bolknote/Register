import assert from 'node:assert/strict';
import {chromium, firefox, webkit} from 'playwright';
import {pathToFileURL} from 'node:url';
import {createFixtureServer} from './server.mjs';
import {holdRequests} from './save-tests.mjs';

export async function runInitialLayoutRegressions(browser, origin) {
    for (const width of [390, 1280, 2560]) {
        const context = await browser.newContext({viewport: {width, height: 900}, colorScheme: 'dark'});
        try {
            for (const role of ['moderator', 'author', 'guest']) {
                await assertInitialLayout(context, origin, role, width);
            }
        } finally { await context.close(); }
    }
    console.log(`${browser.browserType().name()}: first paint and reload keep the text/tool origin for authors, moderators and guests`);
}

async function assertInitialLayout(context, origin, role, width) {
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    const held = holdRequests(page, '**/initial-layout-gate.js');
    await held.installed;
    await page.addInitScript(() => {
        window.initialLayoutFrames = [];
        function record() {
            const header = document.querySelector('#header');
            const prose = document.querySelector('#responsive-prose');
            const plus = document.querySelector('.post-create-start');
            if (prose && header) {
                window.initialLayoutFrames.push({
                    state: document.readyState,
                    header: header.getBoundingClientRect().left,
                    content: prose.getBoundingClientRect().left,
                    plus: plus?.getBoundingClientRect().left ?? null,
                    textWidth: prose.getBoundingClientRect().width,
                    font: parseFloat(getComputedStyle(prose).fontSize),
                });
            }
            requestAnimationFrame(record);
        }
        requestAnimationFrame(record);
    });
    const path = role === 'author' ? '/initial-layout.html' : `/initial-layout-${role}.html`;
    try {
        for (const action of ['open', 'reload']) {
            let gate;
            const navigation = action === 'open' ? page.goto(origin + path) : page.reload();
            navigation.catch(() => {});
            try {
                gate = await held.next();
                await page.waitForFunction(() => window.initialLayoutFrames.length >= 3);
                const during = await page.evaluate(() => window.initialLayoutFrames.slice());
                assert.ok(during.every(frame => frame.state === 'loading'));
                // A moderator's first controls are below the article. They have
                // not arrived yet; a completed-page screenshot cannot catch this.
                if (role === 'moderator') assert.equal(await page.locator('.comment-moderation').count(), 0);
                await gate.fulfill({contentType: 'text/javascript', body: ''});
                gate = null;
                await navigation;
                await page.waitForFunction(() => window.initialLayoutFrames.some(frame => frame.state === 'complete'));
                const frames = await page.evaluate(() => window.initialLayoutFrames.slice());
                const final = frames.at(-1);
                if (role === 'moderator') assert.equal(await page.locator('.comment-moderation').count(), 1);
                for (const frame of frames) {
                    for (const key of ['header', 'content', 'plus', 'textWidth', 'font']) {
                        if (frame[key] === null) continue;
                        assert.ok(Math.abs(frame[key] - final[key]) <= 0.5,
                            `${role}, ${width}px, ${action}: ${key} must not jump between initial paint and load: ${JSON.stringify({frame, final})}`);
                    }
                    if (frame.plus !== null) assert.ok(frame.plus >= (width > 760 ? 12 : 0),
                        'The author control stays safely inside the viewport from the first frame');
                    assert.equal(frame.font, width <= 720 ? 18 : 20, 'Existing typography is unchanged');
                    assert.ok(frame.textWidth <= 1000.5, 'The established column width is unchanged');
                }
            } finally {
                if (gate) await gate.fulfill({contentType: 'text/javascript', body: ''});
            }
        }
    } finally { await page.close(); }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const engines = [chromium, firefox, webkit].filter(engine => !process.env.EDITOR_TEST_BROWSER
        || engine.name() === process.env.EDITOR_TEST_BROWSER);
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of engines) {
            const browser = await engine.launch();
            try { await runInitialLayoutRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
