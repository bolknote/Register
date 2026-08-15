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
    const saveState = form.querySelector('[data-config-save-state]');
    let savedState = saveState?.dataset.state || 'applied';
    let savedStateText = saveState?.textContent.trim() || messages.saved;
    let submitting = false;

    form.addEventListener('submit', function (event) {
        event.preventDefault();
    });

    function showError(message) {
        errors.textContent = message;
        form.classList.add('has-errors');
        setSaveState('error', message);
    }

    function setSaveState(status, message) {
        if (saveState) {
            saveState.dataset.state = status;
            saveState.textContent = message;
        }
        form.dispatchEvent(new CustomEvent('register:config-state', {
            bubbles: true,
            detail: {state: status, key: form.dataset.configKey || ''}
        }));
    }

    async function save(clear) {
        if (submitting || !input || (!clear && input.value.trim() === '')) {
            return;
        }
        submitting = true;
        errors.textContent = '';
        form.classList.remove('has-errors');
        setSaveState('saving', messages.saving);
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
            savedState = 'applied';
            savedStateText = messages.saved;
            setSaveState(savedState, savedStateText);
            form.classList.add('success');
            setTimeout(function () {
                form.classList.remove('success');
            }, 3000);
            form.dispatchEvent(new CustomEvent('register:config-saved', {
                bubbles: true,
                detail: {key: form.dataset.configKey || ''}
            }));
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
                setSaveState(savedState, savedStateText);
                input.blur();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                save(false);
            }
        });
        input.addEventListener('blur', function () {
            save(false);
        });
        input.addEventListener('input', function () {
            if (input.value.trim() !== '') {
                setSaveState('dirty', messages.dirty);
            } else {
                setSaveState(savedState, savedStateText);
            }
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
            saving: form.dataset.savingMessage || 'Saving…',
            saved: form.dataset.savedMessage || 'Saved',
            dirty: form.dataset.dirtyMessage || 'Not saved',
            error: form.dataset.errorMessage || 'Unable to save the value.',
            clearConfirm: form.dataset.clearConfirmMessage || ''
        });
    });

    document.querySelector('.field-Config-value input[type="color"]')?.addEventListener('change', function (event) {
        document.documentElement.style.setProperty('--page-secondary-background', event.target.value);
    });
});
