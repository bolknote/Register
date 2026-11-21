import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {mkdir} from 'node:fs/promises';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {formData} from './save-tests.mjs';

const resources = execFileSync(process.env.PHP_BIN || 'php',
    [fileURLToPath(new URL('./context-menu-fixture.php', import.meta.url))], {encoding: 'utf8'});
const description = 'Подробное описание изображения: два списка на листах в клетку, заметки и небольшие рисунки. '.repeat(4).trim();

export async function runImageRetinaDetectionRegressions(browser, origin) {
    const cases = [
        {name: 'saved half-size attributes', pixels: [800, 500], attributes: 'width="400" height="250"', checked: true},
        {name: 'fractional half-size attributes', pixels: [801, 501], attributes: 'width="400.5" height="250.5"', checked: true},
        {name: 'rounded half-size attributes', pixels: [801, 501], attributes: 'width="401" height="251"', checked: true},
        {name: 'only a half-size width', pixels: [800, 500], attributes: 'width="400"', checked: true},
        {name: 'authored half-size CSS', pixels: [800, 500], attributes: 'width="800" height="500" style="width:400px;height:250px"', checked: true},
        {name: 'responsive column constraint', pixels: [1600, 1000], attributes: '', checked: false},
        {name: 'explicit full size', pixels: [800, 500], attributes: 'width="800" height="500"', checked: false},
    ];
    for (const fixture of cases) {
        const page = await browser.newPage();
        try {
            await page.route('**/recovery.html', async route => {
                const response = await route.fetch();
                // Match public articles: authored style attributes are allowed.
                await route.fulfill({response, headers: {...response.headers(),
                    'content-security-policy': response.headers()['content-security-policy']
                        + "; style-src 'self'; style-src-attr 'unsafe-inline'",
                }});
            });
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            await page.route('**/initial-retina.svg', route => route.fulfill({contentType: 'image/svg+xml', body:
                `<svg xmlns="http://www.w3.org/2000/svg" width="${fixture.pixels[0]}" height="${fixture.pixels[1]}"><rect width="100%" height="100%" fill="gray"/></svg>`,
            }));
            const body = `<p>Image properties</p><div class="post-picture"><img src="/initial-retina.svg" alt="Existing description" ${fixture.attributes}></div><p>After image</p>`;
            await page.route('**/recovery-fixture.js', async route => {
                const response = await route.fetch();
                await route.fulfill({response, body: (await response.text()).replace(
                    "const body = creating ? '' : `<p>Server body ${revision}</p>`;",
                    "const body = creating ? '' : " + JSON.stringify(body) + ';',
                )});
            });
            await page.goto(origin + '/recovery.html');
            await page.evaluate(html => {
                const config = document.getElementById('post-editor-resources').dataset.config;
                document.getElementById('post-editor-resources').outerHTML = html;
                document.getElementById('post-editor-resources').dataset.config = config;
            }, resources);
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            const image = page.locator('.post-card.is-editing img');
            await image.evaluate(image => image.decode());
            const original = await image.evaluate(image => image.outerHTML);
            await image.click({button: 'right'});
            assert.equal(await page.getByRole('switch', {name: 'Для Ретины', exact: true}).isChecked(), fixture.checked,
                `${fixture.name}: the initial switch state must match the existing display dimensions.`);
            assert.equal(await image.evaluate(image => image.outerHTML), original, 'Opening the panel must not resize or rewrite the image.');
            assert.equal(await page.evaluate(() => window.editorTest.editorStates
                .get(document.querySelector('.post-card.is-editing')).bodyDirty), false);
        } finally { await page.close(); }
    }
    console.log(`${browser.browserType().name()}: existing Retina dimensions are recognized on first opening without changing the article`);
}

export async function runImagePropertiesRegressions(browser, origin) {
    for (const width of [1360, 390, 320]) {
        const context = await browser.newContext({viewport: {width, height: 900}, colorScheme: 'dark'});
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        try {
            await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
            await page.route('**/image-properties.svg', route => route.fulfill({contentType: 'image/svg+xml', body:
                '<svg xmlns="http://www.w3.org/2000/svg" width="801" height="501"><rect width="801" height="501" fill="#50776d"/></svg>',
            }));
            const initial = `<p>Before image</p><div class="post-picture post-media-picture"><img src="/image-properties.svg" width="801" height="501" alt="${description}"></div><p>After image</p>`;
            await page.route('**/recovery-fixture.js', async route => {
                const response = await route.fetch();
                await route.fulfill({response, body: (await response.text()).replace(
                    "const body = creating ? '' : `<p>Server body ${revision}</p>`;",
                    "const body = creating ? '' : " + JSON.stringify(initial) + ';',
                )});
            });
            let savedBody = null;
            await page.route('**/_inplace/post/9', async route => {
                const data = await formData(route);
                assert.equal(data.get('inplace_action'), 'edit');
                savedBody = data.get('body');
                await route.fulfill({json: {success: true, action: 'edit', revision: 2,
                    title: data.get('title'), body_html: `<div class="post body" data-post-inplace-body>${savedBody}</div>`,
                    tags: [], scheduled: false, published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September',
                }});
            });
            await page.goto(origin + '/recovery.html');
            await page.evaluate(html => {
                const config = document.getElementById('post-editor-resources').dataset.config;
                document.getElementById('post-editor-resources').outerHTML = html;
                document.getElementById('post-editor-resources').dataset.config = config;
            }, resources);
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            const image = page.locator('.post-card.is-editing img');
            await image.evaluate(image => image.decode());
            const open = () => image.click({button: 'right'});
            await open();
            const menu = page.locator('.post-editor-context-menu');
            const alt = menu.locator('[data-context-image-alt-input]');
            assert.equal(await alt.evaluate(field => field.tagName), 'TEXTAREA', 'Descriptions must wrap in a multiline field.');
            assert.equal(await alt.inputValue(), description);
            const layout = await menu.evaluate(menu => {
                const field = menu.querySelector('[data-context-image-alt-input]');
                const rect = menu.getBoundingClientRect();
                return {width: rect.width, left: rect.left, right: rect.right, bottom: rect.bottom,
                    fieldWidth: field.clientWidth, scrollWidth: field.scrollWidth, fieldHeight: field.clientHeight};
            });
            assert.ok(layout.width <= 450 && layout.left >= 10 && layout.right <= width - 10,
                `The image panel is compact and stays inside ${width}px: ${JSON.stringify(layout)}`);
            assert.ok(layout.bottom <= 890 && layout.fieldHeight >= 70);
            assert.ok(layout.scrollWidth <= layout.fieldWidth + 1, 'Long descriptions wrap instead of scrolling horizontally.');
            const retina = menu.getByRole('switch', {name: 'Для Ретины', exact: true});
            assert.equal(await retina.isChecked(), false);
            const historyLength = () => page.evaluate(() => window.editorTest.editorStates
                .get(document.querySelector('.post-card.is-editing')).history.length);
            const before = await historyLength();
            await retina.check();
            assert.equal(await image.getAttribute('width'), '400');
            assert.equal(await image.getAttribute('height'), '250');
            assert.match(await menu.locator('[data-context-image-dimensions]').textContent(), /400\s*×\s*250/u);
            assert.equal(await historyLength(), before + 1, 'Changing display size is one undo step.');
            await page.keyboard.press('Escape');
            if (width === 1360) {
                const body = page.locator('.post-card.is-editing [data-post-inplace-body]');
                const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
                await body.press(`${modifier}+z`);
                assert.equal(await image.getAttribute('width'), '801');
                assert.equal(await image.getAttribute('height'), '501');
                await body.press(`${modifier}+Shift+z`);
                assert.equal(await image.getAttribute('width'), '400');
                assert.equal(await image.getAttribute('height'), '250');
            }
            await open();
            assert.equal(await retina.isChecked(), true, 'Reopening recognizes an image already displayed at half its file size.');
            await retina.uncheck();
            assert.equal(await image.getAttribute('width'), '801');
            assert.equal(await image.getAttribute('height'), '501');
            await retina.focus();
            await page.keyboard.press('Space');
            assert.equal(await image.getAttribute('width'), '400');
            assert.equal(await image.getAttribute('height'), '250');
            const changedAlt = 'Новое описание, которое сохраняется вместе с размерами изображения.';
            await alt.fill(changedAlt);
            if (process.env.EDITOR_IMAGE_SCREENSHOTS) {
                await mkdir(process.env.EDITOR_IMAGE_SCREENSHOTS, {recursive: true});
                await menu.screenshot({path: join(process.env.EDITOR_IMAGE_SCREENSHOTS, `${browser.browserType().name()}-${width}.png`)});
            }
            await page.keyboard.press('Control+s');
            await page.waitForFunction(() => !document.querySelector('.post-card.is-editing'));
            assert.ok(savedBody?.includes('width="400" height="250"'));
            assert.ok(savedBody.includes(changedAlt));
            assert.ok(savedBody.includes('Before image') && savedBody.includes('After image'));
            assert.doesNotMatch(savedBody, /post-editor-image|data-context-image/u);
            await page.getByRole('button', {name: 'Edit', exact: true}).click();
            await image.evaluate(image => image.decode());
            await open();
            assert.equal(await retina.isChecked(), true, 'Saving and reopening preserve the display-size choice.');
            assert.equal(await alt.inputValue(), changedAlt);
            assert.equal(await image.getAttribute('src'), '/image-properties.svg', 'Display-size changes never replace or reupload the file.');
            assert.deepEqual(errors, []);
        } finally {
            await context.close();
        }
    }
    console.log(`${browser.browserType().name()}: compact image properties, wrapped alt, reversible Retina dimensions, keyboard and save/reopen passed`);
}
