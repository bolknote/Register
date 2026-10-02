/* Runs only in the authenticated, opaque-origin HTML block preview document. */
(() => {
    'use strict';
    const options = JSON.parse(document.currentScript.dataset.previewOptions);
    Object.assign(document.body.style, options.theme);
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
