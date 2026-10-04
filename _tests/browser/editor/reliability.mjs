import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';
import {runAdminRecoveryPresenceRegressions} from './admin-recovery-presence-tests.mjs';
import {runAdminAsyncDeadlineRegressions} from './admin-async-deadline-tests.mjs';
import {runHtmlContentLayoutRegressions} from './html-content-layout-tests.mjs';
import {runExpiredMediaRecoveryRegressions} from './expired-media-recovery-tests.mjs';
import {runPublicRecoveryWarningRegressions, runHtmlReloadLifecycleRegressions} from './review-followup-tests.mjs';
import {runNavigationRecoveryRegressions} from './navigation-recovery-tests.mjs';

const engines = [chromium, firefox, webkit];
const selectedEngine = process.env.EDITOR_TEST_BROWSER;
if (selectedEngine && !engines.some(engine => engine.name() === selectedEngine)) {
    throw new Error(`Unknown editor browser: ${selectedEngine}`);
}
const server = createFixtureServer();
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
try {
    for (const engine of engines.filter(engine => !selectedEngine || engine.name() === selectedEngine)) {
        const browser = await engine.launch();
        try {
            console.log(`${engine.name()} editor reliability regressions`);
            await runAdminRecoveryPresenceRegressions(browser, origin);
            await runAdminAsyncDeadlineRegressions(browser, origin);
            await runHtmlContentLayoutRegressions(browser, origin);
            await runExpiredMediaRecoveryRegressions(browser, origin);
            await runPublicRecoveryWarningRegressions(browser, origin);
            await runHtmlReloadLifecycleRegressions(browser, origin);
            await runNavigationRecoveryRegressions(browser, origin);
        } finally { await browser.close(); }
    }
} finally { await new Promise(resolve => server.close(resolve)); }
