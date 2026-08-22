(function () {
    'use strict';

    const root = document.querySelector('[data-register-update]');
    if (!(root instanceof HTMLElement)) {
        return;
    }

    const fileInput = root.querySelector('[data-update-file]');
    const dropZone = root.querySelector('[data-update-drop]');
    const progress = root.querySelector('[data-update-progress]');
    const progressBar = root.querySelector('[data-update-progress-bar]');
    const progressLabel = root.querySelector('[data-update-progress-label]');
    const result = root.querySelector('[data-update-result]');
    const status = root.querySelector('[data-update-status]');
    const applyButton = root.querySelector('[data-update-apply]');
    const passwordInput = root.querySelector('[data-update-password]');
    let sessionId = root.dataset.sessionId || window.sessionStorage.getItem('registerUpdateSession') || '';
    let currentStatus = '';
    let busy = false;

    function setStatus(message, kind) {
        if (!(status instanceof HTMLElement)) {
            return;
        }
        status.textContent = message;
        status.classList.toggle('is-error', kind === 'error');
        status.classList.toggle('is-success', kind === 'success');
    }

    function setProgress(value, label) {
        if (progress instanceof HTMLElement) {
            progress.hidden = false;
        }
        if (progressBar instanceof HTMLProgressElement) {
            progressBar.value = Math.max(0, Math.min(100, value));
        }
        if (progressLabel instanceof HTMLElement) {
            progressLabel.textContent = label;
        }
    }

    async function request(action, values, chunk) {
        const body = new FormData();
        body.set('csrf_token', root.dataset.csrfToken || '');
        Object.entries(values || {}).forEach(function ([key, value]) {
            body.set(key, String(value));
        });
        if (chunk instanceof Blob) {
            body.set('chunk', chunk, 'release.chunk');
        }

        const endpoint = root.dataset.endpoint || '';
        const separator = endpoint.includes('?') ? '&' : '?';
        const response = await fetch(endpoint + separator + 'action=register_update_' + encodeURIComponent(action), {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            s2HandleErrorsInline: true
        });
        let payload;
        try {
            payload = await response.json();
        } catch {
            throw new Error('The server returned an unreadable update response.');
        }
        if (!response.ok || !payload || payload.success !== true || typeof payload.state !== 'object') {
            throw new Error(payload && typeof payload.message === 'string' ? payload.message : 'The update request failed.');
        }

        return payload.state;
    }

    function supportedFilename(name) {
        return /\.(?:zip|tar\.gz|tar\.bz2)$/i.test(name);
    }

    function renderPlan(state) {
        if (!(result instanceof HTMLElement) || !state || typeof state !== 'object') {
            return;
        }
        result.hidden = false;
        currentStatus = typeof state.status === 'string' ? state.status : '';
        const version = root.querySelector('[data-update-version]');
        if (version) {
            version.textContent = state.version || '';
        }
        const plan = state.plan && typeof state.plan === 'object' ? state.plan : {};
        const counters = {
            '[data-update-writes]': Array.isArray(plan.writes) ? plan.writes.length : 0,
            '[data-update-deletes]': Array.isArray(plan.deletes) ? plan.deletes.length : 0,
            '[data-update-unchanged]': Array.isArray(plan.unchanged) ? plan.unchanged.length : 0
        };
        Object.entries(counters).forEach(function ([selector, value]) {
            const element = root.querySelector(selector);
            if (element) {
                element.textContent = String(value);
            }
        });

        const conflicts = Array.isArray(plan.conflicts) ? plan.conflicts : [];
        const conflictBox = root.querySelector('[data-update-conflicts]');
        const conflictList = root.querySelector('[data-update-conflict-list]');
        if (conflictBox instanceof HTMLElement) {
            conflictBox.hidden = conflicts.length === 0;
        }
        if (conflictList) {
            conflictList.replaceChildren(...conflicts.map(function (message) {
                const item = document.createElement('li');
                item.textContent = String(message);
                return item;
            }));
        }

        const approval = root.querySelector('[data-update-approval]');
        if (approval instanceof HTMLElement) {
            approval.hidden = ![
                'ready', 'backing_up', 'applying_files', 'rollback_failed', 'files_switched',
                'migrating', 'opening_site', 'migration_failed'
            ].includes(currentStatus);
        }
        if (applyButton instanceof HTMLButtonElement) {
            applyButton.textContent = [
                'applying_files', 'rollback_failed', 'files_switched',
                'migrating', 'opening_site', 'migration_failed'
            ].includes(currentStatus)
                ? (root.dataset.labelFinalize || 'Retry finalization')
                : (root.dataset.labelInstall || 'Back up and install');
        }
    }

    async function upload(file) {
        if (busy) {
            return;
        }
        const maximum = Number(root.dataset.maxArchiveBytes || 0);
        if (!(file instanceof File) || !supportedFilename(file.name) || file.size < 1 || file.size > maximum) {
            setStatus(root.dataset.messageInvalidFile || 'Select a supported release archive.', 'error');
            return;
        }

        busy = true;
        if (fileInput instanceof HTMLInputElement) {
            fileInput.disabled = true;
        }
        try {
            let state = await request('start', {filename: file.name, size: file.size});
            sessionId = String(state.id || '');
            window.sessionStorage.setItem('registerUpdateSession', sessionId);
            const chunkBytes = Number(root.dataset.chunkBytes || 1048576);
            let offset = Number(state.received || 0);
            while (offset < file.size) {
                const chunk = file.slice(offset, Math.min(file.size, offset + chunkBytes));
                state = await request('chunk', {id: sessionId, offset: offset}, chunk);
                offset = Number(state.received || 0);
                const percent = Math.round((offset / file.size) * 100);
                setProgress(percent, (root.dataset.messageUploading || 'Uploading release') + ' — ' + percent + '%');
            }

            setStatus(root.dataset.messageChecking || 'Checking release');
            state = await request('prepare', {id: sessionId});
            renderPlan(state);
            setStatus(
                state.status === 'ready'
                    ? (root.dataset.messageReady || 'Release ready')
                    : (state.message || 'The release has file conflicts.'),
                state.status === 'ready' ? 'success' : 'error'
            );
            if (fileInput instanceof HTMLInputElement) {
                fileInput.disabled = false;
            }
        } catch (error) {
            setStatus(error instanceof Error ? error.message : String(error), 'error');
            if (fileInput instanceof HTMLInputElement) {
                fileInput.disabled = false;
            }
        } finally {
            busy = false;
        }
    }

    async function apply() {
        if (busy || sessionId === '') {
            return;
        }
        const password = passwordInput instanceof HTMLInputElement ? passwordInput.value : '';
        if (password === '') {
            setStatus(root.dataset.messagePassword || 'Enter your current password.', 'error');
            passwordInput?.focus();
            return;
        }
        const confirmed = window.AdminConfirm
            ? await window.AdminConfirm.ask({
                title: root.dataset.messageConfirm || 'Install this release?',
                message: root.dataset.messageConfirm || '',
                confirmLabel: applyButton instanceof HTMLButtonElement ? applyButton.textContent : '',
                dangerous: false
            })
            : window.confirm(root.dataset.messageConfirm || 'Install this release?');
        if (!confirmed) {
            return;
        }

        busy = true;
        if (applyButton instanceof HTMLButtonElement) {
            applyButton.disabled = true;
        }
        try {
            let state;
            if (![
                'applying_files', 'rollback_failed', 'files_switched',
                'migrating', 'opening_site', 'migration_failed'
            ].includes(currentStatus)) {
                setStatus(root.dataset.messageApplying || 'Applying release');
                state = await request('apply', {id: sessionId, password: password});
                renderPlan(state);
                if (state.status !== 'files_switched') {
                    throw new Error(state.message || 'The release files were not switched.');
                }
            }

            setStatus(root.dataset.messageFinalizing || 'Finalizing release');
            state = await request('finish', {id: sessionId, password: password});
            renderPlan(state);
            if (state.status !== 'complete') {
                throw new Error(state.message || 'The release could not be finalized.');
            }
            if (passwordInput instanceof HTMLInputElement) {
                passwordInput.value = '';
            }
            window.sessionStorage.removeItem('registerUpdateSession');
            setProgress(100, root.dataset.messageComplete || 'Update completed');
            setStatus(root.dataset.messageComplete || 'Update completed', 'success');
            window.setTimeout(function () {
                window.location.reload();
            }, 1200);
        } catch (error) {
            setStatus(error instanceof Error ? error.message : String(error), 'error');
            if (sessionId !== '') {
                try {
                    const state = await request('status', {id: sessionId});
                    renderPlan(state);
                } catch {
                    // Preserve the original error; a reload can start a new upload.
                }
            }
            if (applyButton instanceof HTMLButtonElement) {
                applyButton.disabled = false;
            }
        } finally {
            busy = false;
        }
    }

    if (fileInput instanceof HTMLInputElement) {
        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files[0]) {
                upload(fileInput.files[0]);
            }
        });
    }
    if (dropZone instanceof HTMLElement) {
        ['dragenter', 'dragover'].forEach(function (eventName) {
            dropZone.addEventListener(eventName, function (event) {
                event.preventDefault();
                dropZone.classList.add('is-dragging');
            });
        });
        ['dragleave', 'drop'].forEach(function (eventName) {
            dropZone.addEventListener(eventName, function (event) {
                event.preventDefault();
                dropZone.classList.remove('is-dragging');
            });
        });
        dropZone.addEventListener('drop', function (event) {
            const file = event.dataTransfer && event.dataTransfer.files[0];
            if (file) {
                upload(file);
            }
        });
    }
    applyButton?.addEventListener('click', apply);

    if (sessionId !== '') {
        request('status', {id: sessionId}).then(function (state) {
            if (state.status === 'complete') {
                window.sessionStorage.removeItem('registerUpdateSession');
                return;
            }
            if (state.status === 'uploading' || state.status === 'uploaded') {
                window.sessionStorage.removeItem('registerUpdateSession');
                sessionId = '';
                return;
            }
            renderPlan(state);
            setStatus(state.message || (state.status === 'ready'
                ? (root.dataset.messageReady || 'Release ready')
                : (root.dataset.messageFinalizing || 'Finalizing release')),
            state.status === 'ready' ? 'success' : 'error');
        }).catch(function () {
            window.sessionStorage.removeItem('registerUpdateSession');
            sessionId = '';
        });
    }
}());
