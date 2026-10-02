import assert from 'node:assert/strict';
import {chromium, firefox, webkit} from 'playwright';
import {spawn, execFileSync} from 'node:child_process';
import {mkdtemp, readFile, writeFile, rm} from 'node:fs/promises';
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

async function runMediaBoundarySaveRegression(page, browserName) {
    await page.locator('.post-create-start').click();
    let card = page.locator('[data-post-creating]');
    await card.locator('[data-post-inplace-title]').fill(`Media boundary ${browserName}`);
    let body = card.locator('[data-post-inplace-body]');
    await body.focus();
    await body.click();
    const png = await page.evaluate(() => {
        const canvas = document.createElement('canvas');
        canvas.width = 360;
        canvas.height = 180;
        const drawing = canvas.getContext('2d');
        drawing.fillStyle = '#567';
        drawing.fillRect(0, 0, 360, 180);
        drawing.fillStyle = '#fff';
        drawing.font = '20px sans-serif';
        drawing.fillText('Editor image fixture', 20, 90);
        return canvas.toDataURL('image/png').split(',')[1];
    });
    await card.getByRole('button', {name: 'Editor tools', exact: true}).click();
    const chooserPromise = page.waitForEvent('filechooser');
    await page.locator('.post-editor-context-menu [data-context-action="media"]').click();
    await (await chooserPromise).setFiles({name: 'editor-fixture.png', mimeType: 'image/png', buffer: Buffer.from(png, 'base64')});
    const media = card.locator('.post-media-picture:not(.is-processing)');
    await media.waitFor();
    await media.locator('img').evaluate(image => image.decode());
    const caption = media.locator('.post-caption');
    await caption.click();
    await page.keyboard.type('Caption entered in the editor.');
    await page.keyboard.press('Enter');
    await page.keyboard.type('Paragraph after the image.');
    await page.keyboard.press('Enter');
    await page.keyboard.type('Final paragraph after the image.');
    const measurements = async () => {
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        return body.evaluate(body => {
            const media = body.querySelector('.post-media-picture');
            const image = media.querySelector('img').getBoundingClientRect();
            return {top: image.top + scrollY,
                gap: media.querySelector('.post-caption').getBoundingClientRect().top - image.bottom,
                line: parseFloat(getComputedStyle(body).lineHeight),
                paragraphs: Array.from(body.querySelectorAll(':scope > p'), paragraph => ({
                    text: paragraph.textContent, classes: paragraph.className,
                    height: paragraph.getBoundingClientRect().height,
                    margin: getComputedStyle(paragraph).marginBottom,
                }))};
        });
    };
    const original = await measurements();
    // Put a real keyboard caret before this leading image first. Clicking
    // inside an image alone may keep an earlier text caret instead of creating
    // a new one; it must not make this test type at that unrelated old offset.
    const documentStart = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta+ArrowUp' : 'Control+Home');
    await body.press(documentStart);
    await media.locator('img').scrollIntoViewIfNeeded();
    const bounds = await media.locator('img').boundingBox();
    await page.mouse.click(bounds.x + 1, bounds.y + 1);
    if (await page.evaluate(() => !getSelection().getRangeAt(0).collapsed)) {
        await page.keyboard.press('ArrowLeft');
    }
    const caret = await body.evaluate(body => {
        const range = getSelection().getRangeAt(0);
        const prefix = range.cloneRange();
        prefix.setStart(body, 0);
        return {before: range.collapsed && body.contains(range.startContainer)
                && !prefix.cloneContents().querySelector('img'),
            collapsed: range.collapsed, start: range.startContainer.nodeName,
            parent: range.startContainer.parentElement?.className, offset: range.startOffset,
            selected: Array.from(range.cloneContents().childNodes, node => node.nodeName),
            html: body.innerHTML.replace(/src="[^"]*"/g, 'src="fixture"')};
    });
    assert.equal(caret.before, true, `Real keyboard/pointer actions put the body caret at the leading image edge: ${JSON.stringify(caret)}`);
    await page.keyboard.press('Enter');
    const first = await measurements();
    await page.keyboard.press('Enter');
    const second = await measurements();
    assert.ok(Math.abs(second.top - first.top - second.line) < 1,
        `The second real Enter moves the uploaded image by one line, without an extra paragraph gap: ${JSON.stringify({first, second})}`);
    await page.keyboard.type('Paragraph before the image.');
    assert.ok(Math.abs((await measurements()).gap - original.gap) < 1,
        'Typing at the leading image edge must not move its caption');
    const expectedText = ['Paragraph before the image.', 'Caption entered in the editor.',
        'Paragraph after the image.', 'Final paragraph after the image.'];
    const savedResponse = page.waitForResponse(async response => response.request().method() === 'POST'
        && new URL(response.url()).pathname === '/_inplace/post/new'
        && (await response.json()).action === 'create');
    await card.locator('.post-edit-save').click();
    const savedPost = await (await savedResponse).json();
    assert.equal(savedPost.success, true);
    const postId = Number(savedPost.id);
    assert.ok(Number.isInteger(postId) && postId > 0, `Creation returns a post ID: ${JSON.stringify(savedPost)}`);
    card = page.locator(`.post-card[data-post-id="${postId}"]`);
    body = card.locator('[data-post-inplace-body]');
    await page.waitForFunction(id => !document.querySelector(`.post-card[data-post-id="${id}"]`)?.classList.contains('is-editing'), postId);
    const stored = database(`SELECT body FROM content WHERE id=${postId}`)[0].body;
    for (const text of expectedText) assert.ok(stored.includes(text), `The database retains ${text}`);
    assert.equal(/post-editor-(body|empty)-paragraph/.test(stored), false, 'Editor-only spacing classes never enter stored content');
    await page.reload();
    await card.locator('.post-edit-start').focus();
    await card.locator('.post-edit-start').press('Enter');
    await card.locator('.post-media-picture img').evaluate(image => image.decode());
    assert.equal(await card.locator('.post-media-picture').count(), 1);
    assert.equal(await card.locator('.post-media-picture > .post-caption').textContent(), expectedText[1]);
    const reopenedText = (await body.textContent()).replace(/\s+/g, ' ');
    for (const text of expectedText) assert.ok(reopenedText.includes(text), `Reopening retains ${text}`);
    assert.ok(Math.abs((await measurements()).gap - original.gap) < 1, 'The caption stays attached after a real reload');
    await card.locator('.post-edit-save').click();
    await page.waitForFunction(id => !document.querySelector(`.post-card[data-post-id="${id}"]`)?.classList.contains('is-editing'), postId);
    assert.equal(database(`SELECT body FROM content WHERE id=${postId}`)[0].body, stored,
        'A second save after reopening leaves all text, blank lines, image and caption unchanged');
    console.log('media E2E: real upload, edge click, repeated Enter, typing, save/reload/reopen and second save retain the entire post');
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
        const context = await browser.newContext();
        // Uploads stay in disposable storage outside the document root. Serve
        // those actual saved files to the browser; upload/save requests still
        // reach the real PHP application and database without a mock response.
        await context.route('**/_e2e_media/**', async route => {
            const name = decodeURIComponent(new URL(route.request().url()).pathname).slice('/_e2e_media/'.length);
            const file = resolve(scratch, 'media', name);
            assert.ok(file.startsWith(resolve(scratch, 'media') + '/'), 'Only disposable uploaded files may be served');
            const contentType = file.endsWith('.webp') ? 'image/webp' : /\.jpe?g$/.test(file) ? 'image/jpeg' : 'image/png';
            await route.fulfill({status: 200, contentType, body: await readFile(file)});
        });
        // Playwright's serviceWorkers:'block' init script reads this property
        // unguarded in every frame, which throws in an opaque HTML preview.
        // Keep worker registration disabled without weakening the sandbox.
        await context.addInitScript(() => {
            try {
                if (navigator.serviceWorker) navigator.serviceWorker.register = async () => {
                    throw new Error('Service workers are disabled in the editor E2E fixture');
                };
            } catch (_) { /* Sandboxed frames intentionally cannot access service workers. */ }
        });
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

            // Exercise the real server-rendered toolbar button and resources,
            // not only the isolated browser fixture's copy of the controls.
            const editorTools = savedCard.getByRole('button', {name: 'Editor tools', exact: true});
            for (const width of [320, 390]) {
                await page.setViewportSize({width, height: 844});
                const geometry = await savedCard.evaluate(card => {
                    const tools = card.querySelector('.post-inplace-tools').getBoundingClientRect();
                    const time = card.querySelector('.post.time time').getBoundingClientRect();
                    const calendar = card.querySelector('.post-inplace-date-button').getBoundingClientRect();
                    const overlaps = rect => rect.left < tools.right && rect.right > tools.left
                        && rect.top < tools.bottom && rect.bottom > tools.top;
                    return {clear: !overlaps(time) && !overlaps(calendar),
                        tools: tools.toJSON(), time: time.toJSON(), calendar: calendar.toJSON(),
                        field: card.querySelector('.post.time').getBoundingClientRect().toJSON(),
                        padding: getComputedStyle(card.querySelector('.post.time')).paddingRight,
                        shrink: getComputedStyle(card.querySelector('.post.time time')).flexShrink,
                        minWidth: getComputedStyle(card.querySelector('.post.time time')).minWidth};
                });
                if (!geometry.clear) await page.screenshot({path: resolve(directory, `../../_output/editor-e2e-${engine.name()}-${width}px.png`)});
                assert.equal(geometry.clear, true, `Mobile editor controls leave room for the publication date at ${width}px: ${JSON.stringify(geometry)}`);
            }
            await editorTools.click();
            await page.getByRole('menu', {name: 'Editor context menu'}).waitFor();
            assert.equal(await editorTools.getAttribute('aria-expanded'), 'true');
            await page.getByRole('button', {name: 'Close editor menu', exact: true}).click();
            assert.equal(await page.locator('.post-editor-context-menu').count(), 0);
            assert.equal(await editorTools.getAttribute('aria-expanded'), 'false');
            await page.setViewportSize({width: 1280, height: 720});

            await savedCard.locator('[data-post-inplace-body]').fill('Updated through the real server.');
            await savedCard.locator('[data-post-inplace-body]').press('End');
            await editorTools.click();
            await page.locator('.post-editor-context-menu [data-context-action="html"]').click();
            const htmlBlock = savedCard.locator('[data-post-html-source]');
            assert.equal(await htmlBlock.locator('iframe').isVisible(), false);
            const htmlSource = '<p id="e2e-html-content">Содержимое HTML-вставки</p>';
            await htmlBlock.locator('textarea').fill(htmlSource);
            await savedCard.frameLocator('.post-html-block-preview').locator('#e2e-html-content').waitFor();
            assert.equal(await savedCard.frameLocator('.post-html-block-preview').locator('#e2e-html-content').textContent(), 'Содержимое HTML-вставки');
            const panelTheme = await htmlBlock.evaluate(element => ({background: getComputedStyle(element).backgroundColor,
                colorScheme: getComputedStyle(element).colorScheme}));
            assert.deepEqual(await savedCard.frameLocator('.post-html-block-preview').locator('html').evaluate(element => ({
                background: getComputedStyle(element).backgroundColor, colorScheme: getComputedStyle(element).colorScheme,
            })), panelTheme, 'The real preview endpoint receives the editor theme');
            await htmlBlock.getByRole('button', {name: 'Done', exact: true}).click();
            await savedCard.locator('.post-edit-save').click();
            await page.waitForFunction(id => !document.querySelector(`.post-card[data-post-id="${id}"]`)?.classList.contains('is-editing'), postId);
            const savedBody = database(`SELECT body FROM content WHERE id=${postId}`)[0].body;
            assert.match(savedBody, /Updated through the real server/u);
            assert.ok(savedBody.includes(htmlSource), 'The real save retains the exact HTML source and surrounding text');
            assert.ok(!/post-html-block-code|post-html-block-tools|post-html-block-preview/.test(savedBody));
            await page.reload();
            assert.match(await page.locator(`.post-card[data-post-id="${postId}"] [data-post-inplace-body]`).textContent(), /Updated through the real server/u);
            assert.equal(await savedCard.locator('[data-post-html-source]').getAttribute('data-post-html-source'), htmlSource);

            await runMediaBoundarySaveRegression(page, engine.name());

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
