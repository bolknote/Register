import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const resources = execFileSync(process.env.PHP_BIN || 'php',
    [fileURLToPath(new URL('./context-menu-fixture.php', import.meta.url))], {encoding: 'utf8'});

export async function runContextMenuRegressions(browser, origin) {
    for (const mobile of [false, true]) {
        const context = await browser.newContext({
            viewport: mobile ? {width: 390, height: 844} : {width: 1360, height: 900},
            hasTouch: mobile,
        });
        const errors = [];
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        page.on('pageerror', error => errors.push(String(error)));
        await context.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        try {
            await page.goto(origin + '/recovery.html');
            await page.evaluate(html => { document.getElementById('post-editor-resources').outerHTML = html; }, resources);
            const tools = page.getByRole('button', {name: 'Editor tools', exact: true});
            assert.equal(await tools.isVisible(), false);
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
            await body.click();
            await body.press('End');
            const activate = async locator => mobile ? locator.tap() : locator.click();
            await activate(tools);
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
            if (!mobile) assert.ok(initial.width >= 500, 'Desktop tools have room for long labels');
            assert.ok(initial.scrollHeight <= initial.clientHeight + 1, 'A full-height viewport shows the entire menu without scrolling');
            assert.equal(await menu.locator('[data-context-action="html"]').isVisible(), true);
            await activate(menu.locator('[data-context-action="close-menu"]'));
            assert.equal(await menu.count(), 0);
            assert.equal(await tools.getAttribute('aria-expanded'), 'false');
            assert.equal(await body.evaluate(element => document.activeElement === element), true);

            // A toolbar click must not change the selection into an end-of-post
            // caret, including real touch input on narrow screens.
            await body.evaluate(element => {
                element.focus();
                const text = element.querySelector('p').firstChild;
                const range = document.createRange();
                range.setStart(text, 0);
                range.setEnd(text, 6);
                getSelection().removeAllRanges();
                getSelection().addRange(range);
            });
            await activate(tools);
            assert.equal(await menu.locator('[data-context-selection-only]').first().textContent(), 'Выделенный текст');
            assert.equal(await menu.locator('[data-context-caret-only]').first().isVisible(), false);
            await page.setViewportSize(mobile ? {width: 390, height: 320} : {width: 900, height: 320});
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
        console.log(`context menu: ${mobile ? 'touch' : 'desktop'} toolbar access preserves selection, uses available space and survives resize`);
    }
}
