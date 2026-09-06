import {chromium, firefox} from 'playwright';
import {createFixtureServer} from './server.mjs';

const server = createFixtureServer();
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
try {
    for (const engine of [chromium, firefox]) {
        const browser = await engine.launch();
        try {
            const page = await browser.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(String(error)));
            await page.goto(`http://127.0.0.1:${server.address().port}/`);
            await page.getByRole('button', {name: 'Run regressions', exact: true}).click();
            await page.locator('#results[data-finished="true"]').waitFor({timeout: 30000});
            const results = await page.locator('#results').textContent();
            console.log(`${engine.name()}\n${results}`);
            if (await page.locator('#results').getAttribute('data-failed') !== '0' || errors.length > 0) {
                throw new Error(`${engine.name()} editor regressions failed.\n${errors.join('\n')}`);
            }
        } finally {
            await browser.close();
        }
    }
} finally {
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
}
