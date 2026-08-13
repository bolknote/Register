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

    document.addEventListener('DOMContentLoaded', function () {
        initCommentStorage();
        initKeyboardNavigation();
    }, false);
}());
