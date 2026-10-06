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
    let previousMeasurement = null;
    let viewportSized = false;
    let contentChanged = false;
    let resizeTimer = null;
    function intrinsicAnimations() {
        // Auto-height follows animations too. Their progress is independent of
        // the preceding iframe resize; opacity/color animations do not affect it.
        const animations = new Map();
        for (const animation of document.getAnimations()) {
            const frames = animation.effect?.getKeyframes?.() || [];
            const geometry = frames.flatMap(frame => Object.entries(frame).filter(([property]) => (
                /^(?:height|width|(?:min|max)?(?:Height|Width|BlockSize|InlineSize)|blockSize|inlineSize|top|bottom|left|right|inset.*|padding.*|margin.*|border.*Width|font.*|lineHeight|letterSpacing|wordSpacing|transform|translate|scale|rotate|zoom|flex.*|grid.*|gap|rowGap|columnGap|--.*)$/u.test(property)
            )));
            if (geometry.length && !geometry.some(([, value]) => /(?:\d|\.)\s*(?:%|[sld]?v(?:h|w|b|i|min|max)\b)/iu.test(String(value)))) {
                animations.set(animation, {time: animation.currentTime, target: animation.effect.target,
                    position: geometry.some(([property]) => /^(?:top|bottom|left|right|inset|margin|transform|translate|rotate|flex|grid)/u.test(property))});
            }
        }
        return animations;
    }
    function animationsChanged(previous, current) {
        return previous.size !== current.size
            || Array.from(current).some(([animation, {time}]) => previous.get(animation)?.time !== time);
    }
    function viewportResponse(previous, boxes, animations, viewportChange) {
        const targets = new Map();
        for (const {target, position} of [...previous.animations.values(), ...animations.values()]) {
            targets.set(target, position || targets.get(target));
        }
        for (const [element, bounds] of boxes) {
            const before = previous.boxes.get(element);
            if (!before || Array.from(targets.keys()).some(target => target?.contains(element))) continue;
            let allowance = 1;
            for (const [target, position] of targets) {
                if (!element.contains(target)) continue;
                const old = previous.boxes.get(target);
                const current = boxes.get(target);
                if (!old || !current) continue;
                // An intrinsic animation can resize its ancestors. Discount that
                // contribution instead of ignoring every other element's response.
                allowance += Math.abs(current.height - old.height);
                if (position) {
                    allowance += Math.abs(current.marginTop - old.marginTop) + Math.abs(current.marginBottom - old.marginBottom);
                    allowance += Math.abs((current.top - bounds.top) - (old.top - before.top));
                }
            }
            const change = bounds.height - before.height;
            if (Math.abs(change) > allowance && change * viewportChange > 0) return true;
        }
        return false;
    }
    const sizes = new ResizeObserver(measure);
    function observeTree(node, added) {
        if (!(node instanceof Element)) return;
        [node, ...node.querySelectorAll('*')].forEach(element => {
            if (added) sizes.observe(element);
            else sizes.unobserve(element);
        });
    }
    function contentBottom(node, range, boxes, clipBottom = Infinity) {
        if (node instanceof Text) {
            range.selectNode(node);
            return Math.min(range.getBoundingClientRect().bottom, clipBottom);
        }
        if (!(node instanceof Element)) return 0;
        const style = getComputedStyle(node);
        if (style.display === 'none') return 0;
        const bounds = node.getBoundingClientRect();
        boxes.set(node, {height: bounds.height, top: bounds.top,
            marginTop: parseFloat(style.marginTop) || 0, marginBottom: parseFloat(style.marginBottom) || 0});
        let bottom = Math.min(bounds.bottom, clipBottom);
        if (style.display !== 'contents' && style.overflowY !== 'visible') {
            clipBottom = Math.min(clipBottom, bounds.bottom - parseFloat(style.borderBottomWidth || '0'));
        }
        node.childNodes.forEach(child => { bottom = Math.max(bottom, contentBottom(child, range, boxes, clipBottom)); });
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
            const boxes = new Map();
            const bottom = contentBottom(body, document.createRange(), boxes);
            const height = Math.ceil(bottom + scrollY);
            const animations = intrinsicAnimations();
            const animated = previousMeasurement && animationsChanged(previousMeasurement.animations, animations);
            // Auto-height cannot converge when the author's layout itself depends
            // on the iframe height (vh, percentages or resize handlers). Use a
            // stable viewport in that case; intrinsic layouts still grow/shrink.
            if (previousMeasurement && !contentChanged && innerWidth === previousMeasurement.width
                && innerHeight !== previousMeasurement.viewport) {
                const heightChange = height - previousMeasurement.height;
                const viewportChange = innerHeight - previousMeasurement.viewport;
                if (animated ? viewportResponse(previousMeasurement, boxes, animations, viewportChange)
                    : Math.abs(heightChange) > 1 && heightChange * viewportChange > 0) viewportSized = true;
            }
            // A resize observer may run before the author's resize event handler.
            // Keep the prior baseline until that handler has changed the height.
            if (!previousMeasurement || contentChanged || animated || innerWidth !== previousMeasurement.width
                || Math.abs(height - previousMeasurement.height) > 1) {
                previousMeasurement = {height, viewport: innerHeight, width: innerWidth, animations, boxes};
            }
            contentChanged = false;
            parent.postMessage({
                registerHtmlPreview: options.key,
                height,
                viewportSized,
            }, '*');
        });
    }
    observeTree(document.body, true);
    new MutationObserver(records => {
        if (resizeTimer === null) contentChanged = true;
        records.forEach(record => {
            record.removedNodes.forEach(node => observeTree(node, false));
            record.addedNodes.forEach(node => observeTree(node, true));
        });
        measure();
    }).observe(document.documentElement, {childList: true, subtree: true, attributes: true, characterData: true});
    addEventListener('load', measure);
    addEventListener('resize', () => {
        // DOM changes made by synchronous author resize handlers belong to the
        // viewport response, rather than to an independent content edit.
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => { resizeTimer = null; }, 0);
        measure();
    });
    measure();
})();
