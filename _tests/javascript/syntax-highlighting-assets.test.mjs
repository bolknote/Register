import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../../_assets/register/syntax-highlighting/loader.js', import.meta.url), 'utf8');

function page(withCode, configured = true) {
    const loaded = [];
    const code = {tagName: 'CODE', className: '', textContent: 'const answer = 42;', dataset: {},
        parentElement: {className: ''}, hasAttribute: () => true};
    const window = {hljs: {highlightElement(element) { element.dataset.highlighted = 'yes'; }}};
    const urls = {
        script: 'https://example.test/blog/_assets/register/syntax-highlighting/vendor/highlight.js/highlight.min.js.asset?v=' + 'a'.repeat(64),
        style: 'https://example.test/blog/_assets/register/syntax-highlighting/theme.css.asset?v=' + 'b'.repeat(64),
    };
    const document = {
        currentScript: {src: 'https://example.test/blog/_assets/register/syntax-highlighting/loader.js.asset?v=' + 'c'.repeat(64)},
        querySelector(selector) {
            if (configured && selector === 'meta[name="register-syntax-highlighting"]') {
                return {dataset: {scriptUrl: urls.script, styleUrl: urls.style}};
            }
            return null;
        },
        querySelectorAll: () => withCode ? [{children: [code]}] : [],
        createElement: tagName => ({tagName, setAttribute() {}}),
        head: {appendChild(element) {
            loaded.push(element.src || element.href);
            if (element.tagName === 'script') window.hljs = {highlightElement(element) { element.dataset.highlighted = 'yes'; }};
            element.onload();
        }},
        dispatchEvent() {},
    };
    // Exercise the real lazy script request, rather than the already-loaded-library shortcut.
    delete window.hljs;
    vm.runInNewContext(source, {document, window, URL, console, CustomEvent: class {}});
    return {window, document, code, loaded, urls};
}

test('a normal code block loads both versioned assets under the installation base path', async () => {
    const fixture = page(true);
    await fixture.window.RegisterSyntaxHighlighting.highlight(fixture.document);
    assert.deepEqual(fixture.loaded, [fixture.urls.style, fixture.urls.script]);
    assert.equal(fixture.code.dataset.highlighted, 'yes');
});

test('pages without code keep the highlighter and its stylesheet lazy', async () => {
    const fixture = page(false);
    await fixture.window.RegisterSyntaxHighlighting.highlight(fixture.document);
    assert.deepEqual(fixture.loaded, []);
});

test('standalone loader users retain working relative asset URLs', async () => {
    const fixture = page(true, false);
    await fixture.window.RegisterSyntaxHighlighting.highlight(fixture.document);
    assert.deepEqual(fixture.loaded, [
        'https://example.test/blog/_assets/register/syntax-highlighting/theme.css',
        'https://example.test/blog/_assets/register/syntax-highlighting/vendor/highlight.js/highlight.min.js',
    ]);
});
