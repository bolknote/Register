(function () {
    'use strict';

    var cspSafeInteractionsReady = false;
    var activeCommentStorageForm = null;
    var commentStorageSaves = new WeakMap();
    var commentStorageTimer = null;

    function initCspSafeInteractions() {
        if (!cspSafeInteractionsReady) {
            cspSafeInteractionsReady = true;
            document.addEventListener('click', function (event) {
                var link = event.target.closest ? event.target.closest('[data-history-back]') : null;
                if (!link) {
                    return;
                }
                event.preventDefault();
                window.history.back();
            }, false);
            document.addEventListener('load', function (event) {
                if (event.target.matches?.('img[data-youtube-fallback-width]')) {
                    applyYouTubeFallback(event.target);
                }
            }, true);
        }

        function applyYouTubeFallback(image) {
            if (image.dataset.youtubeFallbackDone === '1' || !image.complete) {
                return;
            }

            var minimumWidth = Number.parseInt(image.dataset.youtubeFallbackWidth || '0', 10);
            image.dataset.youtubeFallbackDone = '1';
            if (image.naturalWidth < minimumWidth) {
                image.src = image.src.replace(
                    image.dataset.youtubeFallbackFrom || '',
                    image.dataset.youtubeFallbackTo || ''
                );
            }
        }

        document.querySelectorAll('img[data-youtube-fallback-width]').forEach(applyYouTubeFallback);
    }

    function initCommentStorage() {
        var form = document.getElementById('comment-form');
        activeCommentStorageForm = form || null;
        if (!form) {
            return;
        }
        if (form.dataset.commentStorageReady === '1') {
            return;
        }

        var textField = form.querySelector('.comment-editor-source');
        var nameField = form.querySelector('#comment-name');
        var emailField = form.querySelector('#comment-email');
        var idField = form.querySelector('.comment-form-id');

        if (!textField || !idField) {
            return;
        }

        var textKey = 'comment_text_' + idField.value;

        function save() {
            if (textField.value) {
                localStorage.setItem(textKey, textField.value);
            } else {
                localStorage.removeItem(textKey);
            }

            if (nameField) {
                localStorage.setItem('comment_name', nameField.value);
            }
            if (emailField) {
                localStorage.setItem('comment_email', emailField.value);
            }
        }

        try {
            if (!window.localStorage) {
                return;
            }

            form.dataset.commentStorageReady = '1';
            localStorage.removeItem('comment_showemail');

            if (document.cookie.indexOf('comment_form_sent=') !== -1) {
                document.cookie = 'comment_form_sent=0; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/';
                localStorage.removeItem(textKey);
            } else {
                textField.value = textField.value || localStorage.getItem(textKey) || '';
            }

            if (nameField) {
                nameField.value = nameField.value || localStorage.getItem('comment_name') || '';
            }
            if (emailField) {
                emailField.value = emailField.value || localStorage.getItem('comment_email') || '';
            }
            [textField, nameField, emailField].filter(Boolean).forEach(function (field) {
                field.addEventListener('change', save, false);
            });
            form.addEventListener('submit', save, false);
            commentStorageSaves.set(form, save);
            if (commentStorageTimer === null) {
                commentStorageTimer = window.setInterval(function () {
                    var activeSave = activeCommentStorageForm
                        ? commentStorageSaves.get(activeCommentStorageForm)
                        : null;
                    if (activeSave) {
                        activeSave();
                    }
                }, 5000);
            }
        } catch (error) {
            // Browsers may disable local storage. Commenting must still work.
        }
    }

    initCspSafeInteractions();

    function initKeyboardNavigation() {
        document.addEventListener('keydown', function (event) {
            if (!(event.ctrlKey || event.metaKey)) {
                return;
            }

            var activeElement = document.activeElement;
            if (activeElement && (/^(INPUT|TEXTAREA|SELECT)$/.test(activeElement.tagName) || activeElement.isContentEditable)) {
                return;
            }

            var relation = {
                ArrowLeft: 'prev',
                ArrowRight: 'next',
                ArrowUp: 'up'
            }[event.key];

            var link = relation ? document.querySelector('link[rel="' + relation + '"]') : null;
            if (link) {
                if (window.RegisterNavigation) {
                    window.RegisterNavigation.navigate(link.href);
                } else {
                    window.location.assign(link.href);
                }
            }
        }, false);
    }

    function initCommentReplies(root) {
        root = root || document;
        var block = document.querySelector('.comment-form-block');
        var form = document.getElementById('comment-form');
        if (!block || !form) {
            return;
        }

        var parentField = form.querySelector('.comment-parent-id');
        var numberField = form.querySelector('.comment-reply-number');
        var nameField = form.querySelector('.comment-reply-name');
        var textField = form.querySelector('.comment-editor-source');
        var context = block.querySelector('.comment-reply-context');
        var contextTarget = block.querySelector('.comment-reply-target');
        var cancelButton = block.querySelector('.comment-reply-cancel');
        var originLink = block.querySelector('.comment-form-origin');
        if (!parentField || !numberField || !nameField || !context || !contextTarget || !cancelButton || !originLink) {
            return;
        }

        var originParent = block.parentNode;
        var originNextSibling = block.nextSibling;

        function restoreOrigin() {
            if (originNextSibling && originNextSibling.parentNode === originParent) {
                originParent.insertBefore(block, originNextSibling);
            } else {
                originParent.appendChild(block);
            }
        }

        function setReply(link, focusText) {
            var commentId = link.getAttribute('data-reply-comment') || '';
            var number = link.getAttribute('data-reply-number') || '';
            var name = link.getAttribute('data-reply-name') || '';
            var actions = link.closest('.comment-actions');

            parentField.value = commentId;
            numberField.value = number;
            nameField.value = name;
            contextTarget.textContent = name || ('№ ' + number);
            contextTarget.href = '#' + number;
            context.hidden = false;

            if (actions) {
                actions.insertAdjacentElement('afterend', block);
            }

            try {
                window.history.replaceState(window.history.state, '', link.href);
            } catch (error) {
                // Replying still works if the browser disallows History API updates.
            }

            if (focusText && textField) {
                if (!window.RegisterCommentEditor?.focus(form)) {
                    textField.focus();
                }
            }
        }

        root.querySelectorAll('.comment-reply').forEach(function (link) {
            if (link.dataset.commentReplyBound === '1') {
                return;
            }
            link.dataset.commentReplyBound = '1';
            link.addEventListener('click', function (event) {
                event.preventDefault();
                setReply(link, true);
            }, false);
        });

        if (cancelButton.dataset.commentReplyBound !== '1') {
            cancelButton.dataset.commentReplyBound = '1';
            cancelButton.addEventListener('click', function () {
                parentField.value = '';
                numberField.value = '0';
                nameField.value = '';
                context.hidden = true;
                restoreOrigin();

                try {
                    window.history.replaceState(null, '', originLink.href);
                } catch (error) {
                    // The form has already returned to its original place.
                }
                document.dispatchEvent(new CustomEvent('register:live-unlock'));
            }, false);
        }

        if (parentField.value) {
            var activeReply = document.querySelector('.comment-reply[data-reply-comment="' + parentField.value + '"]');
            if (activeReply) {
                setReply(activeReply, false);
            }
        }
    }

    function initCommentModeration(root) {
        root = root || document;
        var moderationForms = root.querySelectorAll('.comment-moderation-action, .comment-edit-form');
        if (!moderationForms.length) {
            return;
        }

        var confirmationTemplate = document.getElementById('comment-action-confirmation-template');
        var activeConfirmation = null;

        function askForConfirmation(form) {
            var confirmation = form.getAttribute('data-confirm');
            var moderationAction = form.getAttribute('data-moderation-action');
            if (!confirmation || !confirmationTemplate) {
                return Promise.resolve(true);
            }

            if (activeConfirmation) {
                activeConfirmation(false);
            }

            return new Promise(function (resolve) {
                var item = form.closest('.comment-item');
                var commentBody = item && item.querySelector(':scope > .comment-body');
                var sourceButton = form.querySelector('button[type="submit"]');
                var confirmationElement = confirmationTemplate.content.firstElementChild.cloneNode(true);
                var question = confirmationElement.querySelector('.comment-action-question');
                var confirmButton = confirmationElement.querySelector('.comment-action-confirm');
                var cancelButton = confirmationElement.querySelector('.comment-action-cancel');
                var finished = false;

                if (!item || !sourceButton || !question || !confirmButton || !cancelButton) {
                    resolve(true);
                    return;
                }

                function finish(confirmed) {
                    if (finished) {
                        return;
                    }

                    finished = true;
                    activeConfirmation = null;
                    item.classList.remove('is-confirming');
                    form.classList.remove('is-confirming-source');
                    confirmationElement.remove();
                    sourceButton.focus();
                    resolve(confirmed);
                }

                activeConfirmation = finish;
                question.textContent = confirmation;
                confirmationElement.setAttribute('aria-label', confirmation);
                if (moderationAction) {
                    confirmationElement.setAttribute('data-action', moderationAction);
                }
                confirmButton.textContent = sourceButton.getAttribute('aria-label') || sourceButton.title;
                cancelButton.addEventListener('click', function () {
                    finish(false);
                }, false);
                confirmButton.addEventListener('click', function () {
                    finish(true);
                }, false);
                confirmationElement.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        finish(false);
                    }
                }, false);

                item.classList.add('is-confirming');
                form.classList.add('is-confirming-source');
                if (commentBody) {
                    item.insertBefore(confirmationElement, commentBody);
                } else {
                    item.appendChild(confirmationElement);
                }
                confirmButton.focus();
            });
        }

        function showError(form, message) {
            var item = form.closest('.comment-item');
            if (!item) {
                return;
            }

            var previousError = item.querySelector(':scope > .comment-moderation-error');
            if (previousError) {
                previousError.remove();
            }

            var error = document.createElement('p');
            error.className = 'comment-moderation-error';
            error.setAttribute('role', 'alert');
            error.textContent = message;

            var actions = item.querySelector(':scope > .comment-actions');
            if (actions) {
                actions.insertAdjacentElement('afterend', error);
            } else {
                item.appendChild(error);
            }
        }

        function removeCommentFromThread(item) {
            var section = item.closest('.comments-section');
            var countElement = section && section.querySelector('.comment-count');
            var removedCount = item.querySelectorAll('.comment-item').length + 1;

            item.remove();
            if (!countElement) {
                return;
            }

            var currentCount = Number.parseInt(countElement.textContent || '', 10);
            if (Number.isSafeInteger(currentCount)) {
                countElement.textContent = String(Math.max(0, currentCount - removedCount));
            }
        }

        function submit(form) {
            askForConfirmation(form).then(function (confirmed) {
                if (!confirmed) {
                    return;
                }

                var buttons = form.querySelectorAll('button');
                buttons.forEach(function (button) {
                    button.disabled = true;
                });

                window.fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }).then(function (response) {
                    return response.json().catch(function () {
                        return {success: false, message: 'Не удалось обработать ответ сервера.'};
                    }).then(function (payload) {
                        if (!response.ok || !payload.success) {
                            throw new Error(payload.message || 'Не удалось изменить комментарий.');
                        }

                        return payload;
                    });
                }).then(function (payload) {
                    var item = form.closest('.comment-item');
                    var isSpamAction = payload.action === 'spam';
                    var activeElement = document.activeElement;
                    if (activeElement && form.contains(activeElement) && typeof activeElement.blur === 'function') {
                        activeElement.blur();
                    }

                    if (isSpamAction && item) {
                        removeCommentFromThread(item);
                    }

                    var anchorField = form.elements.comment_anchor;
                    if (!isSpamAction && anchorField && anchorField.value) {
                        try {
                            window.history.replaceState(window.history.state, '', '#' + anchorField.value);
                        } catch (error) {
                            // The moderation change is already stored.
                        }
                    }
                    if (document.querySelector('meta[name="register-live-updates"]')) {
                        if (item && !isSpamAction) {
                            item.classList.remove('is-editing');
                        }
                        document.dispatchEvent(new CustomEvent('register:live-refresh'));
                    } else {
                        window.location.reload();
                    }
                }).catch(function (error) {
                    buttons.forEach(function (button) {
                        button.disabled = false;
                    });
                    showError(form, error.message);
                });
            });
        }

        moderationForms.forEach(function (form) {
            if (form.dataset.commentModerationBound === '1') {
                return;
            }
            form.dataset.commentModerationBound = '1';
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                submit(form);
            }, false);
        });

        root.querySelectorAll('.comment-edit-start').forEach(function (button) {
            if (button.dataset.commentEditBound === '1') {
                return;
            }
            button.dataset.commentEditBound = '1';
            button.addEventListener('click', function () {
                var item = button.closest('.comment-item');
                var form = item ? item.querySelector(':scope > .comment-edit-form') : null;
                if (!item || !form) {
                    return;
                }

                item.classList.add('is-editing');
                form.hidden = false;
                var textarea = form.querySelector('textarea');
                if (!window.RegisterCommentEditor?.focus(form) && textarea) {
                    textarea.focus();
                    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
                }
            }, false);
        });

        root.querySelectorAll('.comment-edit-cancel').forEach(function (button) {
            if (button.dataset.commentEditBound === '1') {
                return;
            }
            button.dataset.commentEditBound = '1';
            button.addEventListener('click', function () {
                var form = button.closest('.comment-edit-form');
                var item = form ? form.closest('.comment-item') : null;
                if (!form || !item) {
                    return;
                }

                form.hidden = true;
                item.classList.remove('is-editing');
                var startButton = item.querySelector(':scope > .comment-moderation .comment-edit-start');
                if (startButton) {
                    startButton.focus();
                }
                document.dispatchEvent(new CustomEvent('register:live-unlock'));
            }, false);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initCommentReplies();
        initCommentModeration();
        initCommentStorage();
        initKeyboardNavigation();
    }, false);

    document.addEventListener('register:fragment-updated', function (event) {
        var root = event.detail && event.detail.root;
        if (!(root instanceof Element)) {
            return;
        }

        initCommentReplies(root);
        initCommentModeration(root);
        initCommentStorage();
        initCspSafeInteractions();
    }, false);

    document.addEventListener('register:navigation-will-update', function () {
        activeCommentStorageForm = null;
    }, false);
}());
