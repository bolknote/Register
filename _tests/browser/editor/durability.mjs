import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {runDurabilityRegressions} from './durability-tests.mjs';

const server = createFixtureServer();
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
try {
    for (const engine of [chromium, firefox, webkit]) {
        const browser = await engine.launch();
        try {
            console.log(engine.name());
            await runDurabilityRegressions(browser, `http://127.0.0.1:${server.address().port}`);
        } finally { await browser.close(); }
    }
} finally { await new Promise(resolve => server.close(resolve)); }
