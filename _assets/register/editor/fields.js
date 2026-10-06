/* Public editor fields: dependencies are supplied by the entry module. */
(() => {
    'use strict';

    function create({}) {
        function createEditorFieldSurfaces(state) {
            if (!getComputedStyle(state.card).getPropertyValue('--post-editor-field-padding')) {
                return {destroy() {}};
            }
            const namespace = 'http://www.w3.org/2000/svg';
            const surface = document.createElementNS(namespace, 'svg');
            surface.classList.add('post-editor-field-surfaces');
            surface.setAttribute('aria-hidden', 'true');
            surface.setAttribute('focusable', 'false');
            const fields = [
                ['title', state.title],
                ['body', state.body],
                ['tags', state.tags.querySelector('.post-tags-surface')],
            ].map(([name, element]) => {
                const rect = document.createElementNS(namespace, 'rect');
                rect.setAttribute('data-editor-field-surface', name);
                rect.setAttribute('rx', '4');
                surface.append(rect);
                return {element, rect};
            });
            const context = document.createElement('canvas').getContext('2d');
            const fontMetrics = new Map();
            const date = state.time.parentElement;
            const dateOffsetProperty = '--post-editor-date-offset';
            const originalDateOffset = date.style.getPropertyValue(dateOffsetProperty);
            const originalDateOffsetPriority = date.style.getPropertyPriority(dateOffsetProperty);
            let pendingFrame = 0;

            function textEdge(node, atStart) {
                if (node.nodeType === Node.TEXT_NODE) {
                    if (String(node.textContent).trim() === '') {
                        return null;
                    }
                    const range = document.createRange();
                    range.selectNodeContents(node);
                    const rects = Array.from(range.getClientRects());
                    return {element: node.parentElement, bounds: rects.at(atStart ? 0 : -1)};
                }
                if (!(node instanceof HTMLElement) || node.hidden
                    || node.matches('style, script, template')) {
                    return null;
                }
                if (node.matches('img, video, audio, iframe, hr, table, pre, .post-tag-chip')) {
                    return {element: node, bounds: null};
                }
                if (node.matches('input, br') || node.childNodes.length === 0) {
                    return {element: node, bounds: node.getBoundingClientRect()};
                }
                const children = Array.from(node.childNodes);
                for (const child of atStart ? children : children.reverse()) {
                    const edge = textEdge(child, atStart);
                    if (edge) {
                        return edge;
                    }
                }
                return null;
            }

            function leading(element, bounds, atStart) {
                if (!context || element.querySelector('.post-tag-chip')
                    || (element === state.body && (state.creating || element.textContent.trim() === ''))) {
                    return 0;
                }
                const edge = textEdge(element, atStart);
                if (!edge?.bounds?.height) {
                    return 0;
                }
                const typography = getComputedStyle(edge.element);
                const font = `${typography.fontStyle} ${typography.fontWeight} ${typography.fontSize} ${typography.fontFamily}`;
                let metrics = fontMetrics.get(font);
                if (!metrics) {
                    context.font = font;
                    metrics = context.measureText('Hg');
                    fontMetrics.set(font, metrics);
                }
                const {fontBoundingBoxAscent: ascent, fontBoundingBoxDescent: descent} = metrics;
                if (!Number.isFinite(ascent) || !Number.isFinite(descent)) {
                    return 0;
                }
                const baseline = edge.bounds.top + (edge.bounds.height - ascent - descent) / 2 + ascent;
                const inset = atStart
                    ? baseline - metrics.actualBoundingBoxAscent - bounds.top
                    : bounds.bottom - baseline - metrics.actualBoundingBoxDescent;
                // Do not trim intentional blank paragraphs, custom margins, or a new editor's height.
                const limit = (edge.element.matches('input')
                    ? edge.bounds.height : Number.parseFloat(typography.lineHeight) || 0) / 2;
                return inset >= 0 && inset <= limit ? inset : 0;
            }

            function update() {
                pendingFrame = 0;
                const origin = state.card.getBoundingClientRect();
                const padding = Number.parseFloat(getComputedStyle(surface).getPropertyValue('--post-editor-field-padding'));
                fields.forEach(({element, rect}) => {
                    const outer = element.getBoundingClientRect();
                    const fieldStyle = getComputedStyle(element);
                    const inset = (side) => (Number.parseFloat(fieldStyle[`padding${side}`]) || 0)
                        + (Number.parseFloat(fieldStyle[`border${side}Width`]) || 0);
                    const bounds = {
                        left: outer.left + inset('Left'),
                        top: outer.top + inset('Top'),
                        bottom: outer.bottom - inset('Bottom'),
                        width: outer.width - inset('Left') - inset('Right'),
                        height: outer.height - inset('Top') - inset('Bottom'),
                    };
                    const top = leading(element, bounds, true);
                    const bottom = leading(element, bounds, false);
                    rect.setAttribute('x', String(bounds.left - origin.left - padding));
                    rect.setAttribute('y', String(bounds.top - origin.top + top - padding));
                    rect.setAttribute('width', String(bounds.width + 2 * padding));
                    rect.setAttribute('height', String(Math.max(0, bounds.height - top - bottom) + 2 * padding));
                });
                // Field backgrounds extend beyond the text. Keep the date midway
                // between their visible edges without moving either field's layout.
                const titleRect = fields[0].rect;
                const bodyRect = fields[1].rect;
                const middle = (Number(titleRect.getAttribute('y')) + Number(titleRect.getAttribute('height'))
                    + Number(bodyRect.getAttribute('y'))) / 2;
                const dateBounds = date.getBoundingClientRect();
                const currentOffset = Number.parseFloat(date.style.getPropertyValue(dateOffsetProperty)) || 0;
                const dateMiddle = dateBounds.top - origin.top + dateBounds.height / 2 - currentOffset;
                date.style.setProperty(dateOffsetProperty, `${middle - dateMiddle}px`);
            }

            function schedule() {
                if (!pendingFrame) {
                    pendingFrame = requestAnimationFrame(update);
                }
            }

            function fontsLoaded() {
                fontMetrics.clear();
                schedule();
            }

            state.card.append(surface);
            const resizeObserver = new ResizeObserver(schedule);
            const mutationObserver = new MutationObserver(schedule);
            resizeObserver.observe(state.card);
            resizeObserver.observe(date);
            mutationObserver.observe(date, {childList: true, subtree: true, characterData: true});
            fields.forEach(({element}) => {
                resizeObserver.observe(element);
                mutationObserver.observe(element, {childList: true, subtree: true, characterData: true});
            });
            document.fonts?.addEventListener('loadingdone', fontsLoaded);
            update();

            return {
                destroy() {
                    cancelAnimationFrame(pendingFrame);
                    resizeObserver.disconnect();
                    mutationObserver.disconnect();
                    if (originalDateOffset) date.style.setProperty(dateOffsetProperty, originalDateOffset, originalDateOffsetPriority);
                    else date.style.removeProperty(dateOffsetProperty);
                    document.fonts?.removeEventListener('loadingdone', fontsLoaded);
                    surface.remove();
                },
            };
        }

        return {createEditorFieldSurfaces};
    }

    window.RegisterEditorFields = Object.freeze({create});
})();
