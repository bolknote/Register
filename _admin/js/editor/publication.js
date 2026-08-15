/**
 * Keeps the editorial publication state in sync with AdminYard's persisted fields.
 *
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

export function initPublicationState(form) {
    const stateFieldset = form.querySelector('[data-publication-state]');
    if (!stateFieldset) {
        return;
    }

    const stateInputs = Array.from(stateFieldset.querySelectorAll('[data-publication-state-input]'));
    const publishedInput = form.querySelector('[data-publication-native-control] input[name="published"]');
    const scheduledField = form.querySelector('[data-publication-scheduled]');
    const scheduledInput = scheduledField?.querySelector('input[name="scheduled_at"]');
    const publishedAtField = form.querySelector('[data-publication-published-at]');
    const submitButton = form.querySelector('[data-publication-submit]');

    function selectedState() {
        return stateInputs.find((input) => input.checked)?.value || 'draft';
    }

    function updateSubmitLabel(state) {
        if (!submitButton) {
            return;
        }

        const label = submitButton.dataset[state + 'Label'];
        if (label) {
            submitButton.textContent = label;
        }
    }

    function applyState(clearIrrelevantSchedule) {
        const state = selectedState();
        stateFieldset.dataset.state = state;

        if (publishedInput) {
            publishedInput.checked = state === 'published';
        }

        if (scheduledField) {
            scheduledField.hidden = state !== 'scheduled';
        }
        if (publishedAtField) {
            publishedAtField.hidden = state !== 'published';
        }
        if (scheduledInput) {
            scheduledInput.required = state === 'scheduled';
            if (clearIrrelevantSchedule && state !== 'scheduled' && scheduledInput.value !== '') {
                scheduledInput.value = '';
                scheduledInput.dispatchEvent(new Event('input', {bubbles: true}));
                scheduledInput.dispatchEvent(new Event('change', {bubbles: true}));
            }
        }

        updateSubmitLabel(state);
        form.dispatchEvent(new CustomEvent('publication-state-change', {detail: {state: state}}));
    }

    stateInputs.forEach(function (input) {
        input.addEventListener('change', function () {
            applyState(true);
        });
    });

    applyState(false);
}
