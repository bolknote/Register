/**
 * Article editor form logic for Register.
 *
 * @copyright 2007-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

import {editorDeps} from './deps.js';
import {hex_md5} from './hash.js';
import {Preview, initPreviewSync} from './preview.js';
import {register_codemirror} from './codemirror.js';
import {escapeHtml, sanitizeUrlForAttribute} from './utils/escape.js';
import {formErrorMessages} from './utils/form-errors.js';
import {createFormRecovery} from './form-recovery.js';
import '../../../_assets/register/editor/request.js';

export function initArticleEditForm(eForm, statusData, sEntityName, sTextareaName, sTemplateId, sSlugFieldName = 'url', sTemplateScope = '', recoveryOptions = {}) {
    const sLowerEntityName = sEntityName.toLowerCase();
    const formUrl = new URL(eForm.action);
    const contentId = formUrl.searchParams.get('id') || 'new';

    function decorateForm(currentStatusData) {
        const publishedInput = eForm.querySelector('input[name="published"]');
        const isPublished = publishedInput?.checked || false;

        if (currentStatusData) {
            const urlWrapper = eForm.querySelector('.field-' + sSlugFieldName);
            const urlLabel = eForm.querySelector('label[for="id-' + sSlugFieldName + '"]');
            if (urlWrapper) {
                urlWrapper.setAttribute('data-url-status', currentStatusData['urlStatus']);
                urlWrapper.title = currentStatusData['urlTitle'];
                if (currentStatusData['urlStatus'] === 'mainpage') {
                    urlWrapper.querySelector('input')?.setAttribute('disabled', 'disabled');
                }
            }
            if (urlLabel) {
                urlLabel.title = currentStatusData['urlTitle'];
            }
        }

        const ePreviewLink = eForm.querySelector('#preview_link');
        if (ePreviewLink) {
            if (currentStatusData) {
                ePreviewLink.href = currentStatusData['url'];
            }
            ePreviewLink.hidden = !isPublished;
        }
    }

    decorateForm(statusData);
    const editorTextarea = eForm.elements[sTextareaName];
    const previewFrame = editorTextarea && editorTextarea.id
        ? document.getElementById(editorTextarea.id + '-preview-frame')
        : null;
    initPreviewSync(eForm, sTextareaName);
    let saving = false;

    async function saveForm(event) {
        event.preventDefault();
        if (saving) return;

        if (!eForm.checkValidity()) {
            eForm.reportValidity();
            return;
        }

        document.dispatchEvent(new Event('save_article_start.register'));
        let submittedSnapshot;

        function successHandler(nextStatusData) {
            editorDeps.PopupMessages.hide(sLowerEntityName + '-save');
            Changes.markSaved(submittedSnapshot, nextStatusData['revision']);
            document.dispatchEvent(new Event('save_article_end.register'));

            eForm.elements['revision'].value = nextStatusData['revision'];
            statusData = nextStatusData;
            decorateForm(statusData);
        }

        const saveFailed = editorDeps.register_lang?.save_failed || 'Unable to save. Please try again.';
        function errorHandler(data) {
            const errors = formErrorMessages(eForm, data, saveFailed);
            const messageId = sLowerEntityName + '-save';
            // PopupMessages deduplicates by id, retaining the first message.
            // Replace the previous attempt with all of this response's errors.
            editorDeps.PopupMessages.hide(messageId);
            editorDeps.PopupMessages.show(errors.join('\n'), null, null, messageId);
            console.warn('Form submission failed');
        }

        function getTempCsrfToken() {
            return document.cookie
                .split('; ')
                .find((row) => row.startsWith('adminyard_temp_csrf_token='))
                ?.split('=')[1] || '';
        }

        const focusBeforeSave = document.activeElement;
        const wasInert = eForm.inert;
        let readOnlyFields = [];
        let unlockEditing = () => {};
        let navigating = false;
        saving = true;
        try {
            const formData = new FormData(eForm);
            submittedSnapshot = Changes.snapshot(formData);
            // Creating a post redirects to its new id, so later edits cannot
            // remain in this form as they can after an ordinary update.
            if (contentId === 'new') {
                readOnlyFields = Array.from(eForm.querySelectorAll('input, textarea'), input => [input, input.readOnly]);
                readOnlyFields.forEach(([input]) => { input.readOnly = true; });
                unlockEditing = register_codemirror.lockEditing();
                eForm.inert = true;
            }
            const headers = {'X-Requested-With': 'XMLHttpRequest'};
            const tempCsrfToken = getTempCsrfToken();
            if (tempCsrfToken !== '') {
                headers['X-AdminYard-CSRF-Token'] = tempCsrfToken;
            }
            const requestOptions = {timeoutMs: editorDeps.saveTimeoutMs, retries: contentId === 'new' ? 0 : 1,
                timeoutMessage: editorDeps.register_lang?.save_timeout};
            let {response, data} = await window.RegisterEditorRequest.requestJson(eForm.action, {
                method: 'POST', headers: headers, body: formData, registerHandleErrorsInline: true
            }, requestOptions);

            if (response.status === 422) {
                if (!data?.invalid_csrf_token) {
                    errorHandler(data);
                    return;
                }
                ({response, data} = await window.RegisterEditorRequest.requestJson(eForm.action, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-AdminYard-CSRF-Token': getTempCsrfToken()
                    },
                    body: formData,
                    registerHandleErrorsInline: true
                }, requestOptions));
            }

            if (response.redirected) {
                Changes.markSaved(submittedSnapshot);
                document.dispatchEvent(new Event('save_article_end.register'));
                eForm.inert = true;
                window.location.assign(response.url);
                navigating = true;
                return;
            }

            if (response.ok && data && data.revision != null) {
                successHandler(data);
            } else {
                errorHandler(data);
            }
        } catch (error) {
            errorHandler(error?.name === 'EditorTimeoutError' ? {errors: [error.message]} : null);
            console.warn('An error occurred:', error);
        } finally {
            if (!navigating) {
                saving = false;
                unlockEditing();
                readOnlyFields.forEach(([input, readOnly]) => { input.readOnly = readOnly; });
                eForm.inert = wasInert;
                if (document.activeElement === document.body
                    && focusBeforeSave instanceof HTMLElement && focusBeforeSave.isConnected) {
                    focusBeforeSave.focus({preventScroll: true});
                }
            }
        }
    }

    eForm.addEventListener('submit', saveForm);
    eForm.addEventListener('publication-state-change', function () {
        decorateForm(statusData);
    });
    document.addEventListener('save_form.register', function (event) {
        event.preventDefault();
        // Run the same validation and field commit listeners as the Save button.
        eForm.requestSubmit();
    });

    document.addEventListener('return_image.register', function (e) {
        let w = e.detail.width;
        let h = e.detail.height;
        let s = e.detail.file_path;
        s = sanitizeUrlForAttribute(s);

        const sOpenTag = '<img src="' + s + '" width="' + w + '" height="' + h + '" ' + 'loading="lazy" alt="',
            sCloseTag = '" />';
        document.dispatchEvent(new CustomEvent('insert_tag.register', {
            detail: {sStart: sOpenTag, sEnd: sCloseTag, imageSrc: s}
        }));

        const dialog = document.getElementById('picture_dialog');
        if (dialog) {
            dialog.close();
        }
    });

    document.addEventListener('return_audio.register', function (e) {
        const src = sanitizeUrlForAttribute(e.detail.file_path);
        const title = escapeHtml(e.detail.title || '');
        const audio = '<audio controls preload="metadata" src="' + src + '" data-title="' + title + '"></audio>';
        document.dispatchEvent(new CustomEvent('insert_tag.register', {detail: {sStart: audio, sEnd: ''}}));

        const dialog = document.getElementById('picture_dialog');
        if (dialog) {
            dialog.close();
        }
    });

    var Changes = (function () {
        const eTextarea = eForm.elements[sTextareaName];
        const eTitle = eForm.elements['title'];
        let previousText = eTextarea.value;
        let previousTitle = eTitle.value;
        const templateInput = eForm.elements['template'];
        const templateId = () => sTemplateId || templateInput?.value || '';
        let previousTemplate = templateId();
        let currentFormHash = '';
        const recovery = createFormRecovery(eForm, {
            ...recoveryOptions, entity: sLowerEntityName, target: contentId,
        }, () => {
            register_codemirror.setValue(eTextarea.value, true);
            register_codemirror.flip();
            checkChanges();
        });

        function persistDraft() {
            recovery.persist();
        }

        function persistCurrentText() {
            register_codemirror.flip();
            persistDraft();
        }

        function checkChanges() {
            document.dispatchEvent(new Event('check_changes_start.register'));

            register_codemirror.flip();
            const currentText = eTextarea.value;
            const currentTitle = eTitle.value;
            const currentTemplate = templateId();
            persistDraft();

            if (previousText !== currentText || previousTitle !== currentTitle || previousTemplate !== currentTemplate) {
                const absoluteUrl = new URL(eForm.action);
                const id = absoluteUrl.searchParams.get('id');
                Preview(
                    currentTitle,
                    currentText,
                    id,
                    currentTemplate,
                    sTemplateScope,
                    previewFrame
                );
                previousText = currentText;
                previousTitle = currentTitle;
                previousTemplate = currentTemplate;
            }
        }

        function wireLivePreview() {
            const updatePreview = debounceWithMaxWait(function () {
                register_codemirror.flip();
                checkChanges();
            }, 300, 3000);

            function handleTextChange() {
                register_codemirror.flip();
                persistDraft();
                updatePreview();
            }

            if (register_codemirror.isReady()) {
                register_codemirror.onChange(handleTextChange);
            } else {
                eTextarea.addEventListener('input', handleTextChange);
            }
            eTitle.addEventListener('input', updatePreview);
            eTitle.addEventListener('change', updatePreview);
            if (!sTemplateId && templateInput) {
                templateInput.addEventListener('input', updatePreview);
                templateInput.addEventListener('change', updatePreview);
            }
        }

        function getFormHash(formData) {
            return hex_md5(JSON.stringify(recovery.snapshot(formData)));
        }

        function markSaved(snapshot, revision) {
            currentFormHash = snapshot.hash;
            recovery.markSaved(snapshot.fields, revision);
            persistCurrentText();
        }

        currentFormHash = hex_md5(JSON.stringify(recovery.baseline));
        wireLivePreview();

        setInterval(checkChanges, 5000);
        const absoluteUrl = new URL(eForm.action);
        const id = absoluteUrl.searchParams.get('id');
        Preview(
            eForm.elements['title'].value,
            eTextarea.value,
            id,
            templateId(),
            sTemplateScope,
            previewFrame
        );
        return {
            persist: persistCurrentText,
            snapshot: formData => ({hash: getFormHash(formData), fields: recovery.snapshot(formData)}),
            markSaved,
            present: function () {
                document.dispatchEvent(new Event('changes_present.register'));

                return currentFormHash !== getFormHash();
            }
        };
    })();

    window.addEventListener('pagehide', Changes.persist);
    window.onbeforeunload = function () {
        Changes.persist();
        if (Changes.present()) {
            return editorDeps.register_lang.unsaved_exit;
        }
    };
}

function debounceWithMaxWait(fn, wait, maxWait) {
    let timerId = null;
    let lastInvoke = 0;

    return function () {
        const now = Date.now();
        const elapsed = now - lastInvoke;

        if (maxWait && elapsed >= maxWait) {
            lastInvoke = now;
            if (timerId) {
                clearTimeout(timerId);
                timerId = null;
            }
            fn();
            return;
        }

        if (timerId) {
            clearTimeout(timerId);
        }

        timerId = setTimeout(function () {
            lastInvoke = Date.now();
            timerId = null;
            fn();
        }, wait);
    };
}
