(() => {
    'use strict';

    const initialize = (demo) => {
        if (!(demo instanceof HTMLElement) || demo.dataset.initialized === 'yes') {
            return;
        }
        demo.dataset.initialized = 'yes';

        const form = demo.querySelector('.files-archive-rotate-controls');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const inputs = [...form.querySelectorAll('input[type="range"][data-css-variable]')];
        const update = (input) => {
            if (!(input instanceof HTMLInputElement)) {
                return;
            }
            const variable = input.dataset.cssVariable;
            if (variable === undefined || !variable.startsWith('--rotate-')) {
                return;
            }

            demo.style.setProperty(variable, input.value + (input.dataset.cssUnit ?? ''));
            const output = form.querySelector(`[data-files-archive-output="${CSS.escape(input.name)}"]`);
            if (output instanceof HTMLOutputElement) {
                output.value = input.value + (input.dataset.displayUnit ?? '');
            }
        };
        const updateAll = () => inputs.forEach(update);

        inputs.forEach((input) => input.addEventListener('input', () => update(input)));
        form.addEventListener('submit', (event) => event.preventDefault());
        form.addEventListener('reset', () => requestAnimationFrame(updateAll));
        updateAll();
    };

    const boot = () => document
        .querySelectorAll('[data-files-archive-demo="in-rotate"]')
        .forEach(initialize);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, {once: true});
    } else {
        boot();
    }
})();
