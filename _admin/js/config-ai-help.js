/** Contextual onboarding for AI provider API keys. */
function initAiKeyHelp() {
    const dialog = document.getElementById('ai-key-help-dialog');
    const openButton = document.querySelector('[data-ai-key-help-open]');
    const closeButton = dialog && dialog.querySelector('[data-ai-key-help-close]');
    const pasteButton = dialog && dialog.querySelector('[data-ai-key-help-paste]');
    const providerSelect = document.querySelector('form[action*="name=REGISTER_AI_PROVIDER"] select[name="value"]');
    const apiKeyInput = document.querySelector('form[action*="name=REGISTER_AI_API_KEY"] input[type="password"]');
    const tabs = dialog ? Array.from(dialog.querySelectorAll('[data-ai-key-help-tab]')) : [];
    const panels = dialog ? Array.from(dialog.querySelectorAll('[data-ai-key-help-panel]')) : [];

    if (!dialog || !openButton || !providerSelect || tabs.length === 0 || panels.length === 0) {
        return;
    }

    function normalizeProvider(provider) {
        return provider === 'groq' ? 'groq' : 'gemini';
    }

    function activateProvider(provider, moveFocus) {
        const activeProvider = normalizeProvider(provider);
        tabs.forEach(function (tab) {
            const isActive = tab.dataset.aiKeyHelpTab === activeProvider;
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            tab.tabIndex = isActive ? 0 : -1;
            if (isActive && moveFocus) {
                tab.focus();
            }
        });
        panels.forEach(function (panel) {
            panel.hidden = panel.dataset.aiKeyHelpPanel !== activeProvider;
        });
    }

    function openHelp() {
        activateProvider(providerSelect.value, false);
        if (!dialog.open) {
            dialog.showModal();
        }
    }

    openButton.addEventListener('click', openHelp);
    providerSelect.addEventListener('change', function () {
        if (providerSelect.value === 'gemini' || providerSelect.value === 'groq') {
            openHelp();
        }
    });

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            activateProvider(tab.dataset.aiKeyHelpTab, false);
        });
        tab.addEventListener('keydown', function (event) {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                return;
            }
            event.preventDefault();
            const currentIndex = tabs.indexOf(tab);
            const direction = event.key === 'ArrowRight' ? 1 : -1;
            const nextIndex = (currentIndex + direction + tabs.length) % tabs.length;
            activateProvider(tabs[nextIndex].dataset.aiKeyHelpTab, true);
        });
    });

    if (closeButton) {
        closeButton.addEventListener('click', function () {
            dialog.close();
        });
    }
    if (pasteButton) {
        pasteButton.addEventListener('click', function () {
            dialog.close();
            if (apiKeyInput) {
                requestAnimationFrame(function () {
                    apiKeyInput.focus();
                });
            }
        });
    }
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });
}

initAiKeyHelp();
