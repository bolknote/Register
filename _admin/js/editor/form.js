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

export function initArticleEditForm(eForm, statusData, sEntityName, sTextareaName, sTemplateId, sSlugFieldName = 'url', sTemplateScope = '') {
    const sLowerEntityName = sEntityName.toLowerCase();
    const formUrl = new URL(eForm.action);
    const contentId = formUrl.searchParams.get('id') || 'new';
    const draftStorageKey = 'register_content_draft:' + sLowerEntityName + ':' + contentId;

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
            Changes.markSaved(submittedSnapshot);
            document.dispatchEvent(new Event('save_article_end.register'));

            eForm.elements['revision'].value = nextStatusData['revision'];
            statusData = nextStatusData;
            decorateForm(statusData);
        }

        const saveFailed = editorDeps.register_lang?.save_failed || 'Unable to save. Please try again.';
        function errorHandler(data, status) {
            const errors = Array.isArray(data?.errors)
                ? data.errors.filter(error => typeof error === 'string' && error.trim() !== '') : [];
            if (errors.length === 0) {
                errors.push(typeof data?.message === 'string' && data.message.trim() !== '' ? data.message : saveFailed);
            }
            errors.forEach(function (error) {
                editorDeps.PopupMessages.show(error, null, null, status === 401 ? 'login' : sLowerEntityName + '-save');
            });
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
            let response = await fetch(eForm.action, {
                method: 'POST', headers: headers, body: formData, registerHandleErrorsInline: true
            });

            if (response.status === 422) {
                const data = await response.json().catch(() => null);
                if (!data?.invalid_csrf_token) {
                    errorHandler(data);
                    return;
                }
                response = await fetch(eForm.action, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-AdminYard-CSRF-Token': getTempCsrfToken()
                    },
                    body: formData,
                    registerHandleErrorsInline: true
                });
            }

            if (response.redirected) {
                Changes.markSaved(submittedSnapshot);
                document.dispatchEvent(new Event('save_article_end.register'));
                eForm.inert = true;
                window.location.assign(response.url);
                navigating = true;
                return;
            }

            const data = await response.json().catch(() => null);
            if (response.ok && data && data.revision != null) {
                successHandler(data);
            } else {
                errorHandler(data, response.status);
            }
        } catch (error) {
            errorHandler(null);
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
        // Firefox can restore an unsaved textarea value before initialization.
        // Only the server-rendered default is the saved body in that case.
        let savedText = eTextarea.defaultValue;
        let previousText = eTextarea.value;
        let previousTitle = eTitle.value;
        const templateInput = eForm.elements['template'];
        const templateId = () => sTemplateId || templateInput?.value || '';
        let previousTemplate = templateId();
        let currentFormHash = '';
        let lastDraft = null;
        let lastPersistedText = savedText;
        let lastPersistedSavedText = savedText;

        function readDraft() {
            try {
                return localStorage.getItem(draftStorageKey);
            } catch (error) {
                console.warn('Unable to read the local editor draft:', error);
                return null;
            }
        }

        function removeDraft(expected) {
            if (expected !== null && localStorage.getItem(draftStorageKey) === expected) {
                localStorage.removeItem(draftStorageKey);
            }
        }

        function persistDraft(currentText) {
            // An idle tab must not replace a more recent copy on its preview
            // timer or on exit. Only a text change or a completed save writes.
            if (currentText === lastPersistedText && savedText === lastPersistedSavedText) return;
            try {
                if (savedText !== currentText) {
                    localStorage.setItem(draftStorageKey, currentText);
                    lastDraft = currentText;
                } else {
                    removeDraft(lastDraft);
                    lastDraft = null;
                }
                lastPersistedText = currentText;
                lastPersistedSavedText = savedText;
            } catch (error) {
                console.warn('Unable to save the local editor draft:', error);
            }
        }

        function persistCurrentText() {
            register_codemirror.flip();
            persistDraft(eTextarea.value);
        }

        function checkChanges() {
            document.dispatchEvent(new Event('check_changes_start.register'));

            register_codemirror.flip();
            const currentText = eTextarea.value;
            const currentTitle = eTitle.value;
            const currentTemplate = templateId();
            persistDraft(currentText);

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
                persistDraft(eTextarea.value);
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

        function getFormHash(formData = new FormData(eForm)) {
            const visibleFormData = new FormData();

            for (const [key, value] of formData.entries()) {
                const inputElement = eForm.elements[key];
                if (inputElement.type !== 'hidden') {
                    visibleFormData.append(key, value);
                }
            }

            const serializedData = Array.from(visibleFormData).map(function (pair) {
                return pair[0] + '=' + pair[1];
            }).join('&');

            return hex_md5(serializedData);
        }

        function markSaved(snapshot) {
            currentFormHash = snapshot.hash;
            savedText = snapshot.text;
            persistCurrentText();
        }

        const recoveredText = readDraft();
        lastDraft = recoveredText;
        lastPersistedText = recoveredText ?? savedText;
        const initialFormData = new FormData(eForm);
        initialFormData.set(sTextareaName, savedText);
        currentFormHash = getFormHash(initialFormData);
        wireLivePreview();

        if (recoveredText !== null && recoveredText !== savedText) {
            if (!register_codemirror.setValue(recoveredText, true)) {
                eTextarea.value = recoveredText;
            }
            register_codemirror.flip();
            previousText = recoveredText;
        } else if (recoveredText !== null) {
            try {
                removeDraft(recoveredText);
                lastDraft = null;
            } catch (error) {
                console.warn('Unable to remove the local editor draft:', error);
            }
        }

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
            snapshot: formData => ({hash: getFormHash(formData), text: formData.get(sTextareaName)}),
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
