import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_assets/register/public-auth.js', import.meta.url), 'utf8');
const helpers = source.slice(source.indexOf('    function setUrlParameter('), source.indexOf('    const openDialog ='));

class Link {
    constructor(href) { this.attributes = {href}; }
    getAttribute(name) { return this.attributes[name]; }
    setAttribute(name, value) { this.attributes[name] = value; }
}

class Form extends Link {
    constructor(action, existingInput = true) {
        super('');
        this.attributes = {action};
        this.input = existingInput ? {value: '/old'} : null;
    }
    querySelector() { return this.input; }
    append(input) { this.input = input; }
}

function update(elements, returnPath) {
    const passwordInput = {value: '/old'};
    const root = {
        querySelectorAll(selector) {
            return selector.startsWith('input')
                ? [passwordInput, ...elements.filter(e => e instanceof Form && e.input).map(e => e.input)]
                : elements;
        },
    };
    const context = vm.createContext({
        URL, URLSearchParams, HTMLFormElement: Form,
        location: {href: 'https://example.test/blog/article'},
        document: {createElement: () => ({})},
    });
    vm.runInContext(helpers, context);
    context.updateReturnPaths(root, returnPath);
    return passwordInput;
}

test('opening sign-in puts provider returns in POST fields and preserves mode links', () => {
    const form = new Form('/auth/oauth/vk?return=%2Fold');
    const link = new Link('/auth?mode=email&return=%2Fold');
    const target = '/article?x=1&y=2#comment';
    const password = update([form, link], target);
    assert.equal(form.attributes.action, '/auth/oauth/vk');
    assert.equal(form.input.value, target);
    assert.equal(password.value, target);
    const url = new URL(link.attributes.href, 'https://example.test');
    assert.equal(url.searchParams.get('mode'), 'email');
    assert.equal(url.searchParams.get('return'), target);
});

test('provider returns also work with forms from an older cached page', () => {
    const form = new Form('/auth/oauth/vk?return=%2Fold', false);
    update([form], '/new#comment');
    assert.equal(form.attributes.action, '/auth/oauth/vk');
    assert.deepEqual(form.input, {type: 'hidden', name: 'return_path', value: '/new#comment'});
});

test('return updates preserve query-based routing in a mounted installation', () => {
    const form = new Form('/blog/index.php?/auth/oauth/yandex&return=%2Fold');
    const link = new Link('/blog/index.php?/auth&mode=password&return=%2Fold');
    update([form, link], '/blog/article#comment');
    assert.equal(form.attributes.action, '/blog/index.php?/auth/oauth/yandex');
    assert.equal(form.input.value, '/blog/article#comment');
    assert.ok(link.attributes.href.startsWith('/blog/index.php?/auth&mode=password&return='));
    assert.equal(new URL(link.attributes.href, 'https://example.test').searchParams.get('return'), '/blog/article#comment');
});
