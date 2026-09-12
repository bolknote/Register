import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_admin/js/lib.js', import.meta.url), 'utf8');
const helper = source.slice(
    source.indexOf('function setLoginMessage('),
    source.indexOf('async function SendLoginData('),
);

function render(message) {
    const element = {
        children: [],
        replaceChildren(...children) {
            this.children = children;
        },
    };
    const document = {
        createElement(name) {
            return {type: 'element', name};
        },
        createTextNode(text) {
            return {type: 'text', text};
        },
    };
    const context = vm.createContext({document});
    vm.runInContext(helper, context);
    context.setLoginMessage(element, message);

    return element.children;
}

test('login errors render translated br tags as line breaks', () => {
    assert.deepEqual(render('First line<br>Second line'), [
        {type: 'text', text: 'First line'},
        {type: 'element', name: 'br'},
        {type: 'text', text: 'Second line'},
    ]);
    assert.deepEqual(render('First<BR />Second<br/>Third'), [
        {type: 'text', text: 'First'},
        {type: 'element', name: 'br'},
        {type: 'text', text: 'Second'},
        {type: 'element', name: 'br'},
        {type: 'text', text: 'Third'},
    ]);
});

test('login errors keep every other html fragment as text', () => {
    assert.deepEqual(render('<img src=x onerror=alert(1)>'), [
        {type: 'text', text: '<img src=x onerror=alert(1)>'},
    ]);
});
