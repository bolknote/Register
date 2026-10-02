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
    const sizes = new ResizeObserver(measure);
    function observeTree(node, added) {
        if (!(node instanceof Element)) return;
        [node, ...node.querySelectorAll('*')].forEach(element => {
            if (added) sizes.observe(element);
            else sizes.unobserve(element);
        });
    }
    function contentBottom(node, range, clipBottom = Infinity) {
        if (node instanceof Text) {
            range.selectNode(node);
            return Math.min(range.getBoundingClientRect().bottom, clipBottom);
        }
        if (!(node instanceof Element)) return 0;
        const style = getComputedStyle(node);
        if (style.display === 'none') return 0;
        const bounds = node.getBoundingClientRect();
        let bottom = Math.min(bounds.bottom, clipBottom);
        if (style.display !== 'contents' && style.overflowY !== 'visible') {
            clipBottom = Math.min(clipBottom, bounds.bottom - parseFloat(style.borderBottomWidth || '0'));
        }
        node.childNodes.forEach(child => { bottom = Math.max(bottom, contentBottom(child, range, clipBottom)); });
        return bottom;
    }
    function measure() {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {
            queued = false;
            const body = document.body;
            if (!body) return;
            // scrollHeight includes the current iframe viewport: using it would
            // retain a tall frame after its contents shrink. Measure descendants
            // outside body's box too, respecting explicit overflow clipping.
            const bottom = contentBottom(body, document.createRange());
            parent.postMessage({
                registerHtmlPreview: options.key,
                height: Math.ceil(bottom + scrollY),
            }, '*');
        });
    }
    observeTree(document.body, true);
    new MutationObserver(records => {
        records.forEach(record => {
            record.removedNodes.forEach(node => observeTree(node, false));
            record.addedNodes.forEach(node => observeTree(node, true));
        });
        measure();
    }).observe(document.documentElement, {childList: true, subtree: true, attributes: true, characterData: true});
    addEventListener('load', measure);
    addEventListener('resize', measure);
    measure();
})();
