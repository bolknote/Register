import {setEditorDeps} from '/admin/editor/deps.js';
import {initArticleEditForm} from '/admin/editor/form.js';
import {register_codemirror} from '/admin/editor/codemirror.js';
import {initAiTools} from '/admin/editor/ai.js';
import {initImageAlt} from '/admin/editor/image-alt.js';

const params = new URL(location.href).searchParams;
window.adminMessages = [];
const popupMessages = {
    hide() { document.getElementById('error').textContent = ''; },
    show(message) {
        window.adminMessages.push(message);
        document.getElementById('error').textContent = message;
    },
};
if (params.has('fetch-wrapper')) {
    window.PopupMessages = popupMessages;
    window.DisplayError = message => popupMessages.show(message);
    await import('/admin-fetch.js');
}
setEditorDeps({
    CodeMirror: params.has('codemirror') ? window.CodeMirror : null,
    PopupMessages: popupMessages,
    register_lang: {unsaved_exit: 'Unsaved changes'},
    sUrl: '/admin-ajax',
});
const form = document.querySelector('form');
if (params.has('preview')) {
    const frame = document.createElement('iframe');
    frame.id = 'body-preview-frame';
    form.after(frame);
}
const id = params.get('id');
form.action = '/admin-save' + (id ? '?id=' + encodeURIComponent(id) : '');
if (params.has('codemirror')) {
    let editor;
    if (params.has('toolbar')) {
        const {initHtmlTextarea, initHtmlToolbar} = await import('/admin/editor/shortcuts.js');
        initHtmlTextarea(form.elements.body);
        editor = document.querySelector('.CodeMirror').CodeMirror;
        const toolbar = document.createElement('div');
        toolbar.innerHTML = '<button type="button" data-editor-action="b">Bold</button>'
            + '<button type="button" data-editor-action="h2">Heading 2</button>'
            + '<button type="button" data-editor-action="h3">Heading 3</button>'
            + '<button type="button" data-editor-action="h4">Heading 4</button>'
            + '<button type="button" data-editor-action="quote">Quote</button>'
            + '<button type="button" data-editor-action="pre">Preformatted</button>'
            + '<button type="button" data-editor-action="parag">Smart paragraphs</button>'
            + '<button type="button" data-editor-action="left">Paragraph</button>'
            + '<button type="button" data-editor-action="undo">Undo</button>'
            + '<button type="button" data-editor-action="redo">Redo</button>';
        form.before(toolbar);
        initHtmlToolbar(toolbar);
    } else {
        editor = register_codemirror.get_instance(form.elements.body);
    }
    Object.assign(editor.getWrapperElement().style, {position: 'relative', width: '500px', height: '160px'});
    editor.refresh();
}
if (params.has('social')) {
    const panel = document.createElement('section');
    panel.innerHTML = '<label>Description <input name="meta_description"></label>'
        + '<label>Social image <input name="social_image"></label>'
        + '<aside data-social-preview><span data-social-preview-site></span>'
        + '<h2 data-social-preview-title></h2><p data-social-preview-description></p>'
        + '<div data-social-preview-image></div></aside>';
    form.append(panel);
}
if (params.has('activitypub')) {
    const panel = document.createElement('section');
    panel.setAttribute('data-activitypub-editor-panel', '');
    panel.innerHTML = '<label>Published <input name="published" type="checkbox" checked></label>'
        + '<label for="federation">Federation</label><select id="federation" name="activitypub_publication">'
        + '<option value="inherit">Inherit</option><option value="disabled">Disabled</option></select>'
        + '<button type="button" data-activitypub-preview-button>Build ActivityPub preview</button>'
        + '<p data-activitypub-preview-status></p><div data-activitypub-preview-result hidden>'
        + '<p data-activitypub-preview-metadata></p><p data-activitypub-preview-provisional hidden></p>'
        + '<iframe data-activitypub-preview-frame sandbox=""></iframe><pre data-activitypub-preview-json></pre></div>';
    form.append(panel);
    const {initActivityPubPreview} = await import('/admin/editor/activitypub.js');
    initActivityPubPreview(form, {
        enabled: true, entityName: 'Article', contentId: Number(id), url: '/admin-activitypub-preview',
        working: 'Building preview', failed: 'Preview failed', noObject: 'No federated object',
        changed: 'Publication data changed. Build the preview again.',
    });
}
initArticleEditForm(form, null, 'Post', 'body', 'default');
if (params.has('social')) {
    const {initSocialPreview} = await import('/admin/editor/social-preview.js');
    initSocialPreview(form, {defaultImage: '/default.png', emptyText: 'Empty preview'});
}
if (params.has('ai')) {
    document.getElementById('content-editor-ai-tools').hidden = false;
    initAiTools(form, {
        enabled: true, entityName: 'Post', contentId: Number(id), url: '/admin-ai',
        working: 'Working', requestFailed: 'Failed', sourceChanged: 'The source text has changed.',
    });
}
if (params.has('alt')) {
    initImageAlt(form, {
        enabled: true, entityName: 'Post', contentId: Number(id), url: '/admin-ai-alt',
        generating: 'Generating alt', requestFailed: 'Alt failed', edit: 'Edit alt',
        preview: 'Image preview', empty: 'Empty alt', regenerate: 'Regenerate alt', retry: 'Retry alt',
    });
}
if (params.has('tags')) {
    const {initTagsInput} = await import('/admin/editor/tags.js');
    form.elements.tags.id = 'id-tags';
    initTagsInput({inputId: 'id-tags', label: 'Tags', suggestions: ['Cameras', 'Travel']});
}
window.adminEditor = register_codemirror;
window.adminEditorReady = true;
