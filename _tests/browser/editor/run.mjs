import {chromium, firefox} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {runRecoveryRegressions} from './recovery-tests.mjs';

const server = createFixtureServer();
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
try {
    for (const engine of [chromium, firefox]) {
        const browser = await engine.launch();
        try {
            for (const fixture of ['/', '/comment.html']) {
                const page = await browser.newPage();
                const errors = [];
                page.on('pageerror', error => errors.push(String(error)));
                await page.goto(`http://127.0.0.1:${server.address().port}${fixture}`);
                await page.getByRole('button', {name: 'Run regressions', exact: true}).click();
                await page.locator('#results[data-finished="true"]').waitFor({timeout: 30000});
                const results = await page.locator('#results').textContent();
                console.log(`${engine.name()} ${fixture}\n${results}`);
                if (await page.locator('#results').getAttribute('data-failed') !== '0' || errors.length > 0) {
                    throw new Error(`${engine.name()} ${fixture} regressions failed.\n${errors.join('\n')}`);
                }
                await page.close();
            }
            await runRecoveryRegressions(browser, `http://127.0.0.1:${server.address().port}`);
        } finally {
            await browser.close();
        }
    }
} finally {
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
}
