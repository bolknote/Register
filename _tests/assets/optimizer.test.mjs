import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {mkdtemp, mkdir, readFile, rm, stat, symlink, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {resolve} from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import {brotliDecompressSync, gunzipSync, gzipSync, zstdDecompressSync} from 'node:zlib';
import {assetDirectories, buildAssets, compressAsset, manifestPath, minifyAsset} from '../../tools/assets/optimizer.mjs';

test('classic scripts keep globals, function arity, getter effects and Unicode semantics', async () => {
    const source = Buffer.from(`/* Copyright 2026 Test authors */
        var PublicValue = 42;
        function PublicApi(value, unused) { return value + PublicValue; }
        const getter = { get value() { window.effects++; return 1; } };
        window.effects = 0; getter.value;
        window.unicode = ["А😀".length, "А😀".charCodeAt(0), "А😀".slice(1)];
    `);
    const content = await minifyAsset('classic.js', source);
    assert.ok(content.length < source.length);
    assert.match(content.toString(), /Copyright 2026 Test authors/);
    const context = vm.createContext({window: {}});
    new vm.Script(content.toString()).runInContext(context);
    vm.runInContext('window.result = PublicApi(1); window.arity = PublicApi.length;', context);
    assert.equal(context.window.result, 43);
    assert.equal(context.window.arity, 2);
    assert.equal(context.window.effects, 1);
    assert.deepEqual(Array.from(context.window.unicode), [3, 1040, '😀']);
});

test('ES module imports and exported names survive independent file optimization', async () => {
    const content = await minifyAsset('_admin/js/entry.js', Buffer.from(`
        import {helper} from './dependency.js';
        export function publicMethod(value) { const calculatedValue = helper(value); return calculatedValue; }
    `));
    assert.match(content.toString(), /from["']\.\/dependency\.js["']/);
    assert.match(content.toString(), /publicMethod/);
    assert.match(content.toString(), /export/);
});

test('CSS keeps relative URLs, licenses and encoding for escaped glyphs (Lightning CSS #310)', async () => {
    const content = await minifyAsset('_assets/icons.css', Buffer.from(String.raw`
        /*! Copyright 2026 Test authors */
        .icon::before { content: "\f007"; }
        .space::before { content: "\a0"; }
        .literal::before { content: "\\a0"; }
        .background { background-image: url(../images/example.svg); }
    `));
    const css = content.toString();
    assert.ok(css.startsWith('@charset "UTF-8";'));
    assert.match(css, /Copyright 2026 Test authors/);
    assert.match(css, /\.\.\/images\/example\.svg/);
    assert.ok(css.includes('\uf007'));
    assert.ok(css.includes('\u00a0'));
    assert.match(css, /content:"\\\\a0"/);
});

test('all maximum-quality encodings round-trip and gzip never exceeds zlib level 9', async () => {
    const source = Buffer.from('window.answer = 42;\n'.repeat(80));
    const output = await compressAsset(source);
    assert.deepEqual(brotliDecompressSync(output.br), source);
    assert.deepEqual(zstdDecompressSync(output.zst), source);
    assert.deepEqual(gunzipSync(output.gz), source);
    assert.ok(output.gz.length <= gzipSync(source, {level: 9}).length);
    const repeated = await compressAsset(source);
    for (const suffix of ['br', 'zst', 'gz']) assert.deepEqual(repeated[suffix], output[suffix]);
});

async function fixture(t) {
    const root = await mkdtemp(resolve(tmpdir(), 'register-assets-regression-'));
    t.after(() => rm(root, {recursive: true, force: true}));
    for (const directory of [...assetDirectories, '_include']) await mkdir(resolve(root, directory));
    await writeFile(resolve(root, 'service-worker.js'), 'self.addEventListener("fetch", () => {});');
    return root;
}

test('a normal distribution optimizes separate assets and publishes content hashes without touching private data', async t => {
    const root = await fixture(t);
    const source = 'window.source = ' + JSON.stringify('Hello, world! '.repeat(200)) + ';\n';
    await writeFile(resolve(root, '_assets/separate.js'), source);
    await writeFile(resolve(root, '_styles/standalone.css'), '.hello { color: #ff0000; margin: 0px; }');
    await writeFile(resolve(root, '_include/private.js'), source);
    await writeFile(resolve(root, '_assets/empty.js'), '');
    const modifiedAt = (await stat(resolve(root, '_assets/separate.js'))).mtimeMs;
    const report = await buildAssets(root);
    const manifest = JSON.parse(await readFile(resolve(root, manifestPath), 'utf8'));
    const content = await readFile(resolve(root, '_assets/separate.js'));
    assert.equal(report.files.length, 4);
    assert.ok(content.length < Buffer.byteLength(source));
    assert.equal(manifest.assets['_assets/separate.js'], createHash('sha256').update(content).digest('hex'));
    assert.equal(await readFile(resolve(root, '_include/private.js'), 'utf8'), source);
    assert.ok(Math.abs((await stat(resolve(root, '_assets/separate.js'))).mtimeMs - modifiedAt) < 1);
    for (const [suffix, decoder] of Object.entries({br: brotliDecompressSync, zst: zstdDecompressSync, gz: gunzipSync})) {
        assert.deepEqual(decoder(await readFile(resolve(root, `_assets/separate.js.${suffix}`))), content);
        await assert.rejects(stat(resolve(root, `_assets/empty.js.${suffix}`)), {code: 'ENOENT'});
    }
});

test('invalid syntax fails before any original asset is replaced', async t => {
    const root = await fixture(t);
    const source = 'window.first = 1; // Keep this file until the entire tree is validated.\n';
    await writeFile(resolve(root, '_assets/a-first.js'), source);
    await writeFile(resolve(root, '_assets/z-broken.js'), 'function broken( {');
    await assert.rejects(buildAssets(root), /Unable to optimize _assets\/z-broken\.js/);
    assert.equal(await readFile(resolve(root, '_assets/a-first.js'), 'utf8'), source);
    await assert.rejects(stat(resolve(root, manifestPath)), {code: 'ENOENT'});
});

test('source checkouts and symlinked assets are never overwritten', async t => {
    await assert.rejects(buildAssets(new URL('../../', import.meta.url)), /staged distribution/);
    const root = await fixture(t);
    await symlink(resolve(root, 'service-worker.js'), resolve(root, '_assets/linked.js'));
    await assert.rejects(buildAssets(root), /Symlink in asset tree/);
});

test('ordinary bundles retain classic dependencies, CSS locations, licenses and compressed hashes', async t => {
    const root = await fixture(t);
    await mkdir(resolve(root, '_assets/components'));
    await writeFile(resolve(root, '_assets/first.js'), 'var PublicValue = 2; window.order = ["first"];');
    await writeFile(resolve(root, '_assets/second.js'), '(function () { window.order.push("second"); window.answer = PublicValue + 40; })();');
    await writeFile(resolve(root, '_styles/first.css'), '/*! Copyright 2026 Fixture authors */ .first { background: url(../pictures/icon.svg?shade=1#icon); } .symbol:before { content: "★"; }');
    await writeFile(resolve(root, '_assets/components/second.css'), '@font-face { font-family: fixture; src: url(../fonts/fixture.woff2); } .second { color: blue; }');
    const bundles = [
        {path: '_assets/public.bundle.js', files: ['_assets/first.js', '_assets/second.js']},
        {path: '_assets/public.bundle.css', files: ['_styles/first.css', '_assets/components/second.css']},
    ];
    await buildAssets(root, {bundles});
    const manifest = JSON.parse(await readFile(resolve(root, manifestPath), 'utf8'));
    assert.deepEqual(manifest.bundles, bundles);
    const content = await readFile(resolve(root, bundles[0].path));
    const context = vm.createContext({window: {}});
    new vm.Script(content.toString()).runInContext(context);
    assert.deepEqual(Array.from(context.window.order), ['first', 'second']);
    assert.equal(context.window.answer, 42);
    const css = await readFile(resolve(root, bundles[1].path), 'utf8');
    assert.ok(css.startsWith('@charset "UTF-8";'));
    assert.match(css, /Copyright 2026 Fixture authors/);
    assert.ok(css.includes('../pictures/icon.svg?shade=1#icon'));
    assert.ok(css.includes('fonts/fixture.woff2'));
    for (const bundle of bundles) {
        const original = await readFile(resolve(root, bundle.path));
        assert.equal(manifest.assets[bundle.path], createHash('sha256').update(original).digest('hex'));
        assert.deepEqual(brotliDecompressSync(await readFile(resolve(root, bundle.path + '.br'))), original);
    }
});

test('module imports cannot be moved into a classic bundle, and failure leaves originals intact', async t => {
    const root = await fixture(t);
    const source = 'import {helper} from "./second.js"; export const result = helper();';
    await writeFile(resolve(root, '_assets/first.js'), source);
    await writeFile(resolve(root, '_assets/second.js'), 'export const helper = () => 42;');
    await assert.rejects(buildAssets(root, {bundles: [
        {path: '_assets/combined.js', files: ['_assets/first.js', '_assets/second.js']},
    ]}), /classic.*bundle|bundle.*classic/i);
    assert.equal(await readFile(resolve(root, '_assets/first.js'), 'utf8'), source);
    await assert.rejects(stat(resolve(root, manifestPath)), {code: 'ENOENT'});
});
