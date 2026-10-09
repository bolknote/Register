import assert from 'node:assert/strict';

export async function runTelegramStickerRegressions(browser, origin) {
    const page = await browser.newPage();
    const errors = [];
    const requests = [];
    page.on('pageerror', error => errors.push(String(error)));
    page.on('request', request => requests.push(request.url()));
    const markup = '<span class="comment-sticker" data-animation="/_pictures/fixture/comments/telegram/123/2/01-0123456789abcdef0123.json">🙂</span>';
    try {
        await page.goto(origin + '/telegram-stickers.html');
        assert.equal(requests.some(url => url.endsWith('/sticker-renderer.js')), false);
        await page.evaluate(html => document.querySelector('#comments').insertAdjacentHTML('beforeend', html), markup);
        await page.waitForFunction(() => document.querySelector('.comment-sticker canvas')?.width > 0);
        const image = await page.locator('canvas').evaluate(canvas => canvas.toDataURL());
        await page.waitForFunction(before => document.querySelector('canvas').toDataURL() !== before, image);
        await page.emulateMedia({reducedMotion: 'reduce'});
        await page.waitForTimeout(100);
        const still = await page.locator('canvas').evaluate(canvas => canvas.toDataURL());
        await page.waitForTimeout(250);
        assert.equal(await page.locator('canvas').evaluate(canvas => canvas.toDataURL()), still);
        await page.evaluate(html => document.querySelector('#comments').insertAdjacentHTML('beforeend', html), markup);
        await page.waitForFunction(() => document.querySelectorAll('.comment-sticker canvas').length === 2);
        assert.equal(requests.filter(url => url.endsWith('/sticker-renderer.js')).length, 1);
        await page.evaluate(() => document.querySelector('#comments').replaceChildren());
        assert.deepEqual(errors, []);
        console.log('Telegram stickers: lazy canvas playback, inserted comments and reduced motion');
    } finally {
        await page.close();
    }
}
