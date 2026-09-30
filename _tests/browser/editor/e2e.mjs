import assert from 'node:assert/strict';
import {chromium, firefox, webkit} from 'playwright';
import {spawn, execFileSync} from 'node:child_process';
import {mkdtemp, writeFile, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {resolve, dirname, relative} from 'node:path';
import {fileURLToPath} from 'node:url';
import {createServer} from 'node:net';

const directory = dirname(fileURLToPath(import.meta.url));
const root = resolve(directory, '../../..');
const scratch = await mkdtemp(resolve(tmpdir(), 'register-editor-e2e-'));
const id = scratch.split('-').at(-1);
const config = resolve(root, `config.e2e-${id}.php`);
const reservation = createServer();
await new Promise(resolve => reservation.listen(0, '127.0.0.1', resolve));
const port = reservation.address().port;
await new Promise(resolve => reservation.close(resolve));
const origin = `http://127.0.0.1:${port}`;
const settings = resolve(scratch, 'settings.json');
await writeFile(settings, JSON.stringify({scratch, id, config, origin, database: relative(root, resolve(scratch, 'site.sqlite'))}));
const php = process.env.PHP_BIN || 'php';
let server;
let serverLog = '';
let serverError;
const database = sql => JSON.parse(execFileSync(php, ['-r',
    '$db = new PDO("sqlite:" . $argv[1]); echo json_encode($db->query($argv[2])->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);',
    resolve(scratch, 'site.sqlite'), sql], {encoding: 'utf8'}));

async function login(page, account = 'editor') {
    await page.goto(origin + '/_admin/index.php');
    await page.locator('form[name="loginform"] [name="login"]').fill(account);
    await page.waitForFunction(() => !document.querySelector('form[name="loginform"] [name="pass"]').disabled);
    await page.locator('form[name="loginform"] [name="pass"]').fill(account + '-password');
    await page.locator('form[name="loginform"] [type="submit"]').click();
    await page.waitForFunction(() => !document.querySelector('form[name="loginform"]'));
}

try {
    const {pageId} = JSON.parse(execFileSync(php, [resolve(directory, 'e2e-seed.php'), settings], {cwd: root, encoding: 'utf8', env: {...process.env, XDEBUG_MODE: 'off'}}));
    server = spawn(php, ['-d', `session.save_path=${scratch}/sessions`, '-d', 'opcache.revalidate_freq=0',
        '-S', `127.0.0.1:${port}`, '-t', root, resolve(root, 'tools/dev-router.php')],
    {cwd: root, env: {...process.env, APP_ENV: `e2e-${id}`, XDEBUG_MODE: 'off'}, stdio: ['ignore', 'pipe', 'pipe']});
    server.stdout.on('data', data => { serverLog += data; });
    server.stderr.on('data', data => { serverLog += data; });
    server.on('error', error => { serverError = error; });
    for (let attempt = 0; ; attempt++) {
        if (serverError) throw serverError;
        if (server.exitCode !== null || server.signalCode !== null) throw new Error('PHP exited: ' + serverLog);
        try { if ((await fetch(origin, {signal: AbortSignal.timeout(2000)})).ok) break; } catch (_) { /* Server is starting. */ }
        if (attempt > 100) throw new Error('PHP did not start: ' + serverLog);
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    for (const engine of [chromium, firefox, webkit]) {
        const browser = await engine.launch();
        const context = await browser.newContext({serviceWorkers: 'block'});
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        page.setDefaultTimeout(15000);
        try {
            await login(page);
            await page.goto(origin + '/');
            await page.locator('.post-create-start').click();
            const card = page.locator('[data-post-creating]');
            const title = `Lost response ${engine.name()}`;
            await card.locator('[data-post-inplace-title]').fill(title);
            await card.locator('[data-post-inplace-body]').fill('Created once through the real server.');
            const before = database("SELECT COUNT(*) AS count FROM content WHERE content_type='post'")[0].count;
            let attempts = 0;
            const ids = [];
            await page.route('**/_inplace/post/new', async route => {
                if (route.request().method() !== 'POST') return route.continue();
                const response = await route.fetch();
                const data = await response.json();
                assert.equal(data.success, true);
                ids.push(data.id);
                if (++attempts === 1) await route.abort('failed'); // The DB commit happened; only its answer is lost.
                else await route.fulfill({response});
            });
            await card.locator('.post-edit-save').click();

            await page.waitForFunction(() => !document.querySelector('[data-post-creating]'));
            assert.equal(attempts, 2);
            assert.equal(ids[0], ids[1]);
            assert.equal(database("SELECT COUNT(*) AS count FROM content WHERE content_type='post'")[0].count, Number(before) + 1);
            await page.unroute('**/_inplace/post/new');
            const postId = ids[0];
            const savedCard = page.locator(`.post-card[data-post-id="${postId}"]`);
            await page.waitForLoadState('networkidle');
            await savedCard.locator('.post-edit-start').focus();
            await savedCard.locator('.post-edit-start').press('Enter');

            await savedCard.locator('[data-post-inplace-body]').fill('Updated through the real server.');
            await savedCard.locator('.post-edit-save').click();
            await page.waitForFunction(id => !document.querySelector(`.post-card[data-post-id="${id}"]`)?.classList.contains('is-editing'), postId);
            assert.match(database(`SELECT body FROM content WHERE id=${postId}`)[0].body, /Updated through the real server/u);
            await page.reload();
            assert.match(await page.locator(`.post-card[data-post-id="${postId}"] [data-post-inplace-body]`).textContent(), /Updated through the real server/u);

            const editUrl = `${origin}/_admin/index.php?entity=Article&action=edit&id=${pageId}`;
            await page.goto(editUrl);
            await page.waitForFunction(() => typeof window.onbeforeunload === 'function');
            await page.locator('[name="title"]').fill(`Draft page ${engine.name()}`);
            await page.evaluate(() => document.querySelector('.CodeMirror').CodeMirror.setValue('<p>Recovered page body.</p>'));
            await page.locator('.editor-tags-text-input').fill('E2E recovery');
            await page.locator('.editor-tags-text-input').press('Enter');
            await page.reload();
            await page.waitForFunction(() => typeof window.onbeforeunload === 'function');
            assert.notEqual(await page.locator('[name="title"]').inputValue(), `Draft page ${engine.name()}`);
            await page.getByRole('button', {name: 'Restore draft', exact: true}).first().click();
            assert.equal(await page.locator('[name="title"]').inputValue(), `Draft page ${engine.name()}`);
            assert.equal(await page.locator('[name="tags"]').inputValue(), 'E2E recovery');
            const revision = await page.locator('[name="revision"]').inputValue();
            await page.locator('form[name="article-form"]').evaluate(form => form.requestSubmit());
            await page.waitForFunction(revision => document.querySelector('[name="revision"]').value !== revision, revision);
            assert.equal(database(`SELECT body FROM content WHERE id=${pageId}`)[0].body, '<p>Recovered page body.</p>');
            assert.equal(database(`SELECT title FROM content WHERE id=${pageId}`)[0].title, `Draft page ${engine.name()}`);
            assert.equal(await page.evaluate(() => window.onbeforeunload()), undefined);
            assert.deepEqual(errors, []);
            console.log(`${engine.name()}: real login, lost creation response, retry, edit, reload and complete admin recovery → PHP → SQLite passed`);
        } finally { await context.close(); await browser.close(); }
    }
} catch (error) {
    await writeFile(resolve(directory, '../../_output/editor-e2e-server.log'), serverLog);
    throw error;
} finally {
    if (server && server.exitCode === null && server.signalCode === null) {
        const stopped = new Promise(resolve => server.once('exit', resolve));
        server.kill('SIGTERM');
        await stopped;
    }
    await rm(config, {force: true});
    await rm(scratch, {recursive: true, force: true});
}
