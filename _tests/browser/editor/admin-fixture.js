import {setEditorDeps} from '/admin/editor/deps.js';
import {initArticleEditForm} from '/admin/editor/form.js';
import {register_codemirror} from '/admin/editor/codemirror.js';

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
const id = params.get('id');
form.action = '/admin-save' + (id ? '?id=' + encodeURIComponent(id) : '');
if (params.has('codemirror')) {
    const editor = register_codemirror.get_instance(form.elements.body);
    Object.assign(editor.getWrapperElement().style, {position: 'relative', width: '500px', height: '160px'});
    editor.refresh();
}
initArticleEditForm(form, null, 'Post', 'body', 'default');
window.adminEditor = register_codemirror;
window.adminEditorReady = true;
