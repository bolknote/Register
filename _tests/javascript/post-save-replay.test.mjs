import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';
import {parse} from 'acorn';

const source = await readFile(new URL('../../_assets/register/post-inplace.js', import.meta.url), 'utf8');
const functions = parse(source, {ecmaVersion: 'latest'}).body[0].expression.callee.body.body;
const lifecycle = ['beginEdit', 'syncEditor', 'updateEditedCard', 'updateCreatedCard']
    .map(name => functions.find(node => node.type === 'FunctionDeclaration' && node.id.name === name))
    .map(node => source.slice(node.start, node.end)).join('\n');

// Keep the real save -> reopen -> title-only synchronization path. Rendering,
// widgets and focus are peripheral to the hidden form values under test.
function editor() {
    class HTMLElement {
        constructor() {
            this.dataset = {};
            this.attributes = new Map();
            this.innerHTML = '';
            this.textContent = '';
            this.classList = {add() {}, remove() {}, toggle() {}};
        }
        querySelector() { return null; }
        querySelectorAll() { return []; }
        closest() { return null; }
        hasAttribute(name) { return this.attributes.has(name); }
        getAttribute(name) { return this.attributes.get(name) ?? null; }
        setAttribute(name, value) { this.attributes.set(name, value); }
        removeAttribute(name) { this.attributes.delete(name); }
        replaceChildren() { this.innerHTML = ''; }
        append() {}
    }
    class HTMLInputElement extends HTMLElement {
        constructor(value = '') { super(); this.value = this.defaultValue = value; }
    }
    class HTMLTextAreaElement extends HTMLInputElement {}
    class HTMLTimeElement extends HTMLElement {}
    class HTMLAnchorElement extends HTMLElement {}
    class HTMLFormElement extends HTMLElement {}
    const fields = {
        title: new HTMLInputElement(), body: new HTMLTextAreaElement(), tags: new HTMLInputElement(),
        published_at: new HTMLInputElement('1788696000'), uploaded_media_ids: new HTMLInputElement(),
        revision: new HTMLInputElement('0'), inplace_action: new HTMLInputElement('create'),
        inplace_token: new HTMLInputElement('fixture'), request_id: new HTMLInputElement(),
    };
    const card = new HTMLElement();
    card.setAttribute('data-post-creating', '');
    const permalink = new HTMLAnchorElement();
    const title = new HTMLElement();
    title.closest = () => permalink;
    const elements = {
        form: {elements: {namedItem: name => fields[name]}, action: '/_inplace/post/new'},
        title, body: new HTMLElement(), tags: new HTMLElement(), tagsHost: new HTMLElement(),
        foot: new HTMLElement(), titleField: fields.title, bodyField: fields.body, tagsField: fields.tags,
        publishedAtField: fields.published_at, uploadedMediaField: fields.uploaded_media_ids,
        dateInput: new HTMLInputElement(), dateButton: new HTMLElement(), time: new HTMLTimeElement(),
    };
    elements.body.replaceWith = replacement => { elements.body = replacement; };
    card.querySelectorAll = selector => selector === 'input[name="revision"]' ? [fields.revision] : [];
    card.querySelector = selector => selector === ':scope > .post.head > a' ? permalink : null;
    const editorStates = new WeakMap();
    const noop = () => {};
    const history = () => ({before: noop, record: noop});
    const context = vm.createContext({
        HTMLElement, HTMLInputElement, HTMLTextAreaElement, HTMLTimeElement, HTMLAnchorElement, HTMLFormElement,
        AbortController, editorStates, cardFor: () => card, editorElements: () => elements,
        editorConfig: () => ({}), closeOtherCards: () => true, closeConfirmation: noop,
        clearError: noop, clearStatus: noop, destroyWidgets: noop, enhanceWidgets: noop,
        createFootHeightSpacer: () => new HTMLElement(), detachEditableBodyStyles: noop,
        restoreTypographicNoBreaks: noop, prepareEditableMedia: noop, setEditable: noop,
        editableBodyHtml: state => state.body.innerHTML, localDateTimeValue: () => '2026-09-06T12:00:00',
        createTagEditor: () => ({sync: () => [], snapshot: () => ''}),
        createEditorFieldSurfaces: noop, applyShortcutHints: noop, toggleEditingTools: noop,
        createBodyHistory: history, createFieldHistory: history, startPostRecovery: noop,
        focusEdge: noop, queueMicrotask: noop, normalizeMediaBodyStructure: () => false,
        finishImageCaptionEditing: noop, finishInlineMediaCaptions: noop, closeContextMenu: noop,
        editorPublishedAt: state => state.originalPublishedAt, releasableMediaIds: () => [],
        parseBody: html => Object.assign(new HTMLElement(), {innerHTML: html}),
        stopEditing: state => editorStates.delete(state.card), updateScheduledPreview: noop,
        acknowledgeLiveMutation: () => true, refresh: noop, unlock: noop, postToolsFocusTarget: () => null,
        document: {
            createElement: () => ({content: {querySelector: () => null}}),
            createDocumentFragment: () => ({append: noop}), execCommand: noop,
        },
        window: {
            RegisterEditorStorage: {recordId: () => 'stable-operation'},
            RegisterEditorHtmlBlocks: {editableHtml: html => html, create: () => ({prepare: noop})},
        },
    });
    vm.runInContext(lifecycle, context);
    context.beginEdit({});
    const draft = editorStates.get(card);
    draft.title.textContent = 'Original title';
    draft.titleDirty = draft.bodyDirty = true;
    draft.body.innerHTML = '<p>Original creation.</p>';
    assert.equal(context.syncEditor(draft), true);
    return {context, card, elements, fields, editorStates};
}

function response(body) {
    return {
        success: true, action: 'create', replayed: true, request_matched: true,
        id: 12, revision: 2, title: 'Current title', url: '/created', action_url: '/_inplace/post/12', token: 'fixture',
        published_at: 1788696000, datetime: '2026-09-06T12:00:00Z', time: '6 September', tags: [], scheduled: false,
        body, body_html: '<div class="post body" data-post-inplace-body><p>Rendered current body.</p></div>',
    };
}

test('replaying a creation adopts the current raw body before a later title-only edit', () => {
    const {context, card, elements, fields, editorStates} = editor();
    // The creation committed, its response was lost, and another window edited
    // the body before this window's retry returned the current post revision.
    const canonical = '<div data-post-html-source=""><div>Authored block</div></div><p>Saved later.</p>';
    context.updateCreatedCard(card, elements.form, response(canonical));
    assert.match(elements.body.innerHTML, /Rendered current body/u);
    assert.equal(context.beginEdit({}), true);
    const state = editorStates.get(card);
    state.title.textContent = 'Changed title only';
    state.titleDirty = true;
    assert.equal(context.syncEditor(state), true);
    assert.equal(fields.body.value, canonical, 'A title edit must not revert another window\'s saved body');
    assert.equal(state.originalBody, canonical);
    assert.equal(state.body.innerHTML, canonical, 'Reopening uses authored source, not rendered widget markup');
    assert.equal(fields.revision.value, '2');
    assert.equal(fields.body.defaultValue, canonical);
});

test('saving against an older server without a raw body retains the submitted source', () => {
    const {context, card, elements, fields} = editor();
    const payload = response();
    delete payload.body;
    payload.action = 'edit';
    context.updateEditedCard(card, elements.form, payload);
    assert.equal(fields.body.value, '<p>Original creation.</p>');
    assert.equal(fields.body.defaultValue, '<p>Original creation.</p>');
});

test('an empty canonical raw body replaces the earlier source', () => {
    const {context, card, elements, fields} = editor();
    const payload = response('');
    payload.action = 'edit';
    context.updateEditedCard(card, elements.form, payload);
    assert.equal(fields.body.value, '');
    assert.equal(fields.body.defaultValue, '');
});
