import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {mkdir} from 'node:fs/promises';
import {join} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';

const resources = execFileSync(process.env.PHP_BIN || 'php',
    [fileURLToPath(new URL('./context-menu-fixture.php', import.meta.url))], {encoding: 'utf8'});

async function assertHeaderLayout(menu) {
    const layout = await menu.locator('.post-editor-context-header').evaluate(header => {
        const title = header.querySelector('strong:not([hidden])');
        const close = header.querySelector('[data-context-action="close-menu"]');
        const kicker = header.querySelector('.post-editor-context-kicker');
        const rect = element => {
            const {left, right, top, bottom, width, height} = element.getBoundingClientRect();
            return {left, right, top, bottom, width, height, centerY: (top + bottom) / 2};
        };
        const sections = Array.from(header.parentElement.querySelectorAll('.post-editor-context-section'))
            .filter(section => section.getClientRects().length > 0);
        return {header: rect(header), title: rect(title), close: rect(close), kicker: rect(kicker),
            sectionBorders: sections.map(section => getComputedStyle(section).borderTopWidth)};
    });
    assert.ok(Math.abs(layout.title.centerY - layout.close.centerY) <= 1.5,
        `The heading and close icon share a vertical center: ${JSON.stringify(layout)}`);
    assert.ok(Math.abs(layout.header.centerY - layout.close.centerY) <= 1,
        'The close control has balanced vertical padding');
    assert.ok(layout.close.width >= 44 && layout.close.height >= 44, 'Closing has a usable pointer and touch target');
    assert.ok(layout.kicker.right < layout.title.left, 'The editor label and context title stay separated');
    assert.ok(layout.title.right < layout.close.left, 'The context title cannot overlap the close control');
    assert.equal(layout.sectionBorders[0], '0px', 'The first visible section cannot double the header separator');
    assert.ok(layout.sectionBorders.slice(1).every(border => border === '1px'), 'The remaining visible sections keep one separator');
}

async function assertCompactInsertItems(menu, touch) {
    const items = await menu.locator('.post-editor-entity-grid button').evaluateAll(buttons => buttons.map(button => {
        const bounds = element => {
            const {left, right, top, bottom, width, height} = element.getBoundingClientRect();
            return {left, right, top, bottom, width, height, centerY: (top + bottom) / 2};
        };
        return {name: button.textContent.trim(), button: bounds(button), icon: bounds(button.firstElementChild),
            label: bounds(button.lastElementChild), background: getComputedStyle(button).backgroundColor,
            highlighted: button.matches(':hover, :focus-visible')};
    }));
    assert.equal(items.length, 4, 'All four insertion commands remain available');
    for (const item of items) {
        assert.ok(item.icon.right < item.label.left, `${item.name}: the icon is beside the label, not above it`);
        assert.ok(Math.abs(item.icon.centerY - item.label.centerY) <= 1, `${item.name}: the icon and label are centered together`);
        assert.ok(item.button.height <= 48 && item.label.height <= 20, `${item.name}: a compact single-line item`);
        assert.ok(item.button.width >= 44 && item.button.height >= (touch ? 44 : 32), `${item.name}: usable activation target`);
        if (!item.highlighted) assert.equal(item.background, 'rgba(0, 0, 0, 0)', `${item.name}: no permanent tile background`);
    }
}

async function assertUtilityItems(menu, touch, platform) {
    const shortcuts = platform === 'macos' ? {
        undo: ['⌘Z', 'Meta+Z'], redo: ['⇧⌘Z', 'Meta+Shift+Z'],
        copy: ['⌘C', 'Meta+C'], cut: ['⌘X', 'Meta+X'], 'select-all': ['⌘A', 'Meta+A'],
    } : {
        undo: ['Ctrl+Z', 'Control+Z'],
        redo: platform === 'windows' ? ['Ctrl+Y', 'Control+Y'] : ['Ctrl+Shift+Z', 'Control+Shift+Z'],
        copy: ['Ctrl+C', 'Control+C'], cut: ['Ctrl+X', 'Control+X'], 'select-all': ['Ctrl+A', 'Control+A'],
    };
    const items = await menu.locator('.post-editor-context-utility button').evaluateAll(buttons => buttons
        .filter(button => button.getClientRects().length > 0).map(button => {
            const bounds = element => {
                const {left, right, top, bottom, width, height} = element.getBoundingClientRect();
                return {left, right, top, bottom, width, height, centerY: (top + bottom) / 2};
            };
            const icon = button.querySelector('svg');
            const key = button.querySelector('kbd');
            return {action: button.dataset.contextAction, name: button.getAttribute('aria-label'),
                title: button.getAttribute('title'), shortcut: key.textContent, ariaShortcut: button.getAttribute('aria-keyshortcuts'),
                button: bounds(button), icon: icon ? bounds(icon) : null, key: bounds(key)};
        }));
    assert.ok(items.length >= 3, 'History and selection commands remain available');
    for (const item of items) {
        assert.ok(item.icon, `${item.action}: the command has an icon`);
        assert.ok(item.name && item.title.startsWith(item.name), `${item.action}: the name remains available to readers and on hover`);
        assert.equal(item.shortcut, shortcuts[item.action][0]);
        assert.equal(item.ariaShortcut, shortcuts[item.action][1]);
        assert.ok(item.title.endsWith(item.shortcut), `${item.action}: the tooltip uses this platform's shortcut`);
        assert.ok(item.icon.right < item.key.left && item.key.right <= item.button.right,
            `${item.action}: the shortcut fits next to the icon`);
        assert.ok(Math.abs(item.icon.centerY - item.key.centerY) <= 1, `${item.action}: the icon and shortcut share a center`);
        assert.ok(item.button.height <= 48 && item.button.height >= (touch ? 44 : 32), `${item.action}: compact and tappable`);
    }
}

export async function runContextMenuRegressions(browser, origin) {
    for (const {touch, width, platform} of [
        {touch: false, width: 1360, platform: 'macos'}, {touch: false, width: 390, platform: 'windows'},
        {touch: true, width: 390, platform: 'linux'}, {touch: true, width: 1360, platform: 'windows'},
        {touch: true, width: 320, platform: 'macos'},
    ]) {
        const context = await browser.newContext({
            viewport: {width, height: 900},
            hasTouch: touch,
            colorScheme: touch ? 'dark' : 'light',
        });
        const errors = [];
        await context.addInitScript(platform => {
            const names = {macos: ['MacIntel', 'macOS'], windows: ['Win32', 'Windows'], linux: ['Linux x86_64', 'Linux']};
            Object.defineProperty(navigator, 'platform', {configurable: true, value: names[platform][0]});
            Object.defineProperty(navigator, 'userAgentData', {configurable: true, value: {platform: names[platform][1]}});
        }, platform);
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
            if (process.env.EDITOR_MENU_SCREENSHOTS) {
                await mkdir(process.env.EDITOR_MENU_SCREENSHOTS, {recursive: true});
                await menu.screenshot({path: join(process.env.EDITOR_MENU_SCREENSHOTS,
                    `${browser.browserType().name()}-${width}-${touch ? 'dark-touch' : 'light-mouse'}.png`)});
            }
            await assertHeaderLayout(menu);
            await assertCompactInsertItems(menu, touch);
            await assertUtilityItems(menu, touch, platform);
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
            await assertHeaderLayout(menu);
            await assertUtilityItems(menu, touch, platform);
            await page.setViewportSize({width, height: 320});
            assert.equal(await tools.isVisible(), touch, 'Resizing does not change the input-based control visibility');
            await menu.waitFor();
            await page.waitForFunction(() => {
                const rect = document.querySelector('.post-editor-context-menu')?.getBoundingClientRect();
                return rect && rect.top >= 0 && rect.bottom <= innerHeight;
            });
            assert.equal(await menu.count(), 1, 'Viewport resize keeps the tools open');
            assert.ok(await menu.evaluate(element => element.scrollHeight > element.clientHeight), 'Small viewports scroll instead of losing tools');
            await menu.evaluate(element => { element.scrollTop = element.scrollHeight; });
            await assertHeaderLayout(menu);
            assert.ok(await menu.locator('.post-editor-context-header').evaluate(header => {
                const bounds = header.getBoundingClientRect();
                const menu = header.closest('.post-editor-context-menu').getBoundingClientRect();
                return bounds.top >= menu.top && bounds.bottom <= menu.bottom;
            }), 'The aligned header stays visible when a short menu scrolls');
            await activate(menu.locator('[data-context-action="bold"]'));
            assert.match(await body.innerHTML(), /<(?:b|strong)>Server<\/(?:b|strong)>/u);
            assert.equal(await menu.count(), 0);
            assert.equal(await tools.getAttribute('aria-expanded'), 'false');
            const openUtilities = async () => {
                if (touch) await tools.tap();
                else await body.press('Shift+F10');
                await menu.waitFor();
            };
            await openUtilities();
            await activate(menu.locator('[data-context-action="undo"] svg'));
            assert.doesNotMatch(await body.innerHTML(), /<(?:b|strong)>Server<\/(?:b|strong)>/u,
                'Clicking the undo icon really undoes the formatting');
            await openUtilities();
            await activate(menu.locator('[data-context-action="redo"] svg'));
            assert.match(await body.innerHTML(), /<(?:b|strong)>Server<\/(?:b|strong)>/u,
                'Clicking the redo icon restores the formatting');
            await body.press('ArrowRight');
            await openUtilities();
            await activate(menu.locator('[data-context-action="select-all"] svg'));
            assert.equal(await page.evaluate(() => getSelection().getRangeAt(0).cloneContents().textContent), await body.textContent(),
                'Clicking the selection icon selects all author text');
            assert.equal(await menu.count(), 0);
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
