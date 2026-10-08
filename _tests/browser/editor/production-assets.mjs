import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {createServer} from 'node:http';
import {mkdtemp, readFile, rm, symlink, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {buildAssets, minifyAsset} from '../../../tools/assets/optimizer.mjs';

const directory = dirname(fileURLToPath(import.meta.url));
const root = resolve(directory, '../../..');
const php = process.env.PHP_BIN || 'php';
const scratch = await mkdtemp(resolve(tmpdir(), 'register-production-assets-'));

async function encodingRegression() {
    const original = Buffer.from(String.raw`.icon:before{content:"\f007"}.space:before{content:"\a0"}.literal:before{content:"\\a0"}`);
    const optimized = await minifyAsset('encoding.css', original);
    const server = createServer((request, response) => {
        if (request.url.endsWith('.css')) {
            // Deliberately omit HTTP charset and use a legacy-encoded HTML document.
            response.setHeader('Content-Type', 'text/css');
            response.end(request.url === '/original.css' ? original : optimized);
            return;
        }
        response.setHeader('Content-Type', 'text/html; charset=windows-1251');
        response.end(`<link rel="stylesheet" href="/${request.url === '/original' ? 'original' : 'optimized'}.css">
            <div class="icon"></div><div class="space"></div><div class="literal"></div>`);
    });
    await new Promise(done => server.listen(0, '127.0.0.1', done));
    const origin = `http://127.0.0.1:${server.address().port}`;
    try {
        for (const engine of [chromium, firefox, webkit]) {
            const browser = await engine.launch();
            try {
                const page = await browser.newPage();
                const contents = () => page.evaluate(() => ['icon', 'space', 'literal']
                    .map(name => getComputedStyle(document.querySelector('.' + name), '::before').content));
                await page.goto(origin + '/original');
                const before = await contents();
                await page.goto(origin + '/optimized');
                assert.deepEqual(await contents(), before, 'Minification must preserve glyphs, NBSP and literal backslashes');
                console.log(`${engine.name()}: Lightning CSS #310 regression passed without HTTP charset in windows-1251 HTML`);
            } finally { await browser.close(); }
        }
    } finally { await new Promise(done => server.close(done)); }
}

try {
    await encodingRegression();
    const distribution = resolve(scratch, 'distribution');
    execFileSync(php, ['-r',
        'require "_vendor/autoload.php"; require "tools/deployment/SharedHostingDistributionBuilder.php"; '
        + '(new Register\\Tools\\Deployment\\SharedHostingDistributionBuilder(getcwd()))->buildDirectory($argv[1], includeInstalledVendor: false);',
        distribution], {cwd: root, stdio: 'inherit'});
    const publicRoot = resolve(distribution, 'public_html');
    const report = await buildAssets(publicRoot, {onProgress(done, total) {
        if (done % 20 === 0 || done === total) console.log(`Production browser assets: ${done}/${total}`);
    }});
    await writeFile(resolve(root, '_tests/_output/asset-build-report.json'), JSON.stringify(report, null, 2) + '\n');
    console.log('Production asset totals:', JSON.stringify(report.totals));
    // The disposable fixture reuses the local Composer install; release archives never contain symlinks.
    await symlink(resolve(root, '_vendor'), resolve(publicRoot, '_vendor'), 'dir');
    for (const script of ['e2e.mjs', 'guest-comment-e2e.mjs']) {
        execFileSync(process.execPath, [resolve(directory, script)], {
            cwd: root, stdio: 'inherit', env: {...process.env, REGISTER_E2E_ROOT: publicRoot},
        });
    }
} finally {
    await rm(scratch, {recursive: true, force: true});
}
