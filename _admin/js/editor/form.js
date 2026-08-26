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
import {waitForPendingImages} from './images/pipeline.js';

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

    async function saveForm(event) {
        event.preventDefault();

        if (!eForm.checkValidity()) {
            eForm.reportValidity();
            return;
        }

        try {
            await waitForPendingImages();
        } catch (error) {
            editorDeps.PopupMessages.show(
                error && error.message ? error.message : 'Image optimization failed.',
                null,
                null,
                sLowerEntityName + '-save'
            );
            return;
        }

        document.dispatchEvent(new Event('save_article_start.register'));

        function successHandler(nextStatusData) {
            editorDeps.PopupMessages.hide(sLowerEntityName + '-save');
            document.dispatchEvent(new Event('save_article_end.register'));

            eForm.elements['revision'].value = nextStatusData['revision'];
            statusData = nextStatusData;
            decorateForm(statusData);
        }

        function errorHandler(data) {
            Array.from(data.errors).forEach(function (error) {
                // TODO array_merge
                editorDeps.PopupMessages.show(error, null, null, sLowerEntityName + '-save');
            });
            console.warn('Form submission failed');
        }

        function getTempCsrfToken() {
            return document.cookie
                .split('; ')
                .find((row) => row.startsWith('adminyard_temp_csrf_token='))
                ?.split('=')[1] || '';
        }

        try {
            const formData = new FormData(eForm);
            const headers = {'X-Requested-With': 'XMLHttpRequest'};
            const tempCsrfToken = getTempCsrfToken();
            if (tempCsrfToken !== '') {
                headers['X-AdminYard-CSRF-Token'] = tempCsrfToken;
            }
            const response = await fetch(eForm.action, {method: 'POST', headers: headers, body: formData});

            if (response.redirected) {
                document.dispatchEvent(new Event('save_article_end.register'));
                try {
                    localStorage.removeItem(draftStorageKey);
                } catch (error) {
                    console.warn('Unable to remove the local editor draft:', error);
                }
                window.location.assign(response.url);
                return;
            }

            if (response.ok) {
                successHandler(await response.json());
            } else if (response.status === 422) {
                const data = await response.json();
                if (data.invalid_csrf_token) {
                    const response2 = await fetch(eForm.action, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-AdminYard-CSRF-Token': getTempCsrfToken()
                        },
                        body: formData
                    });

                    if (response2.ok) {
                        successHandler(await response2.json());
                    } else if (response2.status === 422) {
                        errorHandler(await response2.json());
                    }
                } else {
                    errorHandler(data);
                }
            }
        } catch (error) {
            console.warn('An error occurred:', error);
        }
    }

    eForm.addEventListener('submit', saveForm);
    eForm.addEventListener('publication-state-change', function () {
        decorateForm(statusData);
    });
    document.addEventListener('save_form.register', saveForm);

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
        let savedText = eTextarea.value;
        let previousText = savedText;
        let currentFormHash = '';

        function readDraft() {
            try {
                return localStorage.getItem(draftStorageKey);
            } catch (error) {
                console.warn('Unable to read the local editor draft:', error);
                return null;
            }
        }

        function removeDraft() {
            try {
                localStorage.removeItem(draftStorageKey);
            } catch (error) {
                console.warn('Unable to remove the local editor draft:', error);
            }
        }

        function persistDraft(currentText) {
            try {
                if (savedText !== currentText) {
                    localStorage.setItem(draftStorageKey, currentText);
                } else {
                    localStorage.removeItem(draftStorageKey);
                }
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
            persistDraft(currentText);

            if (previousText !== currentText) {
                const absoluteUrl = new URL(eForm.action);
                const id = absoluteUrl.searchParams.get('id');
                Preview(
                    eForm.elements['title'].value,
                    currentText,
                    id,
                    sTemplateId || eForm.elements['template'].value,
                    sTemplateScope,
                    previewFrame
                );
                previousText = currentText;
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
        }

        function getFormHash() {
            const formData = new FormData(eForm);
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

        function markSaved() {
            register_codemirror.flip();
            currentFormHash = getFormHash();
            savedText = eTextarea.value;
            removeDraft();
        }

        const recoveredText = readDraft();
        currentFormHash = getFormHash();
        wireLivePreview();

        if (recoveredText !== null && recoveredText !== savedText) {
            if (!register_codemirror.setValue(recoveredText, true)) {
                eTextarea.value = recoveredText;
            }
            register_codemirror.flip();
            previousText = recoveredText;
        } else if (recoveredText !== null) {
            removeDraft();
        }

        setInterval(checkChanges, 5000);
        const absoluteUrl = new URL(eForm.action);
        const id = absoluteUrl.searchParams.get('id');
        Preview(
            eForm.elements['title'].value,
            eTextarea.value,
            id,
            sTemplateId || eForm.elements['template'].value,
            sTemplateScope,
            previewFrame
        );
        document.addEventListener('save_article_end.register', markSaved);

        return {
            persist: persistCurrentText,
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
