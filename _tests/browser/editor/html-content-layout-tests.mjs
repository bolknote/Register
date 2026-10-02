import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {formData} from './save-tests.mjs';

async function previewResponse(route, data) {
    const script = (await readFile(new URL('../../../_assets/register/editor/html-preview.js', import.meta.url))).toString('base64');
    const css = (await readFile(new URL('../../../_assets/register/editor/html-preview.css', import.meta.url))).toString('base64');
    const attribute = text => text.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;');
    const options = {key: data.get('html_preview_key'), theme: {font: data.get('html_font'), color: data.get('html_color'),
        backgroundColor: data.get('html_backgroundColor'), colorScheme: data.get('html_colorScheme')}};
    await route.fulfill({contentType: 'text/html', headers: {
        'Content-Security-Policy': "sandbox allow-scripts allow-forms; default-src * data: blob: 'unsafe-inline' 'unsafe-eval'; frame-ancestors 'self'",
    }, body: `<!doctype html><head><meta charset="utf-8"><link rel="stylesheet" href="data:text/css;base64,${css}"></head><body>`
        + `<script src="data:text/javascript;base64,${script}" data-preview-options="${attribute(JSON.stringify(options))}"></script>`
        + data.get('html_source')});
}

export async function runHtmlContentLayoutRegressions(browser, origin) {
    const context = await browser.newContext();
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    await page.route('**/_inplace/tags', route => route.fulfill({json: {tags: []}}));
    await page.route('**/_inplace/post/9', async route => {
        const data = await formData(route);
        assert.equal(data.get('inplace_action'), 'html_preview');
        await previewResponse(route, data);
    });
    try {
        await page.goto(origin + '/recovery.html');
        await page.getByRole('button', {name: 'Edit', exact: true}).click();
        await page.locator('[data-post-inplace-body]').click();
        await page.keyboard.press('Shift+F10');
        await page.getByRole('button', {name: 'HTML block', exact: true}).click();
        const code = page.getByRole('textbox', {name: 'HTML code', exact: true});
        const preview = page.locator('.post-html-block-preview');
        const frame = page.frameLocator('.post-html-block-preview');
        const height = () => preview.evaluate(element => element.clientHeight);
        const expectHeight = expected => page.waitForFunction(value => (
            Math.abs(document.querySelector('.post-html-block-preview').clientHeight - value) <= 1
        ), expected);

        await code.fill('<div id="positioned" style="position:absolute;top:30px;height:300px;width:100%;background:red">Positioned content</div>');
        await frame.locator('#positioned').waitFor();
        await expectHeight(330);
        assert.equal(await frame.locator('body').evaluate(element => element.getBoundingClientRect().height), 0);
        await frame.locator('#positioned').evaluate(element => { element.style.height = '500px'; });
        await expectHeight(530);
        await frame.locator('#positioned').evaluate(element => { element.style.height = '70px'; });
        await expectHeight(100);
        await page.waitForTimeout(150);
        assert.equal(await height(), 100, 'Shrinking must not retain the old iframe viewport or restart a resize loop');

        await code.fill('<div id="wrapper" style="height:20px"><div id="overflow" style="height:280px">Overflowing content</div></div>');
        await frame.locator('#overflow').waitFor();
        await expectHeight(280);
        await frame.locator('#overflow').evaluate(element => { element.style.height = '60px'; });
        await expectHeight(60);
        await frame.locator('#wrapper').evaluate(element => {
            const added = document.createElement('div');
            added.id = 'added'; added.style.cssText = 'position:absolute;top:200px;height:100px';
            element.append(added);
        });
        await expectHeight(300);
        await frame.locator('#added').evaluate(element => element.remove());
        await expectHeight(60);
        await frame.locator('#wrapper').evaluate(element => { element.style.overflow = 'hidden'; });
        await expectHeight(40);
        await frame.locator('#wrapper').evaluate(element => { element.style.overflow = 'visible'; });
        await expectHeight(60);

        const source = '<style>.sample { color: red; }</style>\n<!-- author spacing -->\n<div class="sample">HTML &amp; text</div>\n';
        await code.fill(source);
        await frame.locator('.sample').waitFor();
        await page.getByRole('button', {name: 'Done', exact: true}).click();
        await page.evaluate(() => {
            const body = document.querySelector('[data-post-inplace-body]');
            body.firstElementChild.innerHTML = 'Before<br>second <strong>line</strong>';
            body.lastElementChild.innerHTML = 'After<br>last line';
        });
        const copy = async onlyBlock => page.evaluate(blockOnly => {
            const body = document.querySelector('[data-post-inplace-body]');
            body.focus();
            const range = document.createRange();
            if (blockOnly) range.selectNode(body.querySelector('[data-post-html-source]'));
            else range.selectNodeContents(body);
            getSelection().removeAllRanges(); getSelection().addRange(range);
            const data = new DataTransfer();
            const event = new Event('copy', {bubbles: true, cancelable: true});
            Object.defineProperty(event, 'clipboardData', {value: data});
            body.dispatchEvent(event);
            return {handled: event.defaultPrevented, text: data.getData('text/plain'), html: data.getData('text/html')};
        }, onlyBlock);
        const whole = await copy(false);
        assert.equal(whole.handled, true);
        assert.equal(whole.text, `Before\nsecond line\n\n${source}\nAfter\nlast line`, 'Plain copying preserves paragraph/line separators and untouched HTML source');
        assert.ok(!/post-html-block-tools|post-html-block-preview|contenteditable/.test(whole.html));
        assert.equal((await copy(true)).text, source, 'Copying a block alone retains its exact source including the final newline');
        assert.deepEqual(errors, []);
        console.log(`${browser.browserType().name()}: HTML preview positioned/overflowing growth and shrink, DOM changes and multiline source clipboard passed`);
    } finally { await context.close(); }
}
