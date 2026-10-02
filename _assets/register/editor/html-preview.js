/* Runs only in the authenticated, opaque-origin HTML block preview document. */
(() => {
    'use strict';
    const options = JSON.parse(document.currentScript.dataset.previewOptions);
    const {backgroundColor, colorScheme, ...typography} = options.theme;
    // Defaults belong to the preview, not the author's body. Author CSS may
    // override them; a transparent document still matches the editor panel.
    document.documentElement.style.setProperty('--register-html-preview-background', backgroundColor);
    document.documentElement.style.setProperty('--register-html-preview-color-scheme', colorScheme);
    Object.assign(document.body.style, typography);
    let queued = false;
    function measure() {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {
            queued = false;
            parent.postMessage({
                registerHtmlPreview: options.key,
                height: Math.ceil(document.body.getBoundingClientRect().height),
            }, '*');
        });
    }
    new ResizeObserver(measure).observe(document.body);
    addEventListener('load', measure);
    measure();
})();
