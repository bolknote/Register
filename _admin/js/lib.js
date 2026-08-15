/**
 * Helper functions
 *
 * @copyright 2007-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

function loadingIndicator(bState) {
    const eDiv = document.getElementById('loading');
    if (!eDiv) {
        return;
    }
    eDiv.style.display = bState ? 'block' : 'none';
    document.body.style.cursor = bState ? 'progress' : 'inherit';
}

// Fetch interceptor
const {fetch: originalFetch} = window;
window.fetch = async (...args) => {
    let [resource, config] = args;

    if (!config) {
        config = {};
    }
    if (!config.headers) {
        config.headers = {};
    }
    const handleErrorsInline = config.s2HandleErrorsInline === true;
    delete config.s2HandleErrorsInline;
    if (!resource.includes('action=delete')) {
        // By default, AdminYard deletes records via fetch but does it only to display a confirmation dialog.
        // After that, it refreshes the page and displays the flash message.
        // Header 'X-Requested-With' switches from flash messages to JSON responses. So we do not add
        // 'X-Requested-With' in a universal fetch interceptor for action=delete.
        config.headers['X-Requested-With'] = 'XMLHttpRequest';
    }

    loadingIndicator(true);
    let response;
    try {
        response = await originalFetch(resource, config);

        if (handleErrorsInline || response.ok || response.status === 422 || response.status === 409 || response.status === 503) {
            return response;
        }

        try {
            if (response.status === 401) {
                const data = await response.json();

                if (data.message) {
                    PopupMessages.show(data.message, null, null, 'login');
                } else {
                    DisplayError(JSON.stringify(data));
                }
            } else if (response.status === 403) {
                const data = await response.json();

                if (data.message) {
                    PopupMessages.show(data.message, null, null);
                } else if (data.errors) {
                    Array.from(data.errors).forEach(function (error) {
                        // TODO array_merge
                        PopupMessages.show(error);
                    });
                }
            } else {
                const txt = await response.text();
                try {
                    const data = JSON.parse(txt);

                    if (data.message) {
                        PopupMessages.show(data.message, null, null);
                    } else if (data.errors) {
                        Array.from(data.errors).forEach(function (error) {
                            // TODO array_merge
                            PopupMessages.show(error);
                        });
                    } else {
                        DisplayError(txt);
                    }
                } catch (e) {
                    DisplayError(txt);
                }
            }
        } catch (error) {
            PopupMessages.show(error);
        }
    } finally {
        loadingIndicator(false);
    }
    console.warn('Form submission failed');

    return Promise.reject(response ?? new Error('Request failed'));
};

window.PopupMessages = {

    show: function (sMessage, aActions, iTime, sId) {
        let eMessage;
        let ePopup = document.getElementById('popup_message');
        let eList, eCross;

        if (!ePopup) {
            eCross = document.createElement('a');
            eCross.setAttribute('class', 'cross');
            eCross.setAttribute('href', '#');
            eCross.setAttribute('tabindex', '0');
            eCross.addEventListener('click', function (e) {
                ePopup.remove();
                e.preventDefault();
            });

            eList = document.createElement('div');
            eList.setAttribute('class', 'message-list');
            eList.appendChild(eCross);

            ePopup = document.createElement('div');
            ePopup.setAttribute('id', 'popup_message');
            ePopup.appendChild(eList);
            document.body.appendChild(ePopup);
        } else {
            eList = ePopup.children[0];
            eCross = eList.children[0];
        }

        eCross.focus();

        if (sId) {
            eMessage = eList.querySelector('div[data-id="' + sId + '"]');
            if (eMessage) {
                eMessage.style.opacity = 0;
                setTimeout(function () {
                    eMessage.style.opacity = 1;
                }, 100);
                setTimeout(function () {
                    eMessage.style.opacity = 0;
                }, 200);
                setTimeout(function () {
                    eMessage.style.opacity = 1;
                }, 300);
                return;
            }
        }

        eMessage = document.createElement('div');
        eMessage.setAttribute('class', 'message');
        eMessage.setAttribute('data-id', sId || '');
        eList.appendChild(eMessage);

        if (iTime) {
            setTimeout(function () {
                eMessage.remove();
                if (!eList.querySelector('.message')) {
                    ePopup.remove();
                }
            }, iTime * 1000);
        }

        eMessage.innerHTML = sMessage;

        if (aActions) {
            for (let i = 0; i < aActions.length; i++) {
                const eA = document.createElement('a');
                eA.setAttribute('class', 'action');
                eA.setAttribute('href', '#');
                eA.setAttribute('tabindex', '0');
                eA.textContent = aActions[i].name;
                (function (action, once) {
                    eA.addEventListener('click', function () {
                        action();
                        if (once) {
                            eMessage.remove();
                            if (!eList.querySelector('.message')) {
                                ePopup.remove();
                            }
                        }
                        return false;
                    });
                }(aActions[i].action, aActions[i].once));
                eMessage.appendChild(document.createTextNode('\u00a0'));
                eMessage.appendChild(eA);
            }
        }
    },

    showUnique: function (sMessage, sId) {
        this.show(sMessage, null, null, sId);
    },

    hide: function (sId) {
        if (!sId) {
            return;
        }

        const popup = document.getElementById('popup_message');
        if (!popup) {
            return;
        }

        const list = popup.children[0];

        const eMessage = list.querySelector('div[data-id="' + sId + '"]');
        if (eMessage) {
            eMessage.remove();
            if (!list.querySelector('.message')) {
                popup.remove();
            }
        }
    }
};

function DisplayError(sError) {
    function isJson(str) {
        try {
            JSON.parse(str);
        } catch (e) {
            return false;
        }
        return true;
    }

    const dialog = document.getElementById('error-dialog');
    const closeButton = document.getElementById('error-dialog-close');
    const iframe = document.getElementById('error-iframe');

    if (isJson(sError)) {
        sError = JSON.stringify(JSON.parse(sError), null, 4);
        sError = '<pre>' + sError.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;') + '</pre>';
    }

    const rootStyle = getComputedStyle(document.documentElement);
    const background = rootStyle.getPropertyValue('--admin-page-background').trim()
        || rootStyle.getPropertyValue('--page-background').trim()
        || '#fff';
    const colorScheme = document.documentElement.dataset.colorScheme === 'dark' ? 'dark' : 'light';
    const themeStyle = '<style id="s2-error-theme">html,body{background:' + background
        + ' !important;color-scheme:' + colorScheme + ';}</style>';
    if (/<\/head\s*>/i.test(sError)) {
        sError = sError.replace(/<\/head\s*>/i, themeStyle + '</head>');
    } else {
        sError = '<!doctype html><html><head>' + themeStyle + '</head><body>' + sError + '</body></html>';
    }

    const blob = new Blob([sError], {type: 'text/html'});
    iframe.style.colorScheme = colorScheme;
    iframe.src = URL.createObjectURL(blob);
    dialog.showModal();

    closeButton.addEventListener('click', function () {
        dialog.close();
        URL.revokeObjectURL(iframe.src);
    });

    closeButton.focus();
}

function localizeTimes() {
    if (typeof window.Intl === 'undefined' || typeof window.Intl.DateTimeFormat === 'undefined') {
        return;
    }

    document.querySelectorAll('time[data-local-time]').forEach(function (element) {
        const date = new Date(element.getAttribute('datetime') || '');
        if (Number.isNaN(date.getTime())) {
            return;
        }

        try {
            const formatter = new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hourCycle: 'h23',
                timeZoneName: 'short'
            });
            const parts = {};
            formatter.formatToParts(date).forEach(function (part) {
                if (part.type !== 'literal') {
                    parts[part.type] = part.value;
                }
            });

            if (parts.year && parts.month && parts.day && parts.hour && parts.minute) {
                element.textContent = parts.year + '-' + parts.month + '-' + parts.day
                    + ' ' + parts.hour + ':' + parts.minute
                    + (parts.timeZoneName ? ' ' + parts.timeZoneName : '');
            } else {
                element.textContent = formatter.format(date);
            }
        } catch (error) {
            // The explicit UTC server-rendered value remains available in old browsers.
        }
    });
}

// Ajax login form processing

let shakeTimerId = null;

async function SendLoginData(eForm, fOk, fFail) {
    try {
        let response = await originalFetch('?action=login', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new URLSearchParams({
                login: eForm.login.value,
                pass: eForm.pass.value,
                foreign_computer: eForm.foreign_computer.checked ? '1' : '0',
            })
        });

        let result = await response.json();
        if (result.success) {
            fOk();
        } else {
            fFail(result.message);
        }
    } catch (error) {
        fFail('An error occurred: ' + error.message);
    }
}

function SendLoginForm() {
    const form = document.forms['loginform'];

    function shift(time) {
        form.style.transform = `translateX(${-150.0 * Math.exp(-time * 0.006) * Math.sin(0.026179938 * time)}px)`;
    }

    let animationStartedAt = null;

    function animateShake(timestamp) {
        if (!animationStartedAt) {
            animationStartedAt = timestamp;
        }
        let duration = timestamp - animationStartedAt;

        if (duration > 835) {
            shift(0);
            cancelAnimationFrame(shakeTimerId);
            shakeTimerId = null;
        } else {
            shift(duration);
            shakeTimerId = requestAnimationFrame(animateShake);
        }
    }

    shift(0);

    SendLoginData(form, function () {
        document.location.reload();
    }, function (sText) {
        document.getElementById('message').innerHTML = sText;
        if (shakeTimerId === null) {
            animationStartedAt = null;
            shakeTimerId = requestAnimationFrame(animateShake);
        }
    });
}

function LoginInit() {
    const form = document.forms['loginform'];
    const eLogin = form.elements['login'];
    const ePass = form.elements['pass'];

    eLogin.focus();
    ePass.removeAttribute('disabled');

    let login = '', password = '';

    eLogin.onkeyup = ePass.onkeyup = function () {
        if (shakeTimerId !== null) {
            return;
        }

        if (login !== eLogin.value || password !== ePass.value) {
            document.getElementById('message').innerHTML = '';
            login = eLogin.value;
            password = ePass.value;
        }
    };
}

document.addEventListener('DOMContentLoaded', () => {
    localizeTimes();

    const loginForm = document.forms.loginform;
    if (document.body.classList.contains('login_page') && loginForm) {
        LoginInit();
        loginForm.addEventListener('submit', function (event) {
            event.preventDefault();
            SendLoginForm();
        });
    }

    document.querySelectorAll('.now-control[data-target-id][data-server-time]').forEach(function (control) {
        const serverTime = new Date(control.dataset.serverTime);
        const timeDifference = serverTime.getTime() - Date.now();

        control.addEventListener('click', function (event) {
            event.preventDefault();
            const target = document.getElementById(control.dataset.targetId);
            if (target && Number.isFinite(timeDifference)) {
                target.value = new Date(Date.now() + timeDifference).toISOString().substring(0, 16);
            }
        });
    });

    if (typeof window.makeInlineForm === 'function') {
        document.querySelectorAll('form[data-inline-form]').forEach(function (form) {
            window.makeInlineForm(form.id, form.dataset.errorMessage || 'Unable to save the value.');
        });
    }

    if (typeof window.makeAutocompleteControl === 'function') {
        document.querySelectorAll('[data-autocomplete-control]').forEach(function (control) {
            window.makeAutocompleteControl(
                control.id,
                control.dataset.allowEmpty === '1',
                control.dataset.emptyLabel || '',
                control.dataset.fetchUrl || ''
            );
        });
    }

    document.body.addEventListener('keydown', function(e) {
        // Disable sending form on Enter on new and edit forms to prevent partial submission
        if (e.key === 'Enter' && (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT')) {
            if (e.target.closest('.edit-content') || e.target.closest('.new-content')) {
                e.preventDefault();

                const formElements = e.target.form.elements;
                let index = Array.prototype.indexOf.call(formElements, e.target);

                if (index < 0) {
                    return;
                }
                while (index < formElements.length - 1) {
                    index++;
                    if (formElements[index].tabIndex !== -1) {
                        formElements[index].focus();
                        break;
                    }
                }
            }
        }
    });

    document.body.addEventListener('click', function (event) {
        const flashClose = event.target.closest('.flash-message-close');
        if (flashClose) {
            flashClose.parentElement?.remove();
            return;
        }

        const deleteAction = event.target.closest('[data-admin-delete]');
        if (deleteAction) {
            event.preventDefault();
            if (!window.confirm(deleteAction.dataset.confirm || '')) {
                return;
            }

            fetch(deleteAction.href, {
                method: 'POST',
                body: new URLSearchParams({csrf_token: deleteAction.dataset.csrfToken || ''})
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('Delete failed with HTTP ' + response.status);
                }
                window.location.assign(deleteAction.dataset.successUrl || './');
            }).catch(function (error) {
                console.warn('Unable to delete the record:', error);
            });
            return;
        }

        const action = event.target.closest('[data-list-action]');
        if (!action) {
            return;
        }

        event.preventDefault();
        const actionType = action.dataset.listAction;
        if (actionType === 'toggle-delete') {
            action.parentNode.querySelector('.list-action-delete-popup')?.classList.toggle('hidden');
            return;
        }
        if (actionType === 'cancel-delete') {
            action.closest('.list-action-delete-popup')?.classList.add('hidden');
            return;
        }
        if (actionType !== 'submit-reload' && actionType !== 'submit-remove') {
            return;
        }

        fetch(action.href, {
            method: 'POST',
            body: new URLSearchParams({csrf_token: action.dataset.csrfToken || ''})
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Action failed with HTTP ' + response.status);
            }
            if (actionType === 'submit-remove') {
                action.remove();
            } else {
                window.location.reload();
            }
        }).catch(function (error) {
            console.warn('Unable to perform list action:', error);
        });
    });
});
