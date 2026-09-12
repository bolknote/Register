import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_assets/register/post-recovery.js', import.meta.url), 'utf8');
const context = vm.createContext({window: {}});
vm.runInContext(source, context);
const {createStore} = context.window.RegisterPostRecovery;
const stamp = 1800000000000;
function storage() {
    const values = new Map();
    return {
        values,
        get length() { return values.size; },
        key(index) { return [...values.keys()][index] ?? null; },
        getItem(key) { return values.get(key) ?? null; },
        setItem(key, value) { values.set(key, value); },
        removeItem(key) { values.delete(key); },
    };
}
function record(id = 'draft1', changes = {}) {
    return {
        version: 1, id, target: 'new', revision: 0, savedAt: stamp,
        snapshot: {title: '', body: '<p>Незаконченный текст</p>', tags: 'тег', date: '2026-09-12T12:00', slug: '', mediaIds: []},
        ...changes,
    };
}
const open = (data, account = 1, scope = '/_inplace/tags') => createStore(data, scope, account, () => stamp);

test('new and existing unfinished posts survive opening another store without a title requirement', () => {
    const data = storage();
    assert.equal(open(data).save(record()), true);
    assert.equal(open(data).save(record('edit1', {target: '15', revision: 8})), true);
    assert.equal(open(data).list('new')[0].snapshot.body, '<p>Незаконченный текст</p>');
    assert.equal(open(data).list('15')[0].revision, 8);
});

test('accounts and installations cannot enumerate, overwrite or delete each other’s copies', () => {
    const data = storage();
    open(data).save(record());
    assert.equal(open(data, 2).list().length, 0);
    assert.equal(open(data, 1, '/another/_inplace/tags').list().length, 0);
    open(data, 2).remove(record());
    assert.equal(open(data).list().length, 1);
    for (const account of [undefined, null, 0, -1, '1', 1.5]) assert.equal(createStore(data, '/', account), null);
});

test('independent tab records coexist and a stale view cannot delete a newer snapshot', () => {
    const data = storage();
    const first = open(data);
    const second = open(data);
    first.save(record('tabA', {target: '4'}));
    second.save(record('tabB', {target: '4'}));
    assert.equal(first.list('4').length, 2);
    second.save(record('tabA', {target: '4', savedAt: stamp + 1}));
    first.remove(record('tabA', {target: '4'}));
    assert.equal(first.list('4').length, 2);
    first.remove(record('tabB', {target: '4'}));
    assert.equal(first.list('4').length, 1);
});

test('seven-day expiry prunes only the current account and rejects malformed local data', () => {
    const data = storage();
    open(data).save(record());
    open(data, 2).save(record());
    const later = createStore(data, '/_inplace/tags', 1, () => stamp + 8 * 86400000);
    assert.equal(later.list().length, 0);
    assert.equal(open(data, 2).list().length, 1);
    data.setItem('register:post-recovery:1:%2F_inplace%2Ftags:1:bad', '{bad');
    assert.equal(open(data).list().length, 0);
    assert.equal(open(data).save(record('bad', {target: '1"] script'})), false);
    assert.equal(open(data).save(record('bad', {snapshot: {}})), false);
});

test('storage exceptions and oversized posts leave the last valid copy available', () => {
    const data = storage();
    const store = open(data);
    store.save(record());
    const oversized = record();
    oversized.snapshot.body = 'x'.repeat(512 * 1024);
    assert.equal(store.save(oversized), false);
    assert.equal(store.list()[0].snapshot.body, '<p>Незаконченный текст</p>');
    const unavailable = open({get length() { throw new Error('blocked'); }, setItem() { throw new Error('quota'); }});
    assert.equal(unavailable.save(record()), false);
    assert.equal(unavailable.list().length, 0);
    assert.doesNotThrow(() => unavailable.remove(record()));
});

test('retention bounds the number and total size of drafts', () => {
    const data = storage();
    for (let index = 0; index < 14; index++) open(data).save(record(`copy${index}`, {savedAt: stamp + index}));
    assert.equal(open(data).list().length, 10);
    assert.equal(open(data).list()[0].id, 'copy13');
    for (let index = 0; index < 10; index++) {
        const item = record(`large${index}`, {savedAt: stamp + 100 + index});
        item.snapshot.body = 'x'.repeat(450000);
        assert.equal(open(data).save(item), true);
    }
    assert.ok([...data.values.values()].reduce((sum, value) => sum + value.length, 0) <= 2 * 1024 * 1024);
});
