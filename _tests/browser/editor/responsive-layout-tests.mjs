import assert from 'node:assert/strict';
import {chromium, firefox, webkit} from 'playwright';
import {pathToFileURL} from 'node:url';
import {createFixtureServer} from './server.mjs';
import {formData, holdRequests} from './save-tests.mjs';

const widths = [320, 390, 640, 719, 720, 721, 760, 761, 800, 880, 881, 1024, 1200, 1280, 1440, 1920, 2560, 3840];
async function resize(page, width) {
    await page.setViewportSize({width, height: 900});
    // WebKit can apply the media query after the viewport change. Wait for
    // computed typography before measuring layout, not an arbitrary delay.
    await page.waitForFunction(({width, font}) => innerWidth === width
        && Math.abs(parseFloat(getComputedStyle(document.querySelector('#responsive-prose')).fontSize)
            - font) < 0.02, {width, font: expectedProseSize(width)});
}
const expectedProseSize = width => width <= 720 ? 18 : 20;
const measure = page => page.evaluate(async () => {
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    const p = document.querySelector('#responsive-prose');
    const style = getComputedStyle(p);
    const box = selector => document.querySelector(selector).getBoundingClientRect().toJSON();
    const paragraph = p.getBoundingClientRect();
    const drawing = document.createElement('canvas').getContext('2d');
    drawing.font = `${style.fontSize} ${style.fontFamily}`;
    const image = box('.post-media-image');
    const caption = box('.post-caption');
    return {font: parseFloat(style.fontSize), line: parseFloat(style.lineHeight),
        textWidth: paragraph.width, textCh: paragraph.width / drawing.measureText('0').width,
        imageWidth: image.width, captionGap: caption.top - image.bottom,
        body: box('[data-post-inplace-body]'),
        header: box('.site-header-copy'), card: box('.post-card'),
        search: box('.register_search_form'), title: box('.site-title'),
        account: box('.public-auth-user-menu > summary'),
        overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        codeScroll: document.querySelector('#responsive-code').scrollWidth > document.querySelector('#responsive-code').clientWidth,
        tableScroll: document.querySelector('#responsive-table').scrollWidth > document.querySelector('#responsive-table').clientWidth,
        bodyDisplay: getComputedStyle(document.querySelector('[data-post-inplace-body]')).display,
        bodyHtml: document.querySelector('[data-post-inplace-body]').innerHTML};
});

export async function runResponsiveLayoutRegressions(browser, origin) {
    for (const colorScheme of ['light', 'dark']) {
        const context = await browser.newContext({viewport: {width: 1280, height: 900}, colorScheme, reducedMotion: 'reduce'});
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        try {
            await page.goto(origin + '/responsive-layout.html');
            await page.waitForFunction(() => window.editorTest && [...document.images].every(image => image.complete));
            const original = (await measure(page)).bodyHtml;
            const readings = [];
            for (const width of widths) {
                await resize(page, width);
                const current = await measure(page);
                readings.push({width, ...current});
                assert.equal(current.overflow, false, `Only wide code/tables may scroll at ${width}px: ${JSON.stringify(current)}`);
                assert.ok(Math.abs(current.font - expectedProseSize(width)) < 0.02,
                    `Prose keeps its original mobile/desktop size at ${width}px, without growing on wide screens: ${current.font}px`);
                assert.ok(Math.abs(current.line - current.font * 1.6) < 0.02, 'The original line-height is preserved');
                assert.ok(current.textCh <= 75, `The reading measure is capped independently of the page width at ${width}px`);
                assert.ok(Math.abs(current.header.left - current.card.left) < 1, 'Header and posts share the content origin');
                assert.ok(current.captionGap >= 7 && current.captionGap <= 12, 'The caption remains attached to its image');
                assert.equal(current.bodyHtml, original, 'Resizing never rewrites author HTML');
                assert.ok(['block', 'flow-root'].includes(current.bodyDisplay), 'Contenteditable retains normal block flow');
                assert.ok(Math.abs(current.body.width - current.textWidth) < 1,
                    `The published body and its text share a reading column at ${width}px`);
                assert.ok(Math.abs(current.imageWidth - current.body.width) < 1,
                    'A large photo fits the same column in reading and editing');
                if (width >= 1280) {
                    assert.ok(current.textCh >= 65, `Desktop prose uses the intended reading measure: ${JSON.stringify({width, font:current.font, textWidth:current.textWidth, textCh:current.textCh})}`);
                }
                if (width > 720) {
                    const overlap = current.title.left < current.search.right && current.title.right > current.search.left
                        && current.title.top < current.search.bottom && current.title.bottom > current.search.top;
                    assert.equal(overlap, false, `Search must not cover the title at ${width}px`);
                    assert.ok(current.account.bottom <= current.search.top + 1, 'Account controls stay above the search field');
                }
                if (process.env.RESPONSIVE_SCREENSHOT_DIR && [390, 800, 1920].includes(width)
                    && colorScheme === 'dark' && browser.browserType().name() === 'chromium') {
                    await page.locator('.post.body').hover();
                    await page.screenshot({path: `${process.env.RESPONSIVE_SCREENSHOT_DIR}/layout-${width}.png`, fullPage: true});
                }
            }
            for (const edge of [720, 760, 880, 1200, 1440]) {
                const first = readings.find(row => row.width === edge);
                await resize(page, edge + 1);
                const second = await measure(page);
                assert.ok(Math.abs(second.font - first.font
                    - (expectedProseSize(edge + 1) - expectedProseSize(edge))) < 0.02,
                    `Only the original mobile breakpoint changes the prose size at ${edge}px`);
            }
            assert.equal(readings[0].codeScroll, true);
            assert.equal(readings[0].tableScroll, true);
            assert.deepEqual(errors, []);
        } finally { await context.close(); }
    }
    console.log(`${browser.browserType().name()}: original 18/20px prose, responsive media, local overflow and preserved HTML passed`);
}

export async function runResponsiveImageDimensionsRegressions(browser, origin) {
    const displayWidths = {'Retina': 1000, 'Linked Retina': 1000, 'Picture Retina': 1000,
        'Figure Retina': 1000, 'Overlay Retina': 1000, 'Small image': 320,
        'Authored width': 480, 'Natural width': 1600};
    for (const deviceScaleFactor of [1, 2]) {
        const context = await browser.newContext({deviceScaleFactor, viewport: {width: 1280, height: 900},
            colorScheme: deviceScaleFactor === 2 ? 'dark' : 'light', reducedMotion: 'reduce'});
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        const metadata = () => page.locator('.post.body img').evaluateAll(images => images.map(image => ({
            alt: image.alt, width: image.getAttribute('width'), height: image.getAttribute('height'),
            style: image.getAttribute('style'),
        })));
        // This test deliberately changes the root font. Wait for the viewport
        // and measure the actual image layout independently of typography.
        // Its font constraints have their own tests.
        const resizeImages = async width => {
            await page.setViewportSize({width, height: 900});
            await page.waitForFunction(width => innerWidth === width, width);
        };
        const assertDimensions = async label => {
            const state = await page.evaluate(async () => {
                await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                const body = document.querySelector('.post.body');
                const style = getComputedStyle(body);
                const available = body.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
                return {available, overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
                    images: [...body.querySelectorAll('img')].map(image => ({
                        alt: image.alt, naturalWidth: image.naturalWidth, naturalHeight: image.naturalHeight,
                        width: image.getBoundingClientRect().width, height: image.getBoundingClientRect().height,
                    }))};
            });
            assert.equal(state.overflow, false, label + ': no page overflow');
            assert.equal(state.images.length, Object.keys(displayWidths).length);
            for (const image of state.images) {
                const expected = Math.min(displayWidths[image.alt], state.available);
                assert.equal(image.naturalWidth, image.alt === 'Natural width' ? 1600 : 2000);
                assert.ok(Math.abs(image.width - expected) < 1,
                    `${label}: ${image.alt} must respect its display width, not the Retina file width: ${JSON.stringify({image, expected})}`);
                assert.ok(Math.abs(image.height - image.width * image.naturalHeight / image.naturalWidth) < 1,
                    label + ': resizing preserves aspect ratio');
            }
        };
        try {
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            const requests = holdRequests(page, '**/_inplace/post/7');
            await requests.installed;
            await page.goto(origin + '/image-dimensions.html');
            await page.waitForFunction(() => window.editorTest && [...document.images]
                .every(image => image.complete && image.naturalWidth > 0));
            const originalMetadata = await metadata();
            const originalHtml = await page.locator('.post.body').innerHTML();
            await assertDimensions(`DPR ${deviceScaleFactor}, initial 1280px`);
            for (const width of [320, 390, 800, 1280, 1920, 2560, 3840]) {
                await resizeImages(width);
                await assertDimensions(`DPR ${deviceScaleFactor}, ${width}px`);
                assert.equal(await page.locator('.post.body').innerHTML(), originalHtml, 'Resizing keeps authored markup');
            }
            await page.evaluate(() => { document.documentElement.style.fontSize = '32px'; });
            await assertDimensions(`DPR ${deviceScaleFactor}, enlarged user font`);
            await page.evaluate(() => { document.documentElement.style.removeProperty('font-size'); });
            await resizeImages(1280);
            await page.locator('.post.body').hover();
            await page.locator('.post-edit-start').click();
            for (const width of [390, 1280, 2560]) {
                await resizeImages(width);
                await assertDimensions(`DPR ${deviceScaleFactor}, editing at ${width}px`);
                assert.deepEqual(await metadata(), originalMetadata, 'Opening/resizing the editor preserves image dimensions');
            }
            await page.locator('#responsive-after').click();
            await page.keyboard.press('End');
            await page.keyboard.type(' Text entered with fixed image widths.');
            await page.locator('.post-edit-save').click();
            const request = await requests.next();
            const data = await formData(request);
            assert.equal(data.get('inplace_action'), 'edit');
            const savedBody = data.get('body');
            assert.ok(savedBody.includes('Text entered with fixed image widths.'));
            await request.fulfill({json: {success: true, action: 'edit', revision: 2,
                title: 'Заметка с текстом и большой иллюстрацией',
                published_at: 1700000000, datetime: '2023-11-14T22:13:20Z', time: '14 ноября 2023 года, 22:13',
                scheduled: false, tags: [], body_html: `<div class="post body" data-post-inplace-body>${savedBody}</div>`, message: 'Saved'}});
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            await assertDimensions(`DPR ${deviceScaleFactor}, saved`);
            assert.deepEqual(await metadata(), originalMetadata, 'Saving preserves image attributes and authored width');
            await page.locator('.post.body').hover();
            await page.locator('.post-edit-start').click();
            await assertDimensions(`DPR ${deviceScaleFactor}, reopened`);
            assert.deepEqual(await metadata(), originalMetadata);
            await page.locator('.post-edit-cancel').click();
            assert.deepEqual(errors, []);
        } finally { await context.close(); }
    }
    console.log(`${browser.browserType().name()}: authored image widths, Retina, responsive wrappers and save/reopen passed`);
}

export async function runResponsiveEditorRegressions(browser, origin) {
    for (const hasTouch of [false, true]) {
        const context = await browser.newContext({hasTouch, viewport: {width: 1280, height: 900}, reducedMotion: 'reduce'});
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
        try {
            await page.goto(origin + '/responsive-layout.html');
            await page.waitForFunction(() => window.editorTest && [...document.images].every(image => image.complete));
            const original = await measure(page);
            await page.locator('.post.body').hover();
            await page.locator('.post-edit-start').click();
            const body = page.locator('[data-post-inplace-body]');
            const originalText = await body.textContent();
            const editing = await measure(page);
            assert.ok(Math.abs(editing.body.width - original.textWidth) < 1,
                `The visual editor must use the reading column, not the full article width: ${JSON.stringify({body: editing.body.width, prose: original.textWidth})}`);
            assert.ok(Math.abs(editing.font - original.font) < 0.02, 'Opening the editor keeps the prose size');
            assert.ok(Math.abs(original.textWidth - editing.textWidth) < 1, 'Opening the editor keeps the reading measure');
            assert.ok(Math.abs(original.imageWidth - editing.imageWidth) < 1, 'Opening the editor keeps media geometry');
            if (process.env.RESPONSIVE_SCREENSHOT_DIR && !hasTouch && browser.browserType().name() === 'chromium') {
                await page.screenshot({path: `${process.env.RESPONSIVE_SCREENSHOT_DIR}/editor-column-1280.png`, fullPage: true});
            }
            for (const width of [320, 390, 760, 800, 1280, 1920]) {
                await resize(page, width);
                const current = await measure(page);
                assert.ok(Math.abs(current.body.width - current.textWidth) < 1,
                    `The editor stays within the reading column at ${width}px`);
                assert.ok(Math.abs(current.font - expectedProseSize(width)) < 0.02,
                    `The active editor keeps the original prose size at ${width}px: ${current.font}px`);
                assert.equal(current.overflow, false, `The active editor stays within the viewport at ${width}px`);
                assert.equal(await body.textContent(), originalText, 'Resizing cannot change the edited text');
                assert.ok(Math.abs(current.captionGap - original.captionGap) < 1, 'Editor resize retains the caption gap');
                const tools = await page.locator('.post-inplace-tools').boundingBox();
                const date = await page.locator('.post.time time').boundingBox();
                const calendar = await page.locator('.post-inplace-date-button').boundingBox();
                const overlaps = bounds => bounds.x < tools.x + tools.width && bounds.x + bounds.width > tools.x
                    && bounds.y < tools.y + tools.height && bounds.y + bounds.height > tools.y;
                assert.ok(!overlaps(date) && !overlaps(calendar), 'Tools do not cover the publication date');
                if (hasTouch) {
                    for (const selector of ['.post-edit-save', '.post-edit-cancel', '.post-edit-menu', '.post-create-start',
                        '.register-reaction-primary', '.post-foot-comments a', '.public-auth-user-menu > summary']) {
                        const control = page.locator(selector);
                        // Reactions/comments are intentionally hidden while editing.
                        if (!await control.isVisible()) continue;
                        const bounds = await control.boundingBox();
                        assert.ok(bounds.width >= 43.9 && bounds.height >= 43.9, `Touch target ${selector} is usable at ${width}px`);
                    }
                }
            }
            await resize(page, 1280);
            await page.evaluate(() => {
                const paragraph = document.querySelector('#responsive-after');
                const range = document.createRange();
                range.selectNodeContents(paragraph);
                range.collapse(false);
                const body = document.querySelector('[data-post-inplace-body]');
                body.focus();
                getSelection().removeAllRanges();
                getSelection().addRange(range);
            });
            await page.keyboard.press('Enter');
            await page.keyboard.type('Text entered after resizing.');
            let savedBody;
            let expectedSavedText = 'Text entered after resizing.';
            await page.route('**/_inplace/post/7', async route => {
                const request = route.request().postData() || '';
                savedBody = request.match(/name="body"\r?\n\r?\n([\s\S]*?)\r?\n--/u)?.[1];
                assert.ok(savedBody?.includes(expectedSavedText));
                await route.fulfill({json: {success: true, action: 'edit', revision: 2,
                    title: 'Заметка с текстом и большой иллюстрацией',
                    published_at: 1700000000, datetime: '2023-11-14T22:13:20Z', time: '14 ноября 2023 года, 22:13',
                    scheduled: false, tags: [{name: 'тестовая заметка', url: '/tags/example/'}],
                    body_html: `<div class="post body" data-post-inplace-body>${savedBody}</div>`, message: 'Saved'}});
            });
            await page.locator('.post-edit-save').click();
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            assert.ok(Math.abs((await measure(page)).font - 20) < 0.02, 'Saving keeps the desktop prose size');
            assert.ok((await body.textContent()).includes('Text entered after resizing.'));
            assert.equal(await body.locator('img').count(), 1);
            assert.equal(await body.locator('table').count(), 1);
            await page.locator('.post.body').hover();
            await page.locator('.post-edit-start').click();
            assert.ok(Math.abs((await measure(page)).font - 20) < 0.02, 'Reopening keeps the desktop prose size');
            assert.ok((await body.textContent()).includes('Text entered after resizing.'));
            assert.ok(Math.abs((await measure(page)).captionGap - original.captionGap) < 1);
            await page.locator('.post-edit-cancel').click();
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            if (hasTouch) {
                for (const selector of ['.register-reaction-primary', '.post-foot-comments a']) {
                    const bounds = await page.locator(selector).boundingBox();
                    assert.ok(bounds.width >= 43.9 && bounds.height >= 43.9, `Published touch target ${selector} stays usable`);
                }
            }

            // Equivalent layout widths for a 1280px window at browser zoom.
            // Font size is evaluated in screen pixels, not compensated via JS.
            let previousScreenFont = 0;
            for (const zoom of [0.67, 0.8, 1, 1.25, 1.5, 2]) {
                await resize(page, Math.round(1280 / zoom));
                const state = await measure(page);
                assert.equal(state.overflow, false);
                assert.ok(state.font * zoom > previousScreenFont, 'Browser zoom increases the physical type size');
                previousScreenFont = state.font * zoom;
            }
            await resize(page, 1280);
            // Simulate a landscape cutout: the tools must stay inside the safe
            // area as well as the text, and readers need no empty tool column.
            await page.evaluate(() => { document.documentElement.style.setProperty('--safe-side', '48px'); });
            await page.waitForFunction(() => document.querySelector('.post-edit-start').getBoundingClientRect().left >= 48);
            const safe = await page.locator('.post-edit-start').boundingBox();
            assert.ok(safe.x >= 48, 'The tool rail also respects the phone safe area');
            await page.evaluate(() => { document.documentElement.style.removeProperty('--safe-side'); });
            const normalFont = (await measure(page)).font;
            await page.evaluate(() => { document.documentElement.style.fontSize = '32px'; });
            await page.waitForFunction(() => parseFloat(getComputedStyle(document.querySelector('#responsive-prose')).fontSize) > 35);
            const enlarged = await measure(page);
            assert.ok(enlarged.font > normalFont * 1.6, 'The reading scale respects a larger user font');
            assert.equal(enlarged.overflow, false);

            // Native typing in an empty body can produce bare text/inline nodes
            // before the first Enter. Paragraph-only width rules miss that case.
            await page.evaluate(() => { document.documentElement.style.removeProperty('font-size'); });
            await resize(page, 1280);
            await page.locator('.post.body').hover();
            await page.locator('.post-edit-start').click();
            await body.click({position: {x: 40, y: 20}});
            await page.keyboard.press('ControlOrMeta+A');
            await page.keyboard.press('Backspace');
            const nativeText = 'A paragraph entered directly in the visual editor. '
                + 'The text and its editable field must use the same column before and after saving. '.repeat(7).trim();
            expectedSavedText = 'A paragraph entered directly in the visual editor.';
            const nativeLayout = () => body.evaluate(async element => {
                await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                const style = getComputedStyle(element);
                const bounds = element.getBoundingClientRect();
                const card = element.closest('.post-card').getBoundingClientRect();
                const surface = element.closest('.post-card').querySelector('[data-editor-field-surface="body"]');
                const lines = [];
                const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
                let previousTop = null;
                let node;
                while ((node = walker.nextNode())) {
                    for (let index = 0; index < node.length; index++) {
                        const range = document.createRange();
                        range.setStart(node, index);
                        range.setEnd(node, index + 1);
                        const rect = range.getBoundingClientRect();
                        if (!rect.height) continue;
                        if (previousTop === null || Math.abs(rect.top - previousTop) > 1) {
                            lines.push('');
                            previousTop = rect.top;
                        }
                        lines[lines.length - 1] += node.data[index].replace(/\u00a0/u, ' ');
                    }
                }
                return {width: bounds.width, expectedWidth: Math.min(card.width,
                    parseFloat(style.getPropertyValue('--text-width'))), font: parseFloat(style.fontSize),
                    surfaceWidth: surface?.getBoundingClientRect().width ?? null, lines};
            });
            const empty = await nativeLayout();
            assert.ok(Math.abs(empty.width - empty.expectedWidth) < 1, 'An empty editor cannot expand to the page width');
            await page.keyboard.type(nativeText);
            assert.equal((await body.textContent()).replace(/\u00a0/gu, ' '), nativeText, 'Every typed character remains in the editor');
            let typed;
            for (const width of [390, 1280, 1920, 3840]) {
                await page.setViewportSize({width, height: 900});
                await page.waitForFunction(({width, font}) => innerWidth === width
                    && Math.abs(parseFloat(getComputedStyle(document.querySelector('[data-post-inplace-body]')).fontSize)
                        - font) < 0.02, {width, font: expectedProseSize(width)});
                typed = await nativeLayout();
                assert.ok(Math.abs(typed.width - typed.expectedWidth) < 1,
                    `Bare/native text keeps the reading column at ${width}px`);
                assert.ok(Math.abs(typed.surfaceWidth - typed.width - 16) < 1,
                    'The background follows the constrained field, with only its existing 8px outer padding');
                assert.ok(typed.lines.length > 2, 'Native typing wraps instead of filling the entire page');
                if (process.env.RESPONSIVE_SCREENSHOT_DIR && width === 1920 && !hasTouch
                    && browser.browserType().name() === 'chromium') {
                    await page.screenshot({path: `${process.env.RESPONSIVE_SCREENSHOT_DIR}/editor-native-column-1920.png`, fullPage: true});
                }
            }
            await page.locator('.post-edit-save').click();
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            const published = await nativeLayout();
            assert.ok(Math.abs(published.width - typed.width) < 1, 'Saving does not change the native text column');
            assert.deepEqual(published.lines, typed.lines, 'Every line wraps identically when editing and reading');
            assert.equal((await body.textContent()).replace(/\u00a0/gu, ' '), nativeText, 'Saving retains all typed text');
            await page.locator('.post.body').hover();
            await page.locator('.post-edit-start').click();
            const reopened = await nativeLayout();
            assert.ok(Math.abs(reopened.width - published.width) < 1, 'Reopening keeps the published column');
            assert.deepEqual(reopened.lines, published.lines, 'Reopening keeps the published line breaks');
            await page.locator('.post-edit-cancel').click();
            assert.deepEqual(errors, []);
        } finally { await context.close(); }
    }
    console.log(`${browser.browserType().name()}: WYSIWYG column/line breaks, touch targets, native Enter/save/reopen and zoom passed`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit]) {
            if (process.env.EDITOR_TEST_BROWSER && process.env.EDITOR_TEST_BROWSER !== engine.name()) continue;
            const browser = await engine.launch();
            try {
                await runResponsiveLayoutRegressions(browser, `http://127.0.0.1:${server.address().port}`);
                await runResponsiveImageDimensionsRegressions(browser, `http://127.0.0.1:${server.address().port}`);
                await runResponsiveEditorRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            }
            finally { await browser.close(); }
        }
    } finally { await new Promise(resolve => server.close(resolve)); }
}
