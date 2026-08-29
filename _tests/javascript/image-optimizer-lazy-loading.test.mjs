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

test('automatic alt generation covers existing and newly uploaded images', function () {
    const editStart = editorSource.indexOf('function beginEdit');
    const editEnd = editorSource.indexOf('\n    function beginCreate', editStart);
    const editSource = editorSource.slice(editStart, editEnd);
    const uploadStart = editorSource.indexOf('function startMediaUpload');
    const uploadEnd = editorSource.indexOf('\n    async function redatePendingMedia', uploadStart);
    const uploadSource = editorSource.slice(uploadStart, uploadEnd);
    const submitStart = editorSource.indexOf('async function submit');
    const submitEnd = editorSource.indexOf('\n    function handleSubmit', submitStart);
    const submitSource = editorSource.slice(submitStart, submitEnd);

    assert.match(editSource, /generateMissingImageAlts\(state\)/u);
    assert.match(uploadSource, /await queueImageAlt\(state, image, uploadFile\)/u);
    assert.match(submitSource, /await Promise\.all\(Array\.from\(state\.aiAltTasks\)\)/u);
    assert.match(editorSource, /data\.set\('inplace_action', 'ai_alt'\)/u);
    assert.match(editorSource, /\(!force && !imageNeedsGeneratedAlt\(image\)\)/u);
    assert.match(editorSource, /targetImage: context\.targetImage/u);
    assert.doesNotMatch(editSource, /loadImageOptimizer\(\)/u);
});

test('dropped images render immediately and the complete processing flow is queued', function () {
    const pendingStart = editorSource.indexOf('function createMediaUploadPending');
    const pendingEnd = editorSource.indexOf('\n    function bodyRange', pendingStart);
    const pendingSource = editorSource.slice(pendingStart, pendingEnd);
    const uploadStart = editorSource.indexOf('function startMediaUpload');
    const uploadEnd = editorSource.indexOf('\n    async function redatePendingMedia', uploadStart);
    const uploadSource = editorSource.slice(uploadStart, uploadEnd);
    const insertStart = editorSource.indexOf('function insertMediaFiles');
    const insertEnd = editorSource.indexOf('\n    function transferHasFiles', insertStart);
    const insertSource = editorSource.slice(insertStart, insertEnd);

    assert.match(pendingSource, /const previewUrl = URL\.createObjectURL\(file\)/u);
    assert.match(pendingSource, /image\.setAttribute\('src', previewUrl\)/u);
    assert.match(pendingSource, /className = 'post-picture post-media-picture is-processing'/u);
    assert.match(pendingSource, /className = 'post-media-processing-progress'/u);
    assert.match(insertSource, /range\.insertNode\(pending\.element\)/u);
    assert.match(insertSource, /startMediaUpload\(state, file, kind, pending\)/u);

    const optimizing = uploadSource.indexOf("updateMediaUploadPending(pending, optimizingMessage, 'optimizing')");
    const uploading = uploadSource.indexOf("updateMediaUploadPending(pending, uploadingMessage, 'uploading')");
    const alt = uploadSource.indexOf("state.card.dataset.aiAltWorking || 'AI is creating alt text…'");
    const queued = uploadSource.indexOf('state.imageUploadTail.catch(() => {}).then(run)');
    assert.ok(optimizing >= 0 && uploading > optimizing && alt > uploading);
    assert.ok(queued > alt);
    assert.match(uploadSource, /await queueImageAlt\(state, image, uploadFile\)/u);
    assert.match(uploadSource, /await revealProcessedImage\(pending\)/u);
});
