/** Link-health admin actions. */
(function () {
    'use strict';

    const root = document.querySelector('[data-link-health-admin]');
    if (!root) {
        return;
    }

    const status = root.querySelector('.link-health-action-status');
    root.addEventListener('click', async function (event) {
        const button = event.target.closest('button[data-operation]');
        if (!button || button.disabled) {
            return;
        }

        button.disabled = true;
        status.textContent = root.dataset.workingMessage || '';
        try {
            const response = await fetch(root.dataset.actionEndpoint || '', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    csrf_token: root.dataset.csrfToken || '',
                    operation: button.dataset.operation || '',
                    target_id: button.dataset.targetId || ''
                })
            });
            const payload = await response.json();
            if (!response.ok || payload.success !== true) {
                throw new Error(payload.message || 'Request failed');
            }
            status.textContent = payload.message;
            window.setTimeout(function () {
                window.location.reload();
            }, 350);
        } catch (error) {
            status.textContent = error.message || root.dataset.failureMessage || '';
            button.disabled = false;
        }
    });
}());
