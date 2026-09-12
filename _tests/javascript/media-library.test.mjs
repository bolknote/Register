import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_admin/js/pictman.js', import.meta.url), 'utf8');
const functions = source.slice(source.indexOf('function mediaFileType('), source.indexOf('function audioTitle('));
const context = vm.createContext({});
vm.runInContext(functions, context);

function file(name) {
    return {dataset: {fname: name}, hidden: false};
}

test('media filters classify names without requiring a content index', () => {
    for (const [name, type] of [
        ['family.JPG', 'image'], ['diagram.svg', 'image'], ['photo.avif', 'image'],
        ['podcast.M4A', 'audio'], ['recording.opus', 'audio'], ['clip.webm', 'video'],
        ['readme.pdf', 'document'], ['sheet.xlsx', 'document'], ['archive.zip', 'other'],
        ['LICENSE', 'other'],
    ]) {
        assert.equal(context.mediaFileType(name), type, name);
    }
});

test('name and type filters are combined and names remain intact', () => {
    const nodes = [file('Кот.jpg'), file('Кот.mp4'), file('Дом.jpg')];
    const hidden = [];
    const counts = context.filterMediaFiles(nodes, ' КОТ ', 'image', (node) => hidden.push(node.dataset.fname));
    assert.equal(counts.visible, 1);
    assert.equal(counts.total, 3);
    assert.deepEqual(nodes.map((node) => node.hidden), [false, true, true]);
    assert.deepEqual(hidden, ['Кот.mp4', 'Дом.jpg']);
    assert.deepEqual(nodes.map((node) => node.dataset.fname), ['Кот.jpg', 'Кот.mp4', 'Дом.jpg']);
});

test('search accepts decomposed Unicode in names and does not interpret patterns', () => {
    const nodes = [file('cafe\u0301[1].jpg'), file('cafe2.jpg')];
    const counts = context.filterMediaFiles(nodes, 'CAFÉ[1]', 'all');
    assert.equal(counts.visible, 1);
    assert.equal(nodes[0].hidden, false);
    assert.equal(nodes[1].hidden, true);
});

test('clearing the filters restores all files, including hidden results', () => {
    const nodes = [file('a.jpg'), file('b.mp3')];
    context.filterMediaFiles(nodes, 'missing', 'image');
    assert.ok(nodes.every((node) => node.hidden));
    const counts = context.filterMediaFiles(nodes, '', 'all');
    assert.equal(counts.visible, 2);
    assert.ok(nodes.every((node) => !node.hidden));
});

test('empty folders and searches with no matches have different counts', () => {
    const empty = context.filterMediaFiles([], '', 'all');
    const missing = context.filterMediaFiles([file('a.jpg')], 'b', 'all');
    assert.equal(empty.total, 0);
    assert.equal(missing.total, 1);
    assert.equal(empty.visible, 0);
    assert.equal(missing.visible, 0);
});

test('filtered-out selections are deselected and select-all skips hidden files', () => {
    assert.match(source, /fileTree\.jstree\('deselect_node', node\)/u);
    assert.match(source, /_get_children\(-1\)\.filter\(function \(\) \{\s+return !this\.hidden;/u);
    assert.match(source, /\.bind\('deselect_node\.jstree deselect_all\.jstree', updateFileSelection\)/u);
});

test('file details do not contaminate names used by rename and delete operations', () => {
    assert.match(source, /link\.dataset\.fileSummary =/u);
    assert.doesNotMatch(source, /link\.(?:innerHTML|textContent)\s*=/u);
});
