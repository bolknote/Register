import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';

const tagNames = ['Лаборатория прибор-42', 'сенсор-7 документация', 'Модель-42 SDK-7',
    'программирование', 'Справочник устройств', 'Книги & документы'];

async function inspectTags(page, view) {
    return page.locator(`[data-tag-view="${view}"] .post-tag-link`).evaluateAll(links => links.map(link => {
        const rect = link.getBoundingClientRect();
        const style = getComputedStyle(link);
        const walker = document.createTreeWalker(link, NodeFilter.SHOW_TEXT);
        const spaces = [];
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            for (const match of node.textContent.matchAll(/ /gu)) {
                const range = document.createRange();
                range.setStart(node, match.index);
                range.setEnd(node, match.index + 1);
                spaces.push(range.getBoundingClientRect().width);
            }
        }
        const text = document.createRange();
        text.selectNodeContents(link);
        const textRect = text.getBoundingClientRect();
        return {
            text: link.textContent, visibleText: link.innerText, href: link.getAttribute('href'),
            noBreaks: Array.from(link.querySelectorAll('nobr'), word => word.textContent),
            spaces, height: rect.height, centerOffset: Math.abs((textRect.top + textRect.bottom - rect.top - rect.bottom) / 2),
            border: style.borderTopWidth, radius: style.borderTopLeftRadius,
            left: rect.left, right: rect.right, viewport: window.innerWidth,
        };
    }));
}

export async function runTagDisplayRegressions(browser, origin) {
    for (const width of [1000, 390]) {
        for (const colorScheme of ['light', 'dark']) {
            // This must work on the server-rendered page, without JS enhancement.
            const context = await browser.newContext({javaScriptEnabled: false,
                viewport: {width, height: 800}, colorScheme});
            const page = await context.newPage();
            page.setDefaultTimeout(10000);
            try {
                const response = await page.goto(origin + '/tag-display.html');
                assert.equal(response.status(), 200);
                for (const view of ['post', 'post_short']) {
                    const tags = await inspectTags(page, view);
                    assert.deepEqual(tags.map(tag => tag.text), tagNames);
                    assert.deepEqual(tags.map(tag => tag.noBreaks), [['прибор-42'], ['сенсор-7'], ['Модель-42', 'SDK-7'], [], [], []],
                        'The real typography must protect hyphenated words before testing their spacing');
                    for (const [index, tag] of tags.entries()) {
                        const label = `${view}, ${width}px, ${colorScheme}, ${tag.text}`;
                        assert.equal(tag.visibleText, tagNames[index], `${label}: a tag is one continuous text run`);
                        assert.equal(tag.href, `/tags/sample-${index}/`);
                        assert.ok(tag.spaces.every(space => space > 1), `${label}: every interword space must have visible width (${tag.spaces})`);
                        assert.ok(Math.abs(tag.height - 24) < 1, `${label}: retain the existing pill height (${tag.height})`);
                        assert.ok(tag.centerOffset < 2, `${label}: retain vertically centered text (${tag.centerOffset})`);
                        assert.equal(tag.border, '1px');
                        assert.ok(parseFloat(tag.radius) > 0);
                        assert.ok(tag.left >= 0 && tag.right <= tag.viewport, `${label}: mobile wrapping must stay inside the viewport`);
                    }
                }
                console.log(`${browser.browserType().name()}: tag spaces, preserved pills and wrapping passed at ${width}px in ${colorScheme}`);
            } finally { await context.close(); }
        }
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
            try { await runTagDisplayRegressions(browser, `http://127.0.0.1:${server.address().port}`); }
            finally { await browser.close(); }
        }
    } finally {
        server.closeAllConnections();
        await new Promise(resolve => server.close(resolve));
    }
}
