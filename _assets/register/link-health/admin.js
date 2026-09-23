/** Link-health admin actions. */
(function () {
    'use strict';

    const root = document.querySelector('[data-link-health-admin]');
    if (!root) {
        return;
    }

    const status = root.querySelector('.link-health-action-status');

    async function performAction(operation, targetId) {
        const response = await fetch(root.dataset.actionEndpoint || '', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                csrf_token: root.dataset.csrfToken || '',
                operation,
                target_id: targetId
            })
        });
        const payload = await response.json();
        if (!response.ok || payload.success !== true) {
            throw new Error(payload.message || 'Request failed');
        }

        return payload;
    }

    function adjustStatusCount(healthStatus, difference) {
        root.querySelectorAll(`[data-status-count="${healthStatus}"]`).forEach(function (counter) {
            const current = Number.parseInt(counter.textContent || '0', 10);
            counter.textContent = String(Math.max(0, (Number.isFinite(current) ? current : 0) + difference));
        });
    }

    function applyHealthStatus(row, payload) {
        const healthStatus = typeof payload.health_status === 'string' ? payload.health_status : '';
        if (!row || healthStatus === '') {
            return;
        }

        const previousStatus = row.dataset.healthStatus || '';
        if (previousStatus !== healthStatus) {
            adjustStatusCount(previousStatus, -1);
            adjustStatusCount(healthStatus, 1);
            row.dataset.healthStatus = healthStatus;
        }

        const badge = row.querySelector('.link-health-status');
        if (badge) {
            badge.className = `link-health-status status-${healthStatus}`;
            badge.textContent = payload.health_status_label || healthStatus;
        }

        if (healthStatus !== 'broken') {
            row.querySelector('.link-health-repair-action')?.remove();
        }

        const currentFilter = root.dataset.filterStatus || '';
        if (currentFilter !== '' && currentFilter !== healthStatus) {
            row.remove();
        }
    }

    root.addEventListener('click', async function (event) {
        const button = event.target.closest('button[data-operation]');
        if (!button || button.disabled) {
            return;
        }

        button.disabled = true;
        status.textContent = root.dataset.workingMessage || '';
        try {
            const payload = await performAction(
                button.dataset.operation || '',
                button.dataset.targetId || ''
            );
            status.textContent = payload.message;
            applyHealthStatus(button.closest('tr'), payload);
            button.disabled = false;
        } catch (error) {
            status.textContent = error.message || root.dataset.failureMessage || '';
            button.disabled = false;
        }
    });

    root.addEventListener('change', async function (event) {
        const toggle = event.target.closest('input[data-ignore-toggle]');
        if (!toggle || toggle.disabled) {
            return;
        }

        const requestedState = toggle.checked;
        toggle.disabled = true;
        status.textContent = root.dataset.workingMessage || '';
        try {
            const payload = await performAction(
                requestedState ? 'ignore' : 'unignore',
                toggle.dataset.targetId || ''
            );
            status.textContent = payload.message;
            applyHealthStatus(toggle.closest('tr'), payload);
            toggle.disabled = false;
        } catch (error) {
            toggle.checked = !requestedState;
            toggle.disabled = false;
            status.textContent = error.message || root.dataset.failureMessage || '';
        }
    });
}());
