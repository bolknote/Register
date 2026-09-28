import {setEditorDeps} from '/admin/editor/deps.js';
import {initArticleEditForm} from '/admin/editor/form.js';
import {register_codemirror} from '/admin/editor/codemirror.js';
import {initAiTools} from '/admin/editor/ai.js';
import {initImageAlt} from '/admin/editor/image-alt.js';

const params = new URL(location.href).searchParams;
setEditorDeps({
    CodeMirror: params.has('codemirror') ? window.CodeMirror : null,
    PopupMessages: {
        hide() { document.getElementById('error').textContent = ''; },
        show(message) { document.getElementById('error').textContent = message; },
    },
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
initArticleEditForm(form, null, 'Post', 'body', 'default');
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
window.adminEditor = register_codemirror;
window.adminEditorReady = true;
