(() => {
    'use strict';

    const dialog = () => document.getElementById('public-auth-dialog');
    let dialogOpener = null;

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

    const syncEmailValidation = (form, showError = false) => {
        if (!(form instanceof HTMLFormElement) || form.classList.contains('is-busy')) return;
        const input = form.querySelector('input[type="email"]');
        if (!(input instanceof HTMLInputElement)) return;
        const hasError = showError && !input.validity.valid;
        const error = form.querySelector('[data-public-auth-email-error]');
        input.setAttribute('aria-invalid', hasError ? 'true' : 'false');
        if (error instanceof HTMLElement) error.hidden = !hasError;
    };

    const focusMode = (root, selected) => requestAnimationFrame(() => {
        if (selected.hidden || !root.isConnected || !selected.getClientRects().length) return;
        selected.querySelector('.public-auth-method, input:not([type="hidden"]), button[type="submit"]')?.focus({preventScroll: true});
    });

    const setMode = (root, mode, focus = false) => {
        if (!(root instanceof HTMLElement)) return false;
        const panels = Array.from(root.querySelectorAll('[data-public-auth-mode-panel]'));
        const selected = panels.find((panel) => panel.dataset.publicAuthModePanel === mode);
        if (!(selected instanceof HTMLElement)) return false;

        const previous = panels.find((panel) => !panel.hidden);
        panels.forEach((panel) => {
            panel.hidden = panel !== selected;
        });
        root.dataset.publicAuthMode = mode;
        const container = root.closest('.public-auth-dialog, .public-auth-panel');
        const title = container?.querySelector('[data-public-auth-title]');
        if (title) title.textContent = selected.dataset.publicAuthHeading || '';
        const back = container?.querySelector('.public-auth-mode-back');
        if (back) back.hidden = mode === 'methods';
        showStatus(root, '');
        if (previous !== selected && selected.getClientRects().length
            && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
            selected.getAnimations().forEach((animation) => animation.cancel());
            selected.animate([
                {opacity: 0, transform: `translateX(${mode === 'methods' ? -8 : 8}px)`},
                {opacity: 1, transform: 'none'},
            ], {duration: 170, easing: 'ease-out'});
        }
        if (focus) focusMode(root, selected);

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
        authDialog.querySelectorAll('a[data-public-auth-mode-open], form.public-auth-provider-form').forEach((element) => {
            const attribute = element instanceof HTMLFormElement ? 'action' : 'href';
            const target = new URL(element.getAttribute(attribute), location.href);
            target.searchParams.set('return', returnPath);
            element.setAttribute(attribute, target.pathname + target.search);
        });
        syncEmailValidation(authDialog.querySelector('.public-auth-email-form'));
        showStatus(authDialog, '');
        const body = authDialog.querySelector('.public-auth-body');
        setMode(body, body?.dataset.publicAuthDefaultMode || 'methods');
        if (!authDialog.open) {
            dialogOpener = trigger;
            authDialog.showModal();
        }
        const selected = body?.querySelector('[data-public-auth-mode-panel]:not([hidden])');
        if (selected) focusMode(body, selected);
        return true;
    };

    const submit = async (form) => {
        if (form.classList.contains('is-busy')) return;
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
            syncEmailValidation(form);
            if (form.getClientRects().length) form.querySelector('input:not([type="hidden"])')?.focus();
        }
    };

    for (const type of ['input', 'invalid']) {
        document.addEventListener(type, (event) => {
            const input = event.target;
            if (!(input instanceof HTMLInputElement) || input.type !== 'email'
                || !input.form?.classList.contains('public-auth-email-form')) return;
            if (type === 'invalid') {
                event.preventDefault();
                input.focus();
            }
            syncEmailValidation(input.form, type !== 'input');
        }, type === 'invalid');
    }

    document.addEventListener('close', (event) => {
        if (event.target !== dialog()) return;
        event.target.querySelectorAll('.public-auth-password-input input').forEach((input) => {
            input.value = '';
            input.type = 'password';
        });
        event.target.querySelectorAll('[data-public-auth-password-toggle]').forEach((toggle) => {
            toggle.setAttribute('aria-pressed', 'false');
            toggle.setAttribute('aria-label', toggle.dataset.showLabel || '');
        });
        if (dialogOpener instanceof HTMLElement && dialogOpener.isConnected) dialogOpener.focus({preventScroll: true});
        dialogOpener = null;
    }, true);

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;

        const modeButton = target.closest('[data-public-auth-mode-open]');
        if (modeButton instanceof HTMLButtonElement || modeButton instanceof HTMLAnchorElement) {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const container = modeButton.closest('.public-auth-dialog, .public-auth-panel');
            if (setMode(
                container?.querySelector('.public-auth-body'),
                modeButton.dataset.publicAuthModeOpen || '',
                true,
            )) event.preventDefault();
            return;
        }

        const passwordToggle = target.closest('[data-public-auth-password-toggle]');
        if (passwordToggle instanceof HTMLButtonElement) {
            const input = passwordToggle.closest('.public-auth-password-input')?.querySelector('input');
            if (input instanceof HTMLInputElement) {
                const reveal = input.type === 'password';
                input.type = reveal ? 'text' : 'password';
                passwordToggle.setAttribute('aria-pressed', String(reveal));
                passwordToggle.setAttribute('aria-label', (reveal ? passwordToggle.dataset.hideLabel : passwordToggle.dataset.showLabel) || '');
            }
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
