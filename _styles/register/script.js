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

    document.addEventListener('DOMContentLoaded', function () {
        initCommentReplies();
        initCommentStorage();
        initKeyboardNavigation();
    }, false);
}());
