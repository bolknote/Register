import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_admin/js/ajax.js', import.meta.url), 'utf8');

function substitute(from, to, template) {
    // A filename containing the placeholder used to loop indefinitely in the
    // confirmation dialog. Bound execution so regressions cannot hang the suite.
    return vm.runInNewContext(source + '\nstr_replace(from, to, template);', {from, to, template}, {timeout: 200});
}

test('delete confirmation substitutes filenames containing its own placeholder once', () => {
    assert.equal(substitute('%s', 'photo%s.png', 'Delete %s?'), 'Delete photo%s.png?');
    assert.equal(substitute('%s', '%s', 'Delete folder %s and its contents?'), 'Delete folder %s and its contents?');
});

test('template substitutions retain dollar patterns and replace every original occurrence', () => {
    const name = 'снимок $& $$ $` $\' <draft>.png';
    assert.equal(substitute('%s', name, '%s: delete %s?'), name + ': delete ' + name + '?');
    assert.equal(substitute('%s', 'photo.png', 'No placeholder'), 'No placeholder');
});

test('empty replacement patterns leave templates unchanged', () => {
    assert.equal(substitute('', 'photo.png', 'Delete this file?'), 'Delete this file?');
});

test('authentication messages retain their login popup identity', () => {
    for (const status of [401, 403]) {
        const XHR = {status, responseText: JSON.stringify({message: 'Sign in again.'})};
        const messages = [];
        const result = vm.runInNewContext(source + '\ncheckAjaxStatus(XHR);', {
            XHR,
            PopupMessages: {show: (...args) => messages.push(args)},
        });
        assert.equal(result, false);
        assert.equal(XHR.registerErrorFlag, true);
        assert.deepEqual(messages, [['Sign in again.', null, null, status === 401 ? 'login' : null]]);
    }
});
