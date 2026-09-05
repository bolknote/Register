import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_assets/register/post-inplace.js', import.meta.url), 'utf8');
const helpers = source.slice(source.indexOf('    function editorConfig('), source.indexOf('    function applyShortcutHints('));

function harness() {
    let resources = null;
    let reads = 0;
    const context = vm.createContext({
        editorConfigs: new WeakMap(),
        emptyEditorConfig: Object.freeze({}),
        document: {getElementById: () => resources},
    });
    vm.runInContext(helpers, context);
    return {
        context,
        get reads() { return reads; },
        showPage(config, templates = {}) {
            resources = {
                dataset: {get config() { reads++; return config; }},
                querySelector: selector => templates[selector] || null,
            };
            return resources;
        },
        showGuestPage() { resources = null; },
    };
}

test('all editors and live-inserted cards use the shared labels without reparsing the config', () => {
    const page = harness();
    page.showPage(JSON.stringify({titleLabel: 'Заголовок', invalidTags: 'Проверьте теги'}));
    const first = page.context.editorConfig();
    assert.equal(first.titleLabel, 'Заголовок');
    for (let index = 0; index < 30; index++) {
        assert.equal(page.context.editorConfig(), first);
        assert.equal(page.context.editorConfig().invalidTags, 'Проверьте теги');
    }
    assert.equal(page.reads, 1);
});

test('partial navigation replaces translations, URLs, AI settings and templates together', () => {
    const page = harness();
    const firstTemplate = {name: 'first page menu'};
    const secondTemplate = {name: 'second page menu'};
    page.showPage(JSON.stringify({titleLabel: 'Title', tagSuggestionsUrl: '/_inplace/tags', aiAltEnabled: true}), {
        '.post-editor-context-menu-template': firstTemplate,
    });
    assert.equal(page.context.editorConfig().titleLabel, 'Title');
    assert.equal(page.context.editorTemplate('.post-editor-context-menu-template'), firstTemplate);
    page.showPage(JSON.stringify({titleLabel: 'Заголовок', tagSuggestionsUrl: '/blog/_inplace/tags', aiAltEnabled: false}), {
        '.post-editor-context-menu-template': secondTemplate,
    });
    assert.equal(page.context.editorConfig().titleLabel, 'Заголовок');
    assert.equal(page.context.editorConfig().tagSuggestionsUrl, '/blog/_inplace/tags');
    assert.equal(page.context.editorConfig().aiAltEnabled, false);
    assert.equal(page.context.editorTemplate('.post-editor-context-menu-template'), secondTemplate);
    assert.equal(page.reads, 2);
});

test('leaving the editor session cannot retain its settings or templates on a guest page', () => {
    const page = harness();
    page.showPage('{"aiAltEnabled":true}', {'.post-discard-changes-template': {}});
    assert.equal(page.context.editorConfig().aiAltEnabled, true);
    page.showGuestPage();
    assert.equal(page.context.editorConfig().aiAltEnabled, undefined);
    assert.equal(page.context.editorTemplate('.post-discard-changes-template'), null);
    page.showPage('{"aiAltEnabled":false}');
    assert.equal(page.context.editorConfig().aiAltEnabled, false);
});

test('malformed configuration does not break editor fallbacks or keep previous AI settings', () => {
    const page = harness();
    page.showPage('{"aiAltEnabled":true}');
    page.context.editorConfig();
    for (const config of [undefined, '', '{broken', 'null', 'false', '[]', '"text"']) {
        page.showPage(config);
        assert.equal(page.context.editorConfig().aiAltEnabled, undefined);
        assert.equal(page.context.editorConfig().titleLabel || 'Title', 'Title');
    }
});
