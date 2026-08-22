/** ActivityPub private reader and administration actions. */
(function () {
    'use strict';

    const root = document.querySelector('[data-activitypub-admin]');
    if (!root) {
        return;
    }

    const status = root.querySelector('[data-action-status]');

    if (root.dataset.activationPending === '1') {
        window.setTimeout(function refreshActivation() {
            const active = document.activeElement;
            if (active && active.closest && active.closest('.activitypub-setup-form')) {
                window.setTimeout(refreshActivation, 1800);
                return;
            }
            window.location.reload();
        }, 1800);
    }

    function setStatus(message, isError) {
        if (!status) {
            return;
        }
        status.textContent = message || '';
        status.classList.toggle('is-error', Boolean(isError));
    }

    async function request(parameters) {
        parameters.set('csrf_token', root.dataset.csrfToken || '');
        const response = await fetch(root.dataset.actionEndpoint || '', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: parameters
        });
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error(root.dataset.failureMessage || 'Request failed');
        }
        if (!response.ok || payload.success !== true) {
            throw new Error(payload.message || root.dataset.failureMessage || 'Request failed');
        }

        return payload;
    }

    function parametersForButton(button) {
        const parameters = new URLSearchParams();
        Object.keys(button.dataset).forEach(function (key) {
            if (key === 'operation') {
                parameters.set('operation', button.dataset[key] || '');
                return;
            }
            parameters.set(key.replace(/[A-Z]/g, function (letter) {
                return '_' + letter.toLowerCase();
            }), button.dataset[key] || '');
        });

        return parameters;
    }

    function renderActorPreview(actor) {
        const preview = root.querySelector('[data-actor-preview]');
        if (!preview || !actor) {
            return;
        }
        const name = preview.querySelector('[data-preview-name]');
        const url = preview.querySelector('[data-preview-url]');
        const inbox = preview.querySelector('[data-preview-inbox]');
        const ids = preview.querySelectorAll('[data-preview-actor-id]');
        if (name) {
            name.textContent = (actor.display_name || actor.username || '')
                + ' (@' + (actor.username || '') + ')';
        }
        if (url) {
            url.textContent = actor.url || '';
            url.href = actor.url || '#';
        }
        if (inbox) {
            inbox.textContent = actor.type + ' · ' + actor.inbox;
        }
        ids.forEach(function (id) {
            id.value = String(actor.id || '');
        });
        preview.hidden = false;
        preview.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }

    root.addEventListener('submit', async function (event) {
        const form = event.target.closest('form[data-activitypub-form]');
        if (!form) {
            return;
        }
        event.preventDefault();
        const submitter = event.submitter;
        if (submitter && submitter.dataset.confirm && !window.confirm(submitter.dataset.confirm)) {
            return;
        }
        const controls = Array.from(form.querySelectorAll('button, input, select, textarea'));
        controls.forEach(function (control) {
            control.disabled = true;
        });
        setStatus(root.dataset.workingMessage || '', false);
        try {
            const parameters = new URLSearchParams(new FormData(form));
            if (submitter && submitter.name) {
                parameters.set(submitter.name, submitter.value);
            }
            const payload = await request(parameters);
            setStatus(payload.message || root.dataset.successMessage || '', false);
            if (parameters.get('operation') === 'discover') {
                renderActorPreview(payload.actor);
                controls.forEach(function (control) {
                    control.disabled = false;
                });
                return;
            }
            window.setTimeout(function () {
                window.location.reload();
            }, 350);
        } catch (error) {
            setStatus(error instanceof Error ? error.message : root.dataset.failureMessage || '', true);
            controls.forEach(function (control) {
                control.disabled = false;
            });
        }
    });

    root.addEventListener('click', async function (event) {
        const button = event.target.closest('button[data-operation]');
        if (!button || button.disabled || button.closest('form[data-activitypub-form]')) {
            return;
        }
        button.disabled = true;
        setStatus(root.dataset.workingMessage || '', false);
        try {
            const payload = await request(parametersForButton(button));
            setStatus(payload.message || root.dataset.successMessage || '', false);
            window.setTimeout(function () {
                window.location.reload();
            }, 350);
        } catch (error) {
            setStatus(error instanceof Error ? error.message : root.dataset.failureMessage || '', true);
            button.disabled = false;
        }
    });
}());
