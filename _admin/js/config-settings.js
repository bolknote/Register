/** Coordinates dependent settings and the page-wide autosave state. */
document.addEventListener('DOMContentLoaded', function () {
    const page = document.querySelector('.config-page');
    const pageState = page?.querySelector('[data-config-page-state]');
    if (!page || !pageState) {
        return;
    }

    function controlValue(key) {
        const setting = Array.from(page.querySelectorAll('[data-config-key]')).find(function (candidate) {
            return candidate.dataset.configKey === key && candidate.classList.contains('config-setting');
        });
        const control = setting?.querySelector('select, input:not([type="hidden"]), textarea');
        if (!control) {
            return '';
        }
        if (control instanceof HTMLInputElement && control.type === 'checkbox') {
            return control.checked ? '1' : '0';
        }

        return control.value;
    }

    function updateDependencies() {
        page.querySelectorAll('.config-setting[data-depends-on]').forEach(function (setting) {
            const allowedValues = (setting.dataset.dependsValues || '').split(/\s+/).filter(Boolean);
            const visible = allowedValues.includes(controlValue(setting.dataset.dependsOn || ''));
            setting.hidden = !visible;
            setting.setAttribute('aria-hidden', visible ? 'false' : 'true');
        });
    }

    function updatePageState() {
        const states = Array.from(page.querySelectorAll('[data-config-save-state]')).map(function (state) {
            return state.dataset.state || 'applied';
        });
        let state = 'applied';
        if (states.includes('error')) {
            state = 'error';
        } else if (states.includes('saving')) {
            state = 'saving';
        } else if (states.includes('dirty')) {
            state = 'dirty';
        } else if (states.includes('pending')) {
            state = 'pending';
        }

        const messageKey = state + 'Message';
        pageState.dataset.state = state;
        pageState.textContent = pageState.dataset[messageKey] || '';
    }

    page.addEventListener('change', function (event) {
        if (event.target.closest('.config-setting')) {
            updateDependencies();
        }
    });
    page.addEventListener('register:config-state', updatePageState);
    page.addEventListener('register:config-saved', updateDependencies);

    updateDependencies();
    updatePageState();
});
