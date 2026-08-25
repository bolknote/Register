/** Incremental Telegram import admin form. */
(function () {
    'use strict';

    const root = document.querySelector('[data-telegram-import-admin]');
    if (!root) {
        return;
    }
    const form = root.querySelector('[data-telegram-import-form]');
    const status = root.querySelector('[data-telegram-import-status]');
    const result = root.querySelector('[data-telegram-import-result]');
    const output = result.querySelector('pre');
    const button = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        button.disabled = true;
        result.hidden = true;
        status.textContent = root.dataset.workingMessage || '';
        try {
            const response = await fetch(root.dataset.actionEndpoint || '', {
                method: 'POST',
                body: new FormData(form)
            });
            const payload = await response.json();
            if (!response.ok || payload.success !== true) {
                throw new Error(payload.message || 'Import failed');
            }
            status.textContent = payload.message || '';
            output.textContent = JSON.stringify(payload.report, null, 2);
            result.hidden = false;
            form.reset();
        } catch (error) {
            status.textContent = error.message || 'Import failed';
        } finally {
            button.disabled = false;
        }
    });
}());
