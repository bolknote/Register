import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';

const baseline = {like: 1, love: 1, haha: 2, wow: 0, sad: 0, angry: 0};
const chip = type => `.register-reaction-chip[data-reaction="${type}"]`;
const choice = type => `.register-reaction-choice[data-picker-reaction="${type}"]`;
const picker = '.register-reaction-picker';
const tabKey = browser => browser.browserType().name() === 'webkit' ? 'Alt+Tab' : 'Tab';

function payload(counts = baseline, selected = null, extra = {'🔥': 2}) {
    return {counts: {...counts}, selected, extra: {...extra}};
}

async function assertLikeControl(page, label) {
    const state = await page.locator('[data-register-reactions]').evaluate(root => {
        const controls = [...root.querySelectorAll('[data-reaction]')];
        const visible = controls.filter(button => !button.hidden);
        const like = root.querySelector('[data-reaction="like"]');
        const others = controls.filter(button => button !== like);
        const likeRect = like.getBoundingClientRect();
        return {
            primary: controls.filter(button => button.classList.contains('register-reaction-primary'))
                .map(button => button.dataset.reaction),
            firstDom: controls[0].dataset.reaction,
            likeVisible: !like.hidden && getComputedStyle(like).display !== 'none',
            likeOrder: Number(getComputedStyle(like).order),
            othersOrder: others.every(button => Number(getComputedStyle(button).order) >= 0),
            firstVisual: visible.every(button => button === like || button.getBoundingClientRect().left > likeRect.left),
            hasPopup: like.getAttribute('aria-haspopup'),
            hasControls: like.getAttribute('aria-controls') === root.querySelector('.register-reaction-picker').id,
            otherPopup: others.some(button => ['aria-haspopup', 'aria-controls', 'aria-expanded']
                .some(attribute => button.hasAttribute(attribute))),
        };
    });
    assert.deepEqual(state, {
        primary: ['like'], firstDom: 'like', likeVisible: true, likeOrder: -1,
        othersOrder: true, firstVisual: true, hasPopup: 'menu', hasControls: true, otherPopup: false,
    }, label);
}

async function assertState(page, expected) {
    await page.waitForFunction(expected => {
        const root = document.querySelector('[data-register-reactions]');
        const selected = root.querySelector('[data-reaction][aria-pressed="true"]')?.dataset.reaction || null;
        return selected === expected.selected
            && [...root.querySelectorAll('[data-reaction]')].every(button =>
                Number(button.dataset.count) === (expected.counts[button.dataset.reaction] ?? expected.extra[button.dataset.reaction] ?? 0));
    }, expected);
    for (const [type, count] of Object.entries({...expected.counts, ...expected.extra})) {
        assert.equal(await page.locator(chip(type)).getAttribute('data-count'), String(count));
        assert.equal(await page.locator(chip(type)).getAttribute('aria-pressed'), String(expected.selected === type));
        assert.equal(await page.locator(choice(type)).getAttribute('aria-checked'), String(expected.selected === type));
    }
    await assertLikeControl(page, 'Counts and current selection cannot change the palette control');
}

async function setup(browser, origin, {zero = false, selected = null, touch = false, stale = false} = {}) {
    const context = await browser.newContext({
        viewport: touch ? {width: 390, height: 844} : {width: 1000, height: 700},
        hasTouch: touch,
        colorScheme: 'dark',
    });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    const posts = [];
    const postWaiters = new Map();
    const waitForPost = index => posts[index] ? Promise.resolve(posts[index])
        : new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error(`Reaction POST ${index + 1} was not sent within 10 seconds`)), 10000);
            postWaiters.set(index, post => { clearTimeout(timer); resolve(post); });
        });
    let releaseHydration;
    const hydrationGate = new Promise(resolve => { releaseHydration = resolve; });
    const hydrated = payload({...baseline, like: zero ? 0 : 1, haha: 3}, selected);
    await context.route('**/_reactions?content=*', async route => {
        await hydrationGate;
        await route.fulfill({json: {success: true, states: {'post:1': hydrated}}});
    });
    await context.route('**/_reactions/post/1', async route => {
        let resolve;
        const gate = new Promise(done => { resolve = done; });
        posts.push({reaction: route.request().postDataJSON().reaction, resolve});
        postWaiters.get(posts.length - 1)?.(posts.at(-1));
        const result = await gate;
        await route.fulfill(result);
    });
    if (stale) {
        // A cached pre-fix server fragment is a valid rolling-update input.
        // It must be repaired by the real public enhancement API on page load.
        await context.route('**/reactions.html?zero=1', async route => {
            const response = await route.fetch();
            const html = (await response.text())
                .replace(/<button\b[^>]*data-reaction="like"[^>]*>/u, tag => tag
                    .replace('register-reaction-chip register-reaction-primary is-visible', 'register-reaction-chip')
                    .replace(/\saria-(?:haspopup|expanded|controls)="[^"]*"/gu, '')
                    .replace('aria-pressed="false"', 'aria-pressed="false" hidden'))
                .replace('class="register-reaction-chip is-visible" type="button" data-reaction="haha"',
                    'class="register-reaction-chip register-reaction-primary is-visible" aria-haspopup="menu" aria-expanded="false" aria-controls="register-reaction-picker-post-1" type="button" data-reaction="haha"');
            await route.fulfill({response, body: html});
        });
    }
    await page.goto(origin + '/reactions.html' + (zero ? '?zero=1' : ''));
    await page.waitForFunction(() => window.RegisterReactions);
    await assertLikeControl(page, 'Initial enhancement keeps the ordinary PHP-rendered like control first');
    releaseHydration();
    await assertState(page, hydrated);
    return {context, page, errors, posts, waitForPost, hydrated};
}

export async function runReactionRegressions(browser, origin) {
    // CSS layout and PHP output must be correct even without client hydration.
    for (const zero of [false, true]) {
        const context = await browser.newContext({javaScriptEnabled: false, viewport: {width: 1000, height: 700}});
        const page = await context.newPage();
        try {
            await page.goto(origin + '/reactions.html' + (zero ? '?zero=1' : ''));
            await assertLikeControl(page, 'Unenhanced server-rendered control is always like, even with no likes');
        } finally { await context.close(); }
    }
    console.log('reactions: real PHP imported totals render one first, visible like control, including zero likes');

    for (const state of [{}, {zero: true, selected: 'love'}, {zero: true, selected: '🔥'}, {zero: true, stale: true}]) {
        const {context, page, posts, errors} = await setup(browser, origin, state);
        try {
            await page.locator(chip('haha')).hover();
            await page.waitForTimeout(300);
            assert.equal(await page.locator(picker).isVisible(), false, 'A reaction chip is not the palette control');
            await page.locator(chip('like')).hover();
            await page.locator(picker).waitFor();
            assert.equal(await page.locator(chip('like')).getAttribute('aria-expanded'), 'true');
            assert.equal(await page.locator(choice('🔥')).isVisible(), true);
            // On macOS Safari/WebKit, Option-Tab includes buttons even when the
            // OS's full keyboard access preference excludes them from Tab.
            await page.keyboard.press(tabKey(browser));
            await page.keyboard.press('Escape');
            assert.equal(await page.locator(picker).isVisible(), false);
            assert.equal(await page.locator(chip('like')).evaluate(button => document.activeElement === button), true);
            assert.equal(posts.length, 0, 'Hover and Escape cannot save a reaction');
            assert.deepEqual(errors, []);
        } finally { await context.close(); }
    }
    console.log('reactions: hydration, selected built-in/imported emoji and old cached markup keep hover on like only');

    {
        const {context, page, posts, waitForPost, errors} = await setup(browser, origin);
        try {
            await page.clock.install({time: new Date('2026-01-01T00:00:00Z')});
            await page.clock.pauseAt(new Date('2026-01-01T00:00:01Z'));
            // Force skips animation/stability waits, not native pointer input.
            // No virtual time passes until both real pointer events complete.
            await page.locator(chip('like')).hover({force: true});
            await page.locator(chip('haha')).hover({force: true});
            await page.clock.runFor(300);
            assert.equal(await page.locator(picker).isVisible(), false, 'Leaving like before its hover delay cancels opening');
            await page.locator(chip('like')).hover({force: true});
            await page.locator(chip('like')).click({force: true});
            const post = await waitForPost(0);
            // Finish before the 220 ms hover delay; holding the request beyond
            // that delay would hide the stale timer bug behind the busy guard.
            post.resolve({json: {success: true, ...payload({...baseline, like: 2, haha: 3}, 'like')}});
            // Network microtasks settle independently of the paused clock.
            // Poll from Node so a broken request cannot freeze the test itself.
            const deadline = Date.now() + 10000;
            while (await page.locator('[data-register-reactions]').getAttribute('aria-busy') !== 'false') {
                assert.ok(Date.now() < deadline, 'The local reaction response settles within 10 seconds');
                await new Promise(resolve => setTimeout(resolve, 10));
            }
            await assertLikeControl(page, 'Optimistic like keeps the same control');
            await page.clock.runFor(300);
            assert.equal(await page.locator(picker).isVisible(), false, 'Closing a pending palette open cancels the timer');
            assert.equal(posts.length, 1);
            assert.deepEqual(errors, []);
        } finally { await context.close(); }
    }
    console.log('reactions: leaving like early and selecting while its hover timer is pending do not reopen the palette');

    {
        const {context, page, posts, waitForPost, errors, hydrated} = await setup(browser, origin, {zero: true});
        try {
            await page.keyboard.press(tabKey(browser));
            assert.equal(await page.locator(chip('like')).evaluate(button => document.activeElement === button), true);
            await page.keyboard.press('ArrowDown');
            assert.equal(await page.locator(choice('like')).evaluate(button => document.activeElement === button), true);
            await page.keyboard.press('ArrowRight');
            assert.equal(await page.locator(choice('love')).evaluate(button => document.activeElement === button), true);
            await page.keyboard.press('End');
            assert.equal(await page.locator(choice('🔥')).evaluate(button => document.activeElement === button), true);
            await page.keyboard.press('Home');
            await page.keyboard.press('Escape');
            await page.keyboard.press('ArrowUp');
            assert.equal(await page.locator(choice('like')).evaluate(button => document.activeElement === button), true);
            await page.keyboard.press('ArrowRight');
            assert.equal(posts.length, 0, 'Keyboard navigation alone never mutates a reaction');
            await page.keyboard.press('Enter');
            await waitForPost(0);
            await page.waitForFunction(() => document.querySelector('[data-register-reactions]').classList.contains('is-busy'));
            assert.equal(posts[0].reaction, 'love');
            const love = payload({...hydrated.counts, love: 2}, 'love');
            await assertState(page, love);
            posts[0].resolve({json: {success: true, ...love}});
            await page.waitForFunction(() => document.querySelector('[data-register-reactions]').getAttribute('aria-busy') === 'false');
            await assertState(page, love);
            await page.locator(chip('love')).focus();
            await page.keyboard.press('ArrowDown');
            assert.equal(await page.locator(picker).isVisible(), false, 'A non-primary reaction does not open the palette with ArrowDown');

            // Clicking the selected chip removes exactly that reaction; it does
            // not move or remove the palette control when like remains zero.
            await page.locator(chip('love')).click();
            await waitForPost(1);
            await page.waitForFunction(() => document.querySelector('[data-register-reactions]').classList.contains('is-busy'));
            assert.equal(posts[1].reaction, 'love');
            await assertState(page, hydrated);
            posts[1].resolve({json: {success: true, ...hydrated}});
            await page.waitForFunction(() => document.querySelector('[data-register-reactions]').getAttribute('aria-busy') === 'false');

            await page.locator(chip('🔥')).click();
            await waitForPost(2);
            await page.waitForFunction(() => document.querySelector('[data-register-reactions]').classList.contains('is-busy'));
            await assertState(page, payload(hydrated.counts, '🔥', {'🔥': 3}));
            assert.equal(posts[2].reaction, '🔥');
            posts[2].resolve({status: 500, json: {success: false}});
            await page.waitForFunction(() => document.querySelector('[data-register-reactions]').classList.contains('is-error'));
            await assertState(page, hydrated);
            assert.equal(await page.locator('.register-reaction-status').textContent(), 'reaction.error');
            assert.deepEqual(errors, []);
        } finally { await context.close(); }
    }
    console.log('reactions: real keyboard choice, optimistic select/remove and failed imported selection preserve like, counts and selection');

    {
        const {context, page, posts, errors} = await setup(browser, origin, {zero: true, selected: '🔥', touch: true});
        try {
            const button = page.locator(chip('like'));
            const rect = await button.boundingBox();
            const point = {x: rect.x + rect.width / 2, y: rect.y + rect.height / 2};
            if (browser.browserType().name() === 'chromium') {
                // Chromium's public CDP input API supports a genuine held touch.
                const session = await context.newCDPSession(page);
                await session.send('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [point]});
                await page.locator(picker).waitFor();
                await session.send('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []});
                await session.detach();
            } else {
                // Playwright exposes tap, not held-touch input, for these engines.
                // Exercise the touch timer through its DOM pointer entry point,
                // then the actual native touch/click sequence through touchscreen.
                await button.dispatchEvent('pointerdown', {pointerType: 'touch', pointerId: 1, isPrimary: true});
                await page.locator(picker).waitFor();
                await button.dispatchEvent('pointerup', {pointerType: 'touch', pointerId: 1, isPrimary: true});
                await page.touchscreen.tap(point.x, point.y);
            }
            await page.waitForTimeout(100);
            assert.equal(posts.length, 0, 'Opening and releasing a held touch must not toggle like');
            assert.equal(await page.locator(picker).isVisible(), true);
            await assertLikeControl(page, 'Touch long-press uses the same stable like control');
            await page.touchscreen.tap(8, 8);
            assert.equal(await page.locator(picker).isVisible(), false);
            assert.equal(posts.length, 0, 'Dismissal cannot mutate a reaction');
            assert.deepEqual(errors, []);
        } finally { await context.close(); }
    }
    console.log('reactions: touch long-press opens on like and release/dismissal does not create a reaction');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const engines = [chromium, firefox, webkit];
    const selected = process.env.EDITOR_TEST_BROWSER;
    if (selected && !engines.some(engine => engine.name() === selected)) throw new Error(`Unknown EDITOR_TEST_BROWSER: ${selected}`);
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of engines.filter(engine => !selected || engine.name() === selected)) {
            const browser = await engine.launch();
            try { await runReactionRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally {
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
}
