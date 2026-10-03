import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';

const resources = execFileSync(process.env.PHP_BIN || 'php',
    [fileURLToPath(new URL('./context-menu-fixture.php', import.meta.url))], {encoding: 'utf8'});

export async function runContextMenuRegressions(browser, origin) {
    for (const {touch, width} of [
        {touch: false, width: 1360}, {touch: false, width: 390},
        {touch: true, width: 390}, {touch: true, width: 1360},
    ]) {
        const context = await browser.newContext({
            viewport: {width, height: 900},
            hasTouch: touch,
        });
        const errors = [];
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        page.on('pageerror', error => errors.push(String(error)));
        await context.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        try {
            await page.goto(origin + '/recovery.html');
            await page.evaluate(html => { document.getElementById('post-editor-resources').outerHTML = html; }, resources);
            const tools = page.getByRole('button', {name: 'Editor tools', exact: true, includeHidden: true});
            assert.equal(await tools.isVisible(), false);
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            assert.equal(await tools.isVisible(), touch,
                `${width}px: the extra menu control is for touch input, not mouse input or narrow windows`);
            const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
            await body.click();
            await body.press('End');
            const activate = async locator => touch ? locator.tap() : locator.click();
            if (touch) await tools.tap();
            else await body.click({button: 'right'});
            const menu = page.locator('.post-editor-context-menu');
            await menu.waitFor();
            assert.equal(await tools.getAttribute('aria-expanded'), 'true');
            const initial = await menu.evaluate(element => {
                const rect = element.getBoundingClientRect();
                return {width: rect.width, left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom,
                    scrollHeight: element.scrollHeight, clientHeight: element.clientHeight, viewportWidth: innerWidth, viewportHeight: innerHeight};
            });
            assert.ok(initial.left >= 0 && initial.right <= initial.viewportWidth);
            assert.ok(initial.top >= 0 && initial.bottom <= initial.viewportHeight);
            if (width >= 900) assert.ok(initial.width >= 500, 'Wide layouts have room for long labels');
            assert.ok(initial.scrollHeight <= initial.clientHeight + 1, 'A full-height viewport shows the entire menu without scrolling');
            assert.equal(await menu.locator('[data-context-action="html"]').isVisible(), true);
            await activate(menu.locator('[data-context-action="close-menu"]'));
            assert.equal(await menu.count(), 0);
            assert.equal(await tools.getAttribute('aria-expanded'), 'false');
            assert.equal(await body.evaluate(element => document.activeElement === element), true);

            // Touch toolbar access and desktop keyboard access must both
            // preserve the current selection, independently of screen width.
            await body.evaluate(element => {
                element.focus();
                const text = element.querySelector('p').firstChild;
                const range = document.createRange();
                range.setStart(text, 0);
                range.setEnd(text, 6);
                getSelection().removeAllRanges();
                getSelection().addRange(range);
            });
            if (touch) await tools.tap();
            else await body.press('Shift+F10');
            assert.equal(await menu.locator('[data-context-selection-only]').first().textContent(), 'Выделенный текст');
            assert.equal(await menu.locator('[data-context-caret-only]').first().isVisible(), false);
            await page.setViewportSize({width, height: 320});
            assert.equal(await tools.isVisible(), touch, 'Resizing does not change the input-based control visibility');
            await menu.waitFor();
            await page.waitForFunction(() => {
                const rect = document.querySelector('.post-editor-context-menu')?.getBoundingClientRect();
                return rect && rect.top >= 0 && rect.bottom <= innerHeight;
            });
            assert.equal(await menu.count(), 1, 'Viewport resize keeps the tools open');
            assert.ok(await menu.evaluate(element => element.scrollHeight > element.clientHeight), 'Small viewports scroll instead of losing tools');
            await activate(menu.locator('[data-context-action="bold"]'));
            assert.match(await body.innerHTML(), /<(?:b|strong)>Server<\/(?:b|strong)>/u);
            assert.equal(await menu.count(), 0);
            assert.equal(await tools.getAttribute('aria-expanded'), 'false');
            assert.deepEqual(errors, []);
        } finally { await context.close(); }
        console.log(`context menu: ${touch ? 'touch toolbar' : 'mouse/keyboard without toolbar'} at ${width}px preserves selection and survives resize`);
    }
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
            try { await runContextMenuRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally {
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
}
