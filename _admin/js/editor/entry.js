/**
 * Editor bootstrap and global exports for S2.
 *
 * @copyright 2025-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

import {initArticleEditForm} from './form.js';
import {initHtmlTextarea, initHtmlToolbar} from './shortcuts.js';
import {initTagsInput} from './tags.js';
import {initImagePipeline} from './images/pipeline.js';
import {ClosePictureDialog, ReturnAudio, ReturnImage} from './dialogs.js';
import {setEditorDeps} from './deps.js';
import {initAiTools} from './ai.js';
import {initPublicationState} from './publication.js';
import {initActivityPubPreview} from './activitypub.js';

const configElement = document.querySelector('[data-editor-config]');
let config = {};
if (configElement) {
    try {
        config = JSON.parse(configElement.dataset.editorConfig || '{}');
    } catch (error) {
        console.warn('Unable to parse editor configuration:', error);
    }
}

setEditorDeps({
    PopupMessages: window.PopupMessages,
    s2_lang: window.s2_lang,
    CodeMirror: window.CodeMirror,
    loadingIndicator: window.loadingIndicator,
    sUrl: config.sUrl || window.sUrl || null,
    pictureManagerUrl: config.pictureManagerUrl || null,
    previewErrorStylesheet: config.previewErrorStylesheet || null,
    imageOverlayStylesheet: config.imageOverlayStylesheet || null,
    morphdom: window.morphdom || null,
    DisplayError: window.DisplayError || null
});

window.ReturnImage = ReturnImage;
window.ReturnAudio = ReturnAudio;
window.ClosePictureDialog = ClosePictureDialog;

function bindDatalists(form, datalists) {
    if (!form || !Array.isArray(datalists)) {
        return;
    }
    datalists.forEach(function (item) {
        if (!item || !item.inputName || !item.listId) {
            return;
        }
        const input = form.elements[item.inputName];
        if (!input) {
            return;
        }
        input.setAttribute('list', item.listId);
        if (item.placeholder) {
            input.setAttribute('placeholder', item.placeholder);
        }
    });
}

function initHtmlEditors() {
    document.querySelectorAll('.html-textarea-with-preview-wrapper textarea').forEach(function (textarea) {
        initHtmlTextarea(textarea);
    });

    document.querySelectorAll('.html-toolbar').forEach(function (toolbar) {
        initHtmlToolbar(toolbar);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const formName = config.formName || 'article-form';
    const form = document.forms[formName] || document.getElementById(formName);
    if (form) {
        bindDatalists(form, config.datalists);
    }

    initHtmlEditors();
    initImagePipeline();

    if (form && config.ai) {
        initAiTools(form, {...config.ai, entityName: config.entityName});
    }

    if (form && config.activityPub) {
        initActivityPubPreview(form, {...config.activityPub, entityName: config.entityName});
    }

    if (form && config.entityName && config.textareaName) {
        initPublicationState(form);
        initArticleEditForm(
            form,
            config.statusData,
            config.entityName,
            config.textareaName,
            config.templateId,
            config.slugFieldName || 'url',
            config.templateScope || ''
        );
    }

    if (config.tags && config.tags.inputId && Array.isArray(config.tags.suggestions)) {
        initTagsInput(config.tags);
    }

    document.querySelector('.picture-dialog-close')?.addEventListener('click', ClosePictureDialog);
});
