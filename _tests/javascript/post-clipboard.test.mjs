import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {File} from 'node:buffer';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_assets/register/post-inplace.js', import.meta.url), 'utf8');
const mediaKindSource = source.slice(source.indexOf('    function mediaKindForFile('), source.indexOf('    function mediaMessage('));
const pasteSource = source.slice(source.indexOf('    function clipboardMediaFiles('), source.indexOf('    function bodyDropState('));
assert.ok(mediaKindSource.length > 0 && pasteSource.length > 0);

function harness() {
    const state = {body: {}};
    const inserted = [];
    let selection = null;
    const context = vm.createContext({
        File,
        imageExtensions: new Set(['avif', 'bmp', 'gif', 'ico', 'jpeg', 'jpg', 'png', 'webp']),
        audioExtensions: new Set(['flac', 'mkv', 'mp3', 'mp4', 'ogg', 'wav', 'webm']),
        bodyDropState: target => target === state.body ? state : null,
        rangeIsInside: (_body, range) => range.inside,
        insertMediaFiles: (editor, files, range) => inserted.push({editor, files, range}),
        window: {getSelection: () => selection},
    });
    vm.runInContext(mediaKindSource + pasteSource, context);
    return {
        context, state, inserted,
        paste(clipboardData, properties = {}) {
            const event = {
                target: state.body,
                clipboardData,
                defaultPrevented: false,
                preventDefault() { this.defaultPrevented = true; },
                ...properties,
            };
            context.pasteMediaFiles(event);
            return event;
        },
        select(inside = true) {
            const range = {
                inside,
                deleted: false,
                deleteContents() { this.deleted = true; },
                cloneRange() { return {...this}; },
            };
            selection = {rangeCount: 1, getRangeAt: () => range};
            return range;
        },
    };
}

const png = (name = 'image.png') => new File(['test image bytes'], name, {type: 'image/png', lastModified: 123});

test('image paste uses the shared upload pipeline and prevents a second native image', () => {
    const {paste, inserted, state} = harness();
    const file = png();
    const event = paste({files: [file], items: [{kind: 'file', getAsFile() { throw new Error('Do not insert twice'); }}]});
    assert.equal(event.defaultPrevented, true);
    assert.equal(inserted.length, 1);
    assert.equal(inserted[0].editor, state);
    assert.deepEqual(Array.from(inserted[0].files), [file]);
    assert.equal(inserted[0].range, null);
    assert.match(source, /document\.addEventListener\('paste', pasteMediaFiles, false\)/u);
});

test('screenshots without extensions keep their bytes and get a supported filename', async () => {
    const {context} = harness();
    for (const name of ['', 'image', 'clipboard.tiff']) {
        const original = png(name);
        const [file] = context.clipboardMediaFiles({files: [original]});
        assert.equal(file.name, 'clipboard-image-1.png');
        assert.equal(file.type, 'image/png');
        assert.equal(file.lastModified, original.lastModified);
        assert.deepEqual(await file.arrayBuffer(), await original.arrayBuffer());
        assert.equal(context.mediaKindForFile(file), 'image');
    }
});

test('clipboard items are a fallback for browsers with an empty file list', () => {
    const {paste, inserted} = harness();
    const first = png('first.png');
    const second = png('second.png');
    paste({files: [], items: [
        {kind: 'string', getAsFile() { throw new Error('This is text'); }},
        {kind: 'file', getAsFile: () => first},
        {kind: 'file', getAsFile: () => null},
        {kind: 'file', getAsFile: () => second},
    ]});
    assert.equal(inserted.length, 1);
    assert.deepEqual(Array.from(inserted[0].files), [first, second]);
});

test('image paste replaces the current selection, not text elsewhere or at the end', () => {
    const {paste, select, inserted} = harness();
    const selected = select();
    paste({files: [png()]});
    assert.notEqual(inserted[0].range, selected);
    assert.equal(inserted[0].range.deleted, true);
    assert.equal(selected.deleted, false);
});

test('a selection outside the body is never deleted', () => {
    const {paste, select, inserted} = harness();
    const selected = select(false);
    paste({files: [png()]});
    assert.equal(inserted[0].range, null);
    assert.equal(selected.deleted, false);
});

test('ordinary text and HTML paste keep native behavior', () => {
    const {paste, inserted} = harness();
    for (const transfer of [null, {files: [], items: []}, {
        files: [],
        items: [{kind: 'string'}],
        getData: () => '<p><strong>Formatted text</strong></p>',
    }]) {
        assert.equal(paste(transfer).defaultPrevented, false);
    }
    assert.equal(inserted.length, 0);
});

test('pastes into other fields or already handled by a caption are not intercepted', () => {
    const {paste, inserted} = harness();
    assert.equal(paste({files: [png()]}, {target: {}}).defaultPrevented, false);
    paste({files: [png()]}, {defaultPrevented: true});
    assert.equal(inserted.length, 0);
});

test('unsupported image formats use the upload error path without deleting selected text', () => {
    const {context, paste, select, inserted} = harness();
    select();
    const svg = new File(['<svg/>'], 'image.svg', {type: 'image/svg+xml'});
    assert.equal(paste({files: [svg]}).defaultPrevented, true);
    assert.equal(inserted[0].range.deleted, false);
    assert.equal(inserted[0].files[0], svg);
    assert.equal(context.mediaKindForFile(svg), null);
});
