import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_assets/register/post-inplace.js', import.meta.url), 'utf8');
const helper = source.slice(source.indexOf('    function openEditorFromUrl()'), source.lastIndexOf('    applyShortcutHints(document);'));

function harness(href, button) {
    const clicks = [];
    const selectors = [];
    const historyState = {page: 'retained'};
    const window = {
        location: {href},
        history: {
            state: historyState,
            replaceState(state, title, url) {
                assert.equal(state, historyState);
                window.location.href = new URL(url, window.location.href).href;
            },
        },
    };
    const element = button === null ? null : {
        ...button,
        click() { clicks.push(window.location.href); },
    };
    const context = vm.createContext({
        URL,
        window,
        document: {querySelector(selector) { selectors.push(selector); return element; }},
    });
    vm.runInContext(helper, context);
    return {open: () => context.openEditorFromUrl(), window, clicks, selectors};
}

test('create opens the existing creation button once without submitting a post', () => {
    const page = harness('https://example.test/blog/?editor=new&keep=1#anchor', {});
    page.open();
    page.open();
    assert.deepEqual(page.selectors, ['.post-create-start']);
    assert.deepEqual(page.clicks, ['https://example.test/blog/?keep=1#anchor']);
});

test('edit keeps the routed path and opens only an editable post', () => {
    const page = harness('https://example.test/blog/index.php?/заметка&editor=edit', {});
    page.open();
    assert.deepEqual(page.selectors, ['.post-card.is-manageable .post-edit-start']);
    assert.equal(page.clicks.length, 1);
    assert.equal(new URL(page.clicks[0]).searchParams.has('editor'), false);
    assert.equal(page.clicks[0], 'https://example.test/blog/index.php?/%D0%B7%D0%B0%D0%BC%D0%B5%D1%82%D0%BA%D0%B0');
});

test('no button, a hidden button or a disabled button cannot start editing', () => {
    for (const button of [null, {hidden: true}, {disabled: true}]) {
        const page = harness('https://example.test/blog/?editor=edit', button);
        page.open();
        assert.deepEqual(page.clicks, []);
    }
});

test('ordinary URLs and unknown commands leave the page untouched', () => {
    for (const href of ['https://example.test/blog/', 'https://example.test/blog/?editor=delete']) {
        const page = harness(href, {});
        page.open();
        assert.deepEqual(page.selectors, []);
        assert.deepEqual(page.clicks, []);
        assert.equal(page.window.location.href, href);
    }
});
