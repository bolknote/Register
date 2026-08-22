(() => {
    'use strict';

    const dialog = () => document.getElementById('public-auth-dialog');

    const statusFor = (element) => {
        const root = element.closest('.public-auth-dialog, .public-auth-panel');
        return root?.querySelector('.public-auth-status') || null;
    };

    const showStatus = (element, message, isError = false) => {
        const status = statusFor(element);
        if (!status) return;
        status.textContent = message;
        status.hidden = !message;
        status.classList.toggle('is-error', isError);
    };

    const setMode = (root, mode, focus = false) => {
        if (!(root instanceof HTMLElement)) return false;
        const panels = Array.from(root.querySelectorAll('[data-public-auth-mode-panel]'));
        const selected = panels.find((panel) => panel.dataset.publicAuthModePanel === mode);
        if (!(selected instanceof HTMLElement)) return false;

        panels.forEach((panel) => {
            panel.hidden = panel !== selected;
        });
        root.dataset.publicAuthMode = mode;
        showStatus(root, '');
        if (focus) {
            requestAnimationFrame(() => {
                selected.querySelector('a.public-auth-provider, input:not([type="hidden"]), button[type="submit"]')?.focus();
            });
        }

        return true;
    };

    const openDialog = (trigger) => {
        const authDialog = dialog();
        if (!(authDialog instanceof HTMLDialogElement) || typeof authDialog.showModal !== 'function') {
            return false;
        }

        const url = new URL(trigger.href, window.location.href);
        const returnPath = url.searchParams.get('return') || `${location.pathname}${location.search}${location.hash}`;
        authDialog.querySelectorAll('input[name="return_path"]').forEach((input) => {
            input.value = returnPath;
        });
        showStatus(authDialog, '');
        const body = authDialog.querySelector('.public-auth-body');
        setMode(body, body?.dataset.publicAuthDefaultMode || 'methods');
        if (!authDialog.open) authDialog.showModal();
        requestAnimationFrame(() => {
            authDialog.querySelector('a.public-auth-provider, input:not([type="hidden"])')?.focus();
        });
        return true;
    };

    const submit = async (form) => {
        const submitButton = form.querySelector('button[type="submit"]');
        const originalButtonLabel = submitButton?.textContent || '';
        form.classList.add('is-busy');
        form.setAttribute('aria-busy', 'true');
        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = true;
            if (form.dataset.busyLabel) submitButton.textContent = form.dataset.busyLabel;
        }
        showStatus(form, '');
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            let payload = null;
            try {
                payload = await response.json();
            } catch (_) {
                // A readable message below is preferable to exposing an HTML error response.
            }
            if (!response.ok || !payload?.success) {
                const fallback = form.closest('.public-auth-body')?.dataset.publicAuthError || 'Unable to sign in.';
                throw new Error(payload?.message || fallback);
            }

            if (typeof payload.redirect === 'string' && payload.redirect) {
                if (window.RegisterNavigation?.navigate) {
                    void window.RegisterNavigation.navigate(payload.redirect, {
                        mode: 'replace',
                        foregroundOnly: true,
                    });
                } else {
                    window.location.assign(payload.redirect);
                }
            } else {
                window.location.reload();
            }
        } catch (error) {
            showStatus(form, error instanceof Error ? error.message : String(error), true);
            form.classList.remove('is-busy');
            form.removeAttribute('aria-busy');
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = false;
                submitButton.textContent = originalButtonLabel;
            }
            form.querySelector('input:not([type="hidden"])')?.focus();
        }
    };

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;

        const modeButton = target.closest('[data-public-auth-mode-open]');
        if (modeButton instanceof HTMLButtonElement) {
            event.preventDefault();
            setMode(
                modeButton.closest('.public-auth-body'),
                modeButton.dataset.publicAuthModeOpen || '',
                true,
            );
            return;
        }

        const open = target.closest('[data-public-auth-open]');
        if (open instanceof HTMLAnchorElement && openDialog(open)) {
            event.preventDefault();
            return;
        }

        if (target.closest('[data-public-auth-close]')) {
            dialog()?.close();
            return;
        }

        const authDialog = dialog();
        if (authDialog instanceof HTMLDialogElement && event.target === authDialog) {
            const rect = authDialog.getBoundingClientRect();
            const inside = event.clientX >= rect.left && event.clientX <= rect.right
                && event.clientY >= rect.top && event.clientY <= rect.bottom;
            if (!inside) authDialog.close();
        }

        document.querySelectorAll('.public-auth-user-menu[open]').forEach((menu) => {
            if (!menu.contains(target)) menu.removeAttribute('open');
        });
    });

    document.addEventListener('submit', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const form = target?.closest('form[data-public-auth-form]');
        if (!(form instanceof HTMLFormElement)) return;
        event.preventDefault();
        void submit(form);
    });
})();
