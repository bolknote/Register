/** Safely edits secret configuration values without rendering the stored value into HTML. */
function makeSecretInlineForm(formId, messages) {
    const form = document.getElementById(formId);
    if (!form) {
        return;
    }

    const input = form.querySelector('input[type="password"]');
    const clearButton = form.querySelector('.secret-clear-button');
    const state = document.getElementById(formId + '-state');
    const errors = form.querySelector('.validation-errors');
    let submitting = false;

    form.addEventListener('submit', function (event) {
        event.preventDefault();
    });

    function showError(message) {
        errors.textContent = message;
        form.classList.add('has-errors');
    }

    async function save(clear) {
        if (submitting || !input || (!clear && input.value.trim() === '')) {
            return;
        }
        submitting = true;
        errors.textContent = '';
        form.classList.remove('has-errors');
        const data = new FormData(form);
        if (clear) {
            data.set(input.name, '');
        }

        try {
            const response = await fetch(form.action, {method: 'POST', body: data});
            const responseData = await response.json();
            if (!response.ok) {
                const errorList = Array.isArray(responseData.errors) ? responseData.errors : [messages.error];
                showError(errorList.join(' '));
                return;
            }

            input.value = '';
            state.textContent = clear ? messages.empty : messages.configured;
            clearButton.hidden = clear;
            form.classList.add('success');
            setTimeout(function () {
                form.classList.remove('success');
            }, 3000);
        } catch (error) {
            console.warn('Unable to save secret setting:', error);
            showError(messages.error);
        } finally {
            submitting = false;
        }
    }

    if (input) {
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                input.value = '';
                input.blur();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                save(false);
            }
        });
        input.addEventListener('blur', function () {
            save(false);
        });
    }

    clearButton.addEventListener('click', function () {
        if (window.confirm(messages.clearConfirm)) {
            save(true);
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-secret-inline-form]').forEach(function (form) {
        makeSecretInlineForm(form.id, {
            configured: form.dataset.configuredMessage || '',
            empty: form.dataset.emptyMessage || '',
            error: form.dataset.errorMessage || 'Unable to save the value.',
            clearConfirm: form.dataset.clearConfirmMessage || ''
        });
    });

    document.querySelector('.field-Config-value input[type="color"]')?.addEventListener('change', function (event) {
        document.documentElement.style.setProperty('--page-secondary-background', event.target.value);
    });
});
