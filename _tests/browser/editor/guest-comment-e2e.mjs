import assert from 'node:assert/strict';
import {chromium, firefox, webkit} from 'playwright';
import {spawn, execFileSync} from 'node:child_process';
import {mkdtemp, readFile, writeFile, unlink} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {resolve, dirname, relative} from 'node:path';
import {fileURLToPath} from 'node:url';
import {createServer} from 'node:net';

const directory = dirname(fileURLToPath(import.meta.url));
const sourceRoot = resolve(directory, '../../..');
const root = resolve(process.env.REGISTER_E2E_ROOT || sourceRoot);
const scratch = await mkdtemp(resolve(tmpdir(), 'register-guest-comment-e2e-'));
const id = scratch.split('-').at(-1);
const config = resolve(root, `config.e2e-${id}.php`);
const php = process.env.PHP_BIN || 'php';
const reservation = createServer();
await new Promise(done => reservation.listen(0, '127.0.0.1', done));
const port = reservation.address().port;
await new Promise(done => reservation.close(done));
const origin = `http://127.0.0.1:${port}`;
const article = origin + '/guest-comment-fixture';
const mailLog = resolve(scratch, 'mail.jsonl');
const dbFile = resolve(scratch, 'site.sqlite');
const settings = resolve(scratch, 'settings.json');
await writeFile(settings, JSON.stringify({scratch, id, config, origin, root, database: relative(root, dbFile)}));
const database = (sql, parameters = []) => JSON.parse(execFileSync(php, ['-r',
    '$db = new PDO("sqlite:" . $argv[1]); $s = $db->prepare($argv[2]); $s->execute(json_decode($argv[3], true)); echo json_encode($s->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);',
    dbFile, sql, JSON.stringify(parameters)], {encoding: 'utf8'}));
let server;
let serverLog = '';
let serverError;

async function runReplyJourney(browser, javaScriptEnabled) {
    const context = await browser.newContext({javaScriptEnabled, reducedMotion: 'reduce'});
    // Exclude cross-document animation timing from the no-JS form checks,
    // just as in the guest submission journey below.
    await context.route('**/*', async route => {
        if (route.request().resourceType() !== 'stylesheet') return route.continue();
        const response = await route.fetch();
        await route.fulfill({response, body: await response.text() + '\n@view-transition { navigation: none; }'});
    });
    const page = await context.newPage();
    const parent = database("SELECT id FROM comments WHERE nick='Reply fixture parent'")[0].id;
    const count = () => database('SELECT COUNT(*) AS count FROM comments')[0].count;
    const before = count();
    try {
        await page.goto(article);
        const reply = page.locator(`button.comment-reply[data-reply-comment="${parent}"]`);
        assert.equal(await page.locator('a.comment-reply').count(), 0);
        assert.equal(await reply.getAttribute('type'), 'submit');
        assert.equal(await reply.evaluate(button => getComputedStyle(button).minHeight), '0px', 'Reply is a compact text action');
        if (javaScriptEnabled) {
            await page.locator('#comment-form .comment-editor-surface').fill('Preserved reply draft');
            await reply.click();
            assert.equal(await page.locator('#comment-form .comment-editor-surface').textContent(), 'Preserved reply draft');
        } else {
            await Promise.all([page.waitForNavigation(), reply.click()]);
            assert.equal(await page.locator('.comment-reply-target').textContent(), 'Reply fixture parent');
        }
        assert.equal(await page.locator('.comment-parent-id').inputValue(), String(parent));
        assert.equal(new URL(page.url()).search, '', 'Reply selection never creates query URLs');
        assert.equal(count(), before, 'Selecting a reply is read-only');
        const cancel = page.locator('.comment-reply-cancel');
        if (javaScriptEnabled) {
            await cancel.click();
            assert.equal(await page.locator('#comment-form .comment-editor-surface').textContent(), 'Preserved reply draft');
        } else {
            await Promise.all([page.waitForNavigation(), cancel.click()]);
        }
        assert.equal(await page.locator('.comment-parent-id').inputValue(), '');
        const old = await context.request.get(article + '?reply_to=' + parent);
        assert.equal(old.status(), 404);
        assert.equal(await old.text(), '404 Not Found');
        assert.match(old.headers()['content-type'], /^text\/plain/);
        const head = await context.request.head(article + '?reply_name=Reader');
        assert.equal(head.status(), 404);
        assert.equal(await head.text(), '');
        console.log(`Reply E2E ${browser.browserType().name()}-${javaScriptEnabled ? 'js' : 'no-js'}: button, addressee, cancellation, no query URL and old URL 404`);
    } finally { await context.close(); }
}

async function confirmationUrl() {
    const messages = (await readFile(mailLog, 'utf8')).trim().split('\n').map(line => JSON.parse(line));
    const mime = messages.at(-1).replace(/=\r?\n/g, '').replace(/=([A-Fa-f0-9]{2})/g, (_, hex) => String.fromCharCode(parseInt(hex, 16)));
    const match = mime.match(/https?:\/\/[^\s<>]+\/auth\/email\/callback\?token=[A-Za-z0-9_-]+&draft=[a-f0-9]{32}/u);
    assert.ok(match, 'The real captured confirmation email contains a link and recovery reference');
    return match[0];
}

async function runGuestJourney(browser, javaScriptEnabled) {
    // Each guest journey is independent; rapid loopback tests must not hit the shared IP limit.
    database('DELETE FROM spam_rate_events');
    const context = await browser.newContext({javaScriptEnabled, reducedMotion: 'reduce'});
    // Native cross-document transitions still run with JavaScript disabled and
    // can swallow the next automated click. This suite tests forms and recovery,
    // not animation timing; keep real styles and disable only that animation.
    await context.route('**/*', async route => {
        if (route.request().resourceType() !== 'stylesheet') return route.continue();
        const response = await route.fetch();
        await route.fulfill({response, body: await response.text() + '\n@view-transition { navigation: none; }'});
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    page.setDefaultTimeout(15000);
    const label = `${browser.browserType().name()}-${javaScriptEnabled ? 'js' : 'no-js'}`;
    console.log(`Checking guest comment E2E ${label}`);
    const email = label + '@examplw.test';
    const correctedEmail = label + '@example.test';
    const text = 'First paragraph. ' + 'Long guest comment text must remain available. '.repeat(90) + 'Last paragraph.';
    const storedDraft = () => database('SELECT comment_text FROM auth_magic_links WHERE email=?', [email])[0].comment_text;
    try {
        await page.goto(article);
        if (!javaScriptEnabled) assert.equal(await page.locator('.comment-editor-surface').isVisible(), false);
        const form = page.locator('#comment-form');
        const editor = page.locator(javaScriptEnabled ? '#comment-form .comment-editor-surface' : '#comment-form .comment-editor-source');
        await editor.fill(text);
        await page.locator('#comment-name').fill('Guest ' + label);
        // The first submit has no email. Validation must keep the entire long comment.
        await Promise.all([page.waitForNavigation(), form.locator('.comment-submit').click()]);
        assert.equal((await editor.textContent() || await editor.inputValue()).replace(/\s+/g, ' ').trim(), text);
        await page.locator('#comment-email').fill(email);
        const storageKey = javaScriptEnabled ? 'comment_text_' + await form.locator('.comment-form-id').inputValue() : null;
        const invalidFields = await form.evaluate(form => Array.from(form.elements).filter(element => element.willValidate && !element.validity.valid)
            .map(element => ({name: element.name, type: element.type, message: element.validationMessage, length: element.value.length})));
        assert.deepEqual(invalidFields, [], 'Native form constraints allow the corrected guest submission');
        await Promise.all([page.waitForNavigation(), form.locator('.comment-submit').click()]);
        const waitingUrl = page.url();
        const firstLink = await confirmationUrl();
        const originalHtml = storedDraft();
        assert.equal((await page.locator('.pending-comment-preview').textContent()).replace(/\s+/g, ' ').trim(), text);
        assert.equal(await page.locator('.pending-comment-email-form [name="email"]').inputValue(), email);
        if (javaScriptEnabled) {
            await page.screenshot({path: resolve(scratch, `waiting-${label}.png`)});
            await page.setViewportSize({width: 390, height: 844});
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'The pending comment and form fit on a phone');
            await page.screenshot({path: resolve(scratch, `waiting-mobile-${label}.png`)});
            await page.setViewportSize({width: 1280, height: 720});
        }
        await page.reload();
        assert.equal((await page.locator('.pending-comment-preview').textContent()).replace(/\s+/g, ' ').trim(), text);
        if (javaScriptEnabled) {
            await Promise.all([page.waitForURL(article), page.getByRole('link', {name: 'Return to the article', exact: true}).click()]);
            assert.equal((await editor.textContent()).replace(/\s+/g, ' ').trim(), text,
                'Partial navigation restores the visible rich editor, not just its hidden textarea');
            await page.goto(waitingUrl);
        }

        // An old expired auth challenge must be cleaned up, but not its pending comment.
        database('UPDATE auth_magic_links SET expires_at=1 WHERE email=?', [email]);
        const expired = await page.goto(firstLink);
        assert.equal(expired.status(), 410);
        assert.equal((await page.locator('.pending-comment-preview').textContent()).replace(/\s+/g, ' ').trim(), text);
        await page.locator('.pending-comment-email-form [name="email"]').fill(correctedEmail);
        await Promise.all([page.waitForNavigation(), page.locator('.pending-comment-email-form button[type="submit"]').click()]);
        const correctedLink = await confirmationUrl();
        assert.notEqual(correctedLink, firstLink);
        assert.equal(await page.locator('.pending-comment-email-form [name="email"]').inputValue(), correctedEmail);
        assert.equal(database('SELECT comment_text FROM auth_magic_links WHERE email=?', [correctedEmail])[0].comment_text, originalHtml);
        const stranger = await browser.newContext({javaScriptEnabled: false});
        try {
            const otherPage = await stranger.newPage();
            assert.equal((await otherPage.goto(waitingUrl)).status(), 404);
            assert.equal(await otherPage.locator('.pending-comment-preview').count(), 0,
                'The recovery URL alone cannot expose the comment or email to another browser');
        } finally { await stranger.close(); }
        assert.equal((await page.goto(firstLink)).status(), 410, 'The old address cannot confirm the comment after correction');
        assert.equal(database('SELECT COUNT(*) AS count FROM comments WHERE email=?', [correctedEmail])[0].count, 0);
        await page.goto(correctedLink);
        const comments = database('SELECT text, shown FROM comments WHERE email=?', [correctedEmail]);
        assert.equal(comments.length, 1);
        assert.equal(comments[0].text, originalHtml, 'Confirmation stores every character of the original sanitized HTML');
        assert.equal(comments[0].shown, 0, 'A new guest is still held for normal moderation');
        if (javaScriptEnabled) {
            assert.equal(await page.evaluate(key => localStorage.getItem(key), storageKey), null,
                'Only confirmed submission clears the browser draft');
            assert.equal(await page.locator('#comment-form .comment-editor-surface').textContent(), '');
        }
        await page.goto(correctedLink);
        assert.equal(database('SELECT COUNT(*) AS count FROM comments WHERE email=?', [correctedEmail])[0].count, 1,
            'Reopening the same email cannot create a duplicate comment');
        assert.deepEqual(errors, []);
        console.log(`Guest comment E2E ${label}: long text, missing/typo email, reload, expiry, correction, browser ownership, exact confirmation and no duplicates`);
    } catch (error) {
        await page.screenshot({path: resolve(scratch, `failed-${label}.png`)});
        console.error(label, await page.locator('#comment-form').evaluateAll(forms => forms.map(form => ({
            invalid: Array.from(form.elements).filter(element => element.willValidate && !element.validity.valid)
                .map(element => ({name: element.name, type: element.type, message: element.validationMessage})),
            action: form.action, method: form.method,
        }))));
        console.error('Screenshot:', resolve(scratch, `failed-${label}.png`));
        throw error;
    } finally { await context.close(); }
}

try {
    const {pageId} = JSON.parse(execFileSync(php, [resolve(directory, 'e2e-seed.php'), settings],
        {cwd: root, encoding: 'utf8', env: {...process.env, XDEBUG_MODE: 'off'}}));
    for (const [name, value] of Object.entries({REGISTER_AUTH_EMAIL_ENABLED: '1', REGISTER_MAIL_TRANSPORT: 'php_mail',
        REGISTER_MAIL_FROM_EMAIL: 'fixture@example.test', REGISTER_MAIL_ENVELOPE_EMAIL: 'fixture@example.test'})) {
        database('INSERT INTO config (name,value) VALUES (?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value', [name, value]);
    }
    database("INSERT INTO content (content_type,parent_id,slug_scope,title,excerpt,body,created_at,published_at,updated_at,revision,sort_order,published,featured,comments_enabled,slug,template) VALUES ('page',?,'root','Guest comment fixture','','<p>Page text</p>',?,?,?,1,0,1,0,1,'guest-comment-fixture','site.php')", [pageId, ...Array(3).fill(Math.floor(Date.now() / 1000))]);
    const fixturePage = database("SELECT id FROM content WHERE slug='guest-comment-fixture'")[0].id;
    database("INSERT INTO comments (content_type,content_id,time,nick,email,text,shown) VALUES ('page',?,?,'Reply fixture parent','','Visible reply parent',1)", [fixturePage, Math.floor(Date.now() / 1000)]);
    try { await unlink(resolve(scratch, 'cache/register_config.php')); } catch (error) { if (error.code !== 'ENOENT') throw error; }
    const quote = argument => "'" + argument.replace(/'/g, "'\\''") + "'";
    const sendmail = [php, resolve(directory, 'e2e-mail.php'), mailLog].map(quote).join(' ');
    server = spawn(php, ['-d', `sendmail_path=${sendmail}`, '-d', `session.save_path=${scratch}/sessions`, '-d', 'opcache.revalidate_freq=0',
        '-S', `127.0.0.1:${port}`, '-t', root, resolve(sourceRoot, 'tools/dev-router.php')],
        {cwd: root, env: {...process.env, APP_ENV: `e2e-${id}`, XDEBUG_MODE: 'off'}, stdio: ['ignore', 'pipe', 'pipe']});
    server.stdout.on('data', data => { serverLog += data; });
    server.stderr.on('data', data => { serverLog += data; });
    server.on('error', error => { serverError = error; });
    for (let attempt = 0; ; attempt++) {
        if (serverError) throw serverError;
        if (server.exitCode !== null || server.signalCode !== null) throw new Error('PHP exited: ' + serverLog);
        try { if ((await fetch(origin, {signal: AbortSignal.timeout(2000)})).ok) break; } catch (_) { /* Server is starting. */ }
        if (attempt > 100) throw new Error('The disposable PHP server did not start: ' + serverLog);
        await new Promise(done => setTimeout(done, 100));
    }
    for (const engine of [chromium, firefox, webkit]) {
        const browser = await engine.launch();
        try {
            await runReplyJourney(browser, true);
            await runReplyJourney(browser, false);
            await runGuestJourney(browser, true);
            await runGuestJourney(browser, false);
        } finally { await browser.close(); }
    }
} catch (error) {
    console.error(error.stack || String(error));
    console.error(serverLog.slice(-1500));
    process.exitCode = 1;
} finally {
    server?.kill('SIGTERM');
    if (server && server.exitCode === null && server.signalCode === null) await new Promise(done => server.once('exit', done));
    try { await unlink(config); } catch (error) { if (error.code !== 'ENOENT') throw error; }
}
