import assert from 'node:assert/strict';
import {mkdtemp, mkdir, readFile, rm, symlink, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {resolve} from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import {assetDirectories, buildAssets, manifestPath} from '../../tools/assets/optimizer.mjs';
import {prepareAssetGraphs, versionAssetGraphReferences} from '../../tools/assets/graphs.mjs';

const graph = '_assets/processor';
const entry = '_assets/host.js';
const modules = {
    [entry]: `window.processor = new URL('processor/js/optimizer.js', document.currentScript.src).toString();`,
    [graph + '/js/optimizer.js']: `import {policy} from './policy.js'; export {policy};
        export const worker = new URL('worker.js', import.meta.url);`,
    [graph + '/js/policy.js']: 'export const policy = 42;',
    [graph + '/js/worker.js']: `importScripts('../lib/codec.js');
        self.codecUrl = path => new URL('../lib/' + path, self.location.href).toString();`,
    [graph + '/lib/codec.js']: 'self.codec = true;',
    [graph + '/lib/codec.wasm']: Buffer.from([0, 97, 115, 109, 1, 0, 0, 0]),
};

async function fixture(t, changes = {}) {
    const root = await mkdtemp(resolve(tmpdir(), 'register-graph-regression-'));
    t.after(() => rm(root, {recursive: true, force: true}));
    for (const directory of [...assetDirectories, '_include']) await mkdir(resolve(root, directory));
    await writeFile(resolve(root, 'service-worker.js'), 'self.addEventListener("fetch", () => {});');
    for (const [path, content] of Object.entries({...modules, ...changes})) {
        await mkdir(resolve(root, path, '..'), {recursive: true});
        await writeFile(resolve(root, path), content);
    }
    await buildAssets(root, {graphs: [graph], bundles: []});
    const manifest = JSON.parse(await readFile(resolve(root, manifestPath), 'utf8'));
    const source = await readFile(resolve(root, entry), 'utf8');
    const context = vm.createContext({URL, document: {currentScript: {src: 'https://example.test/_assets/host.js?v=editor'}}, window: {}});
    new vm.Script(source).runInContext(context);
    return {root, manifest, url: new URL(context.window.processor)};
}

test('entry, static imports, workers, classic libraries and dynamic Wasm share one release version', async t => {
    const {root, url} = await fixture(t);
    const version = url.searchParams.get('v');
    assert.match(version, /^[a-f0-9]{64}$/u);
    assert.equal(url.pathname, '/' + graph + '/js/optimizer.js.asset');
    const module = await readFile(resolve(root, graph + '/js/optimizer.js'), 'utf8');
    assert.ok(module.includes(`policy.js.asset?v=${version}`));
    assert.ok(module.includes(`worker.js.asset?v=${version}`));
    const scripts = [];
    const context = vm.createContext({URL, self: {location: {href: `https://example.test/${graph}/js/worker.js.asset?v=${version}`}},
        importScripts: path => scripts.push(path)});
    new vm.Script(await readFile(resolve(root, graph + '/js/worker.js'), 'utf8')).runInContext(context);
    assert.deepEqual(scripts, [`../lib/codec.js.asset?v=${version}`]);
    const wasm = new URL(context.self.codecUrl('codec.wasm?quality=1#codec'));
    assert.equal(wasm.pathname, '/' + graph + '/lib/codec.wasm');
    assert.equal(wasm.searchParams.get('v'), version);
    assert.equal(wasm.searchParams.get('quality'), '1');
    assert.equal(wasm.hash, '#codec');
});

test('any dependency or binary change rotates the entry and its consuming editor hash, but mtime does not', async t => {
    const original = await fixture(t);
    const same = await fixture(t);
    assert.equal(same.url.href, original.url.href);
    assert.equal(same.manifest.assets[entry], original.manifest.assets[entry]);
    for (const changes of [
        {[graph + '/js/policy.js']: 'export const policy = 43;'},
        {[graph + '/js/worker.js']: modules[graph + '/js/worker.js'] + 'self.workerVersion = 2;'},
        {[graph + '/lib/codec.wasm']: Buffer.from([0, 97, 115, 109, 1, 0, 0, 0, 0])},
    ]) {
        const changed = await fixture(t, changes);
        assert.notEqual(changed.url.href, original.url.href);
        assert.notEqual(changed.manifest.assets[entry], original.manifest.assets[entry]);
    }
});

test('rewriting uses URL syntax only and leaves outside modules, ordinary strings and fragments intact', () => {
    const source = Buffer.from(`import outside from '../outside.js';
        export {policy} from './js/policy.js?mode=1#fragment';
        const text = './js/policy.js';
        const external = new URL('https://cdn.example.test/codec.js');
        const wasm = new URL('./lib/codec.wasm', import.meta.url);
        const worker = new Worker('./js/worker.js');
        const lazy = () => import('./js/policy.js');`);
    const version = 'a'.repeat(64);
    const output = versionAssetGraphReferences(graph + '/example.js', source, [{path: graph, version}]).toString();
    assert.ok(output.includes("import outside from '../outside.js'"));
    assert.ok(output.includes("const text = './js/policy.js'"));
    assert.ok(output.includes("https://cdn.example.test/codec.js"));
    assert.ok(output.includes(`./js/policy.js.asset?mode=1&v=${version}#fragment`));
    assert.ok(output.includes(`./lib/codec.wasm?v=${version}`));
    assert.ok(output.includes(`./js/worker.js.asset?v=${version}`));
    assert.ok(output.includes(`import("./js/policy.js.asset?v=${version}")`));
});

test('unsafe graph definitions and symlinked binaries are rejected before publishing', async t => {
    await assert.rejects(prepareAssetGraphs('/tmp', [], ['../outside']), /Invalid asset graph/);
    const root = await mkdtemp(resolve(tmpdir(), 'register-graph-symlink-'));
    t.after(() => rm(root, {recursive: true, force: true}));
    await mkdir(resolve(root, graph), {recursive: true});
    await writeFile(resolve(root, 'original.wasm'), modules[graph + '/lib/codec.wasm']);
    await symlink(resolve(root, 'original.wasm'), resolve(root, graph + '/linked.wasm'));
    await assert.rejects(prepareAssetGraphs(root, [], [graph]), /Symlink in asset graph/);
});
