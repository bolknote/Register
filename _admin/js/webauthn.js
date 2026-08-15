'use strict';

(function () {
    const messages = document.querySelector('[data-webauthn-messages]');

    function translated(name, fallback) {
        return messages?.dataset[name] || fallback;
    }

    function decodeBase64Url(value) {
        const padding = '='.repeat((4 - value.length % 4) % 4);
        const binary = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(binary, function (character) {
            return character.charCodeAt(0);
        });
    }

    function encodeBase64Url(value) {
        if (value === null || value === undefined) {
            return null;
        }
        const bytes = new Uint8Array(value);
        let binary = '';
        bytes.forEach(function (byte) {
            binary += String.fromCharCode(byte);
        });
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    }

    function browserOptions(options) {
        options.challenge = decodeBase64Url(options.challenge);
        if (options.user && typeof options.user.id === 'string') {
            options.user.id = decodeBase64Url(options.user.id);
        }
        ['allowCredentials', 'excludeCredentials'].forEach(function (key) {
            (options[key] || []).forEach(function (credential) {
                credential.id = decodeBase64Url(credential.id);
            });
        });
        return options;
    }

    function credentialPayload(credential) {
        const response = {
            clientDataJSON: encodeBase64Url(credential.response.clientDataJSON),
        };
        if ('attestationObject' in credential.response) {
            response.attestationObject = encodeBase64Url(credential.response.attestationObject);
            response.transports = typeof credential.response.getTransports === 'function'
                ? credential.response.getTransports()
                : [];
        } else {
            response.authenticatorData = encodeBase64Url(credential.response.authenticatorData);
            response.signature = encodeBase64Url(credential.response.signature);
            response.userHandle = encodeBase64Url(credential.response.userHandle);
        }

        return {
            id: credential.id,
            rawId: encodeBase64Url(credential.rawId),
            type: credential.type,
            response: response,
        };
    }

    async function postForm(url, formData) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: new URLSearchParams(formData),
            s2HandleErrorsInline: true,
        });
        const result = await response.json().catch(function () {
            return {};
        });
        if (!response.ok || !result.success) {
            throw new Error(result.message || translated('webauthnOperationFailed', 'The security operation failed.'));
        }
        return result;
    }

    async function postCredential(url, credential) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({credential: credentialPayload(credential)}),
            s2HandleErrorsInline: true,
        });
        const result = await response.json().catch(function () {
            return {};
        });
        if (!response.ok || !result.success) {
            throw new Error(result.message || translated('webauthnVerificationFailed', 'The passkey could not be verified.'));
        }
        return result;
    }

    function showMessage(element, error) {
        if (element) {
            const value = error instanceof Error ? error.message : String(error);
            element.textContent = value;
            element.hidden = value === '';
        }
    }

    const supported = window.PublicKeyCredential !== undefined && navigator.credentials !== undefined;
    const loginButton = document.querySelector('[data-webauthn-login]');
    if (loginButton) {
        if (!supported) {
            loginButton.disabled = true;
        }
        loginButton.addEventListener('click', async function () {
            const message = document.getElementById('message');
            try {
                loginButton.disabled = true;
                showMessage(message, '');
                const remember = document.forms.loginform?.elements.remember_me?.checked ? '1' : '0';
                const result = await postForm('?action=webauthn_auth_options', {remember_me: remember});
                const credential = await navigator.credentials.get({publicKey: browserOptions(result.publicKey)});
                if (!(credential instanceof PublicKeyCredential)) {
                    throw new Error(translated('webauthnNoPasskey', 'No passkey was selected.'));
                }
                await postCredential('?action=webauthn_auth_finish', credential);
                document.location.reload();
            } catch (error) {
                showMessage(message, error);
                loginButton.disabled = !supported;
            }
        });
    }

    const recoveryLoginForm = document.querySelector('[data-webauthn-recovery-login]');
    recoveryLoginForm?.addEventListener('submit', async function (event) {
        event.preventDefault();
        const message = document.getElementById('message');
        try {
            const result = await postForm(recoveryLoginForm.action, new FormData(recoveryLoginForm));
            if (result.success) {
                document.location.reload();
            }
        } catch (error) {
            showMessage(message, error);
        }
    });

    const settings = document.querySelector('[data-webauthn-settings]');
    if (!settings) {
        return;
    }
    const settingsMessage = settings.querySelector('[data-webauthn-settings-message]');
    const registerForm = settings.querySelector('[data-webauthn-register-form]');
    if (registerForm && !supported) {
        registerForm.querySelector('button[type="submit"]').disabled = true;
    }
    registerForm?.addEventListener('submit', async function (event) {
        event.preventDefault();
        try {
            showMessage(settingsMessage, '');
            const result = await postForm(registerForm.action, new FormData(registerForm));
            const credential = await navigator.credentials.create({publicKey: browserOptions(result.publicKey)});
            if (!(credential instanceof PublicKeyCredential)) {
                throw new Error(translated('webauthnCreationFailed', 'The passkey was not created.'));
            }
            await postCredential('?action=webauthn_register_finish', credential);
            document.location.reload();
        } catch (error) {
            showMessage(settingsMessage, error);
        }
    });

    settings.querySelectorAll('[data-webauthn-delete-form]').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            try {
                showMessage(settingsMessage, '');
                await postForm(form.action, new FormData(form));
                document.location.reload();
            } catch (error) {
                showMessage(settingsMessage, error);
            }
        });
    });

    const recoveryForm = settings.querySelector('[data-webauthn-recovery-form]');
    recoveryForm?.addEventListener('submit', async function (event) {
        event.preventDefault();
        try {
            showMessage(settingsMessage, '');
            const result = await postForm(recoveryForm.action, new FormData(recoveryForm));
            const holder = settings.querySelector('[data-webauthn-recovery-codes]');
            const list = holder.querySelector('ol');
            list.replaceChildren(...result.codes.map(function (code) {
                const item = document.createElement('li');
                const codeElement = document.createElement('code');
                codeElement.textContent = code;
                item.append(codeElement);
                return item;
            }));
            holder.hidden = false;
            recoveryForm.reset();
        } catch (error) {
            showMessage(settingsMessage, error);
        }
    });
}());
