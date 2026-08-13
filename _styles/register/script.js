(function () {
    'use strict';

    function initCommentStorage() {
        var form = document.forms.post_comment;
        if (!form) {
            return;
        }

        var fields = form.elements;
        var textField = fields.text;
        var nameField = fields.name;
        var emailField = fields.email;
        var showEmailField = fields.show_email;
        var idField = fields.id;

        if (!textField || !nameField || !emailField || !showEmailField || !idField) {
            return;
        }

        var textKey = 'comment_text_' + idField.value;

        function save() {
            if (textField.value) {
                localStorage.setItem(textKey, textField.value);
            } else {
                localStorage.removeItem(textKey);
            }

            localStorage.setItem('comment_name', nameField.value);
            localStorage.setItem('comment_email', emailField.value);
            localStorage.setItem('comment_showemail', showEmailField.checked ? '1' : '0');
        }

        try {
            if (!window.localStorage) {
                return;
            }

            if (document.cookie.indexOf('comment_form_sent=') !== -1) {
                document.cookie = 'comment_form_sent=0; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/';
                localStorage.removeItem(textKey);
            } else {
                textField.value = textField.value || localStorage.getItem(textKey) || '';
            }

            nameField.value = nameField.value || localStorage.getItem('comment_name') || '';
            emailField.value = emailField.value || localStorage.getItem('comment_email') || '';
            showEmailField.checked = showEmailField.checked || localStorage.getItem('comment_showemail') === '1';

            [textField, nameField, emailField, showEmailField].forEach(function (field) {
                field.addEventListener('change', save, false);
            });
            form.addEventListener('submit', save, false);
            window.setInterval(save, 5000);
        } catch (error) {
            // Browsers may disable local storage. Commenting must still work.
        }
    }

    function initKeyboardNavigation() {
        var links = {};

        document.querySelectorAll('link[rel]').forEach(function (link) {
            if (['next', 'prev', 'up'].indexOf(link.rel) !== -1) {
                links[link.rel] = link.href;
            }
        });

        document.addEventListener('keydown', function (event) {
            if (!(event.ctrlKey || event.metaKey)) {
                return;
            }

            var activeElement = document.activeElement;
            if (activeElement && /^(INPUT|TEXTAREA|SELECT)$/.test(activeElement.tagName)) {
                return;
            }

            var relation = {
                ArrowLeft: 'prev',
                ArrowRight: 'next',
                ArrowUp: 'up'
            }[event.key];

            if (relation && links[relation]) {
                window.location.assign(links[relation]);
            }
        }, false);
    }

    function initCommentReplies() {
        var block = document.querySelector('.comment-form-block');
        var form = document.forms.post_comment;
        if (!block || !form) {
            return;
        }

        var parentField = form.elements.parent_id;
        var numberField = form.elements.reply_number;
        var nameField = form.elements.reply_name;
        var textField = form.elements.text;
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
                window.history.replaceState(null, '', link.href);
            } catch (error) {
                // Replying still works if the browser disallows History API updates.
            }

            if (focusText && textField) {
                textField.focus();
            }
        }

        document.querySelectorAll('.comment-reply').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                setReply(link, true);
            }, false);
        });

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
        }, false);

        if (parentField.value) {
            var activeReply = document.querySelector('.comment-reply[data-reply-comment="' + parentField.value + '"]');
            if (activeReply) {
                setReply(activeReply, false);
            }
        }
    }

    function initCommentModeration() {
        var moderationForms = document.querySelectorAll('.comment-moderation-action, .comment-edit-form');
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
                item.appendChild(confirmationElement);
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
                }).then(function () {
                    var anchorField = form.elements.comment_anchor;
                    if (anchorField && anchorField.value) {
                        try {
                            window.history.replaceState(null, '', '#' + anchorField.value);
                        } catch (error) {
                            // The moderation change is already stored; reloading is enough.
                        }
                    }
                    window.location.reload();
                }).catch(function (error) {
                    buttons.forEach(function (button) {
                        button.disabled = false;
                    });
                    showError(form, error.message);
                });
            });
        }

        moderationForms.forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                submit(form);
            }, false);
        });

        document.querySelectorAll('.comment-edit-start').forEach(function (button) {
            button.addEventListener('click', function () {
                var item = button.closest('.comment-item');
                var form = item ? item.querySelector(':scope > .comment-edit-form') : null;
                if (!item || !form) {
                    return;
                }

                item.classList.add('is-editing');
                form.hidden = false;
                var textarea = form.querySelector('textarea');
                if (textarea) {
                    textarea.focus();
                    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
                }
            }, false);
        });

        document.querySelectorAll('.comment-edit-cancel').forEach(function (button) {
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
            }, false);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initCommentReplies();
        initCommentModeration();
        initCommentStorage();
        initKeyboardNavigation();
    }, false);
}());
