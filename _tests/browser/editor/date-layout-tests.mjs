import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

export async function runDateLayoutRegressions(browser, origin) {
    const blogStyle = await readFile(new URL('../../../_assets/register/blog/site.css', import.meta.url), 'utf8');
    const image = '<div class="post-picture post-media-picture" data-post-media-kind="image">'
        + '<img class="post-media-image" src="/date-layout-image.svg" width="600" height="200" alt="Ordinary image"></div>';
    const paragraph = '<p>A normal paragraph that belongs to a generic post.</p>';
    for (const width of [1280, 390]) {
        for (const body of [paragraph, image]) {
            const context = await browser.newContext({viewport: {width, height: 900}, colorScheme: 'dark'});
            const page = await context.newPage();
            page.setDefaultTimeout(10000);
            const errors = [];
            page.on('pageerror', error => errors.push(String(error)));
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            await page.route('**/date-layout-blog.css', route => route.fulfill({contentType: 'text/css', body: blogStyle}));
            await page.route('**/date-layout-image.svg', route => route.fulfill({contentType: 'image/svg+xml',
                body: '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="200"><rect width="600" height="200" fill="#313f48"/></svg>'}));
            await page.route('**/recovery-fixture.css', route => route.fulfill({contentType: 'text/css',
                body: 'body {margin:40px 24px} #content {width:min(680px,100%)} .post-inplace-tools {opacity:1}'}));
            await page.route('**/recovery-fixture.js', async route => {
                const response = await route.fetch();
                await route.fulfill({response, body: (await response.text()).replace(
                    "const body = creating ? '' : `<p>Server body ${revision}</p>`;",
                    "const body = creating ? '' : " + JSON.stringify(body) + ';',
                )});
            });
            const readingLayout = () => page.evaluate(() => {
                const card = document.querySelector('.post-card');
                return ['.post.head', '.post.time', '.post.body'].map(selector => {
                    const bounds = card.querySelector(selector).getBoundingClientRect();
                    return {top: bounds.top + scrollY, bottom: bounds.bottom + scrollY};
                });
            });
            const expectCentered = () => page.waitForFunction(() => {
                const card = document.querySelector('.post-card.is-editing');
                const title = card?.querySelector('[data-editor-field-surface="title"]')?.getBoundingClientRect();
                const body = card?.querySelector('[data-editor-field-surface="body"]')?.getBoundingClientRect();
                const date = card?.querySelector('.post.time')?.getBoundingClientRect();
                return title && body && date && Math.abs((date.top + date.bottom) / 2 - (title.bottom + body.top) / 2) < 1;
            });
            try {
                await page.goto(origin + '/recovery.html');
                await page.addStyleTag({url: '/date-layout-blog.css'});
                await page.waitForFunction(() => Array.from(document.images).every(image => image.complete));
                const before = await readingLayout();
                await page.getByRole('button', {name: 'Edit', exact: true}).click();
                await expectCentered();
                if (width === 1280) {
                    const editing = await readingLayout();
                    assert.ok(Math.abs(editing[0].top - before[0].top) < 1, 'Centering the date keeps the heading in place');
                    assert.ok(Math.abs(editing[2].top - before[2].top) < 1, 'Centering the date keeps the body in place');
                }
                await page.locator('[data-post-inplace-title]').fill('A longer title that wraps onto several lines and changes the visible field edges');
                await expectCentered();
                await page.setViewportSize({width: width === 1280 ? 640 : 340, height: 900});
                await expectCentered();
                await page.setViewportSize({width, height: 900});
                await expectCentered();
                if (process.env.EDITOR_DATE_SCREENSHOT && width === 1280 && body === image) {
                    await page.locator('.post-card').screenshot({path: process.env.EDITOR_DATE_SCREENSHOT});
                }
                await page.getByRole('button', {name: 'Cancel', exact: true}).click();
                await page.getByRole('button', {name: 'Discard changes', exact: true}).click();
                await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
                const after = await readingLayout();
                after.forEach((bounds, index) => {
                    assert.ok(Math.abs(bounds.top - before[index].top) < 1 && Math.abs(bounds.bottom - before[index].bottom) < 1,
                        `Leaving the editor restores the reading layout (${index}): ${JSON.stringify({before: before[index], after: bounds})}`);
                });
                assert.deepEqual(errors, []);
            } finally { await context.close(); }
        }
    }
    console.log(`${browser.browserType().name()}: date stays centered between editor fields for prose/images, wrapped titles and mobile layouts`);
}
