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

    const aiAvailability = page.querySelector('[data-ai-availability]');
    const aiAvailabilityKeys = new Set([
        'REGISTER_AI_PROVIDER',
        'REGISTER_AI_API_KEY',
        'REGISTER_AI_MODEL',
        'REGISTER_AI_FOLDER_ID',
        'REGISTER_AI_CLOUDFLARE_ACCOUNT_ID',
        'REGISTER_AI_GIGACHAT_SCOPE'
    ]);
    let availabilityRequest = null;

    function setAiAvailability(state, message) {
        if (!aiAvailability) {
            return;
        }
        aiAvailability.dataset.state = state;
        aiAvailability.textContent = message;
    }

    async function checkAiAvailability(configKey) {
        if (!aiAvailability) {
            return;
        }
        if (controlValue('REGISTER_AI_PROVIDER') === 'disabled') {
            availabilityRequest?.abort();
            setAiAvailability('disabled', aiAvailability.dataset.disabledMessage || '');
            return;
        }

        const setting = Array.from(page.querySelectorAll('.config-setting[data-config-key]')).find(function (candidate) {
            return candidate.dataset.configKey === configKey;
        });
        const form = setting?.querySelector('form[data-config-key]');
        const csrfToken = form?.querySelector('input[name="__csrf_token"]');
        if (!(form instanceof HTMLFormElement) || !(csrfToken instanceof HTMLInputElement)) {
            return;
        }

        availabilityRequest?.abort();
        availabilityRequest = new AbortController();
        setAiAvailability('checking', aiAvailability.dataset.checkingMessage || '');
        const data = new FormData();
        data.set('config_key', configKey);
        data.set('__csrf_token', csrfToken.value);
        try {
            const response = await fetch(aiAvailability.dataset.endpoint || '', {
                method: 'POST',
                body: data,
                signal: availabilityRequest.signal
            });
            const responseData = await response.json().catch(function () {
                return {};
            });
            setAiAvailability(
                typeof responseData.status === 'string' ? responseData.status : 'unavailable',
                typeof responseData.message === 'string'
                    ? responseData.message
                    : (aiAvailability.dataset.errorMessage || '')
            );
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }
            console.warn('Unable to check AI availability:', error);
            setAiAvailability('unavailable', aiAvailability.dataset.errorMessage || '');
        }
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
    page.addEventListener('register:config-saved', function (event) {
        updateDependencies();
        const key = event.detail?.key || '';
        if (aiAvailabilityKeys.has(key)) {
            checkAiAvailability(key);
        }
    });
    page.addEventListener('click', async function (event) {
        const button = event.target instanceof Element
            ? event.target.closest('[data-copy-oauth-callback]')
            : null;
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        const value = button.closest('.oauth-callback-row')?.querySelector('code')?.textContent || '';
        if (!value || !navigator.clipboard) {
            return;
        }
        try {
            await navigator.clipboard.writeText(value);
            button.textContent = button.dataset.copiedLabel || button.textContent;
            window.setTimeout(function () {
                button.textContent = button.dataset.copyLabel || button.textContent;
            }, 1600);
        } catch (_) {
            // The full address remains visible and can still be selected manually.
        }
    });

    updateDependencies();
    updatePageState();
    checkAiAvailability('REGISTER_AI_PROVIDER');
});
