import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const editorSource = await readFile(
    new URL('../../_assets/register/post-inplace.js', import.meta.url),
    'utf8'
);

test('starting the post editor script does not import image codecs or create workers', async function () {
    let dynamicImports = 0;
    let workers = 0;
    const document = {
        currentScript: {src: 'https://example.test/_assets/register/post-inplace.js?v=1'},
        documentElement: {lang: 'en'},
        addEventListener: function () {},
        querySelectorAll: function () { return []; }
    };
    const window = {
        location: {href: 'https://example.test/post'},
        addEventListener: function () {},
        setTimeout: setTimeout
    };
    const context = vm.createContext({
        AbortController,
        DOMException,
        URL,
        console,
        document,
        navigator: {platform: 'Linux'},
        setTimeout,
        window,
        Worker: class {
            constructor() {
                workers++;
            }
        }
    });
    const script = new vm.Script(editorSource, {
        filename: 'post-inplace.js',
        importModuleDynamically: async function () {
            dynamicImports++;
            throw new Error('The optimizer must not load while the editor boots.');
        }
    });

    script.runInContext(context);
    await Promise.resolve();

    assert.equal(dynamicImports, 0);
    assert.equal(workers, 0);
});

test('the optimizer import remains inside the image-only upload branch', function () {
    const uploadStart = editorSource.indexOf('function startMediaUpload');
    const uploadEnd = editorSource.indexOf('\n    async function redatePendingMedia', uploadStart);
    const uploadSource = editorSource.slice(uploadStart, uploadEnd);
    const imports = editorSource.match(/import\(imageOptimizerUrl\)/gu) || [];

    assert.notEqual(uploadStart, -1);
    assert.notEqual(uploadEnd, -1);
    assert.equal(imports.length, 1);
    assert.match(uploadSource, /if \(kind === 'image'\)[\s\S]*await loadImageOptimizer\(\)/u);
    assert.doesNotMatch(editorSource, /new Worker\s*\(/u);
});
