const pause = 700;
const preferredWidth = 226;
const minimumPopoverWidth = 180;
const preferredGap = 16;
const minimumGap = 10;
const viewportGutter = 16;
const popoverGap = 6;
const scoreBands = [[30, 'veryDifficult'], [50, 'difficult'], [60, 'fairlyDifficult'], [70, 'moderate'], [Infinity, 'easy']];
let nextPopoverId = 0;
const skipped = 'pre, code, script, style, template, textarea, iframe, svg, math, audio, video, [hidden], '
    + '[data-post-html-source], .post-media-upload, .post-media-processing-progress, .post-media-caption-toolbar';
const blocks = 'p, div, section, article, aside, blockquote, li, dt, dd, td, th, figcaption, h1, h2, h3, h4, h5, h6, hr';

function prose(body) {
    const parts = [];
    // Walk the live DOM without cloning author HTML or measuring its layout.
    const parents = [];
    let node = body.firstChild;
    while (node) {
        if (node.nodeType === Node.TEXT_NODE) {
            parts.push(node.data.replace(/[\t\r\n]+/gu, ' '));
        } else if (node instanceof Element) {
            if (node.matches(skipped)) {
                parts.push(node.matches('pre, [data-post-html-source]') ? '\n' : ' ');
            } else if (node.matches('br')) {
                parts.push(' ');
            } else {
                const boundary = node.matches(blocks);
                if (boundary) parts.push('\n');
                if (node.firstChild) {
                    parents.push({element: node, boundary});
                    node = node.firstChild;
                    continue;
                }
            }
        }
        while (!node.nextSibling && parents.length) {
            const parent = parents.pop();
            if (parent.boundary) parts.push('\n');
            node = parent.element;
        }
        node = node.nextSibling;
    }
    return parts.join('').replace(/[\u00ad\u200b\u2060\ufeff]/gu, '').trim();
}

export function createReadability(state, template, signal, isComposing) {
    const panel = template.content.firstElementChild.cloneNode(true);
    const anchor = panel.querySelector('.post-editor-readability-anchor');
    const badge = panel.querySelector('.post-editor-readability-badge');
    const popover = panel.querySelector('.post-editor-readability-popover');
    const content = panel.querySelector('.post-editor-readability-content');
    const details = content.querySelector('details');
    const level = panel.querySelector('.post-editor-readability-level');
    const scale = panel.querySelector('.post-editor-readability-scale');
    const scoreLabel = badge.getAttribute('aria-label');
    popover.id = `register-readability-popover-${++nextPopoverId}`;
    badge.setAttribute('aria-controls', popover.id);
    const {card, body} = state;
    const fields = new Map(Array.from(panel.querySelectorAll('[data-readability-value]'),
        field => [field.dataset.readabilityValue, field]));
    const locale = document.documentElement.lang || 'en';
    const number = new Intl.NumberFormat(locale, {maximumFractionDigits: 1});
    const percent = new Intl.NumberFormat(locale, {style: 'percent', maximumFractionDigits: 0});
    const seconds = new Intl.NumberFormat(locale, {style: 'unit', unit: 'second', unitDisplay: 'narrow'});
    const minutes = new Intl.NumberFormat(locale, {style: 'unit', unit: 'minute', unitDisplay: 'short', maximumFractionDigits: 1});
    let worker;
    let revision = 0;
    let inFlight = false;
    let elapsed = false;
    let failed = false;
    let timer = 0;
    let frame = 0;
    let hovered = false;
    let pinned = false;
    let openListeners;

    function renderScore(score) {
        const band = score === null ? 'unavailable' : scoreBands.find(([limit]) => score < limit)[1];
        const label = level.dataset[band];
        if (level.textContent !== label) level.textContent = label;
        const name = score === null ? `${scoreLabel}. ${label}` : `${scoreLabel} ${number.format(score)}. ${label}`;
        if (badge.getAttribute('aria-label') !== name) badge.setAttribute('aria-label', name);
        scale.hidden = score === null;
        if (score !== null) scale.style.setProperty('--readability-score', Math.max(0, Math.min(100, score)) + '%');
    }

    function render(metrics) {
        for (const [key, value] of Object.entries(metrics)) {
            const field = fields.get(key);
            if (!field) continue;
            let formatted = '—';
            if (value !== null) {
                if (key === 'variety') formatted = percent.format(value);
                else if (key === 'seconds') formatted = '≈ ' + (value < 60
                    ? seconds.format(value) : minutes.format(value / 60));
                else formatted = number.format(value);
            }
            if (field.textContent !== formatted) field.textContent = formatted;
        }
        if (Object.hasOwn(metrics, 'flesch')) {
            renderScore(metrics.flesch);
            fitPopover();
        }
    }

    function workerFailed() {
        failed = true;
        worker?.terminate();
        worker = undefined;
        schedule();
        for (const field of fields.values()) field.textContent = '—';
        renderScore(null);
    }

    function receive({data}) {
        if (signal.aborted) return;
        if (data.metrics) inFlight = false;
        if (data.revision === revision && !panel.hidden && !isComposing() && body.isConnected) {
            if (data.metrics) panel.removeAttribute('data-pending');
            render(data.metrics ?? {rare: data.rare});
        }
        update();
    }

    function update() {
        if (!elapsed || inFlight || !body.isConnected) return;
        elapsed = false;
        try {
            if (!worker) {
                worker = new Worker(new URL('./worker.js', import.meta.url), {type: 'module'});
                worker.addEventListener('message', receive);
                worker.addEventListener('error', event => {
                    event.preventDefault();
                    workerFailed();
                });
            }
            worker.postMessage({revision, text: prose(body)});
            inFlight = true;
        } catch (_) {
            workerFailed();
        }
    }

    function schedule() {
        clearTimeout(timer);
        revision++;
        elapsed = false;
        if (failed || panel.hidden || isComposing()) {
            panel.removeAttribute('data-pending');
            return;
        }
        if (!panel.hasAttribute('data-pending')) panel.setAttribute('data-pending', '');
        timer = setTimeout(() => {
            elapsed = true;
            update();
        }, pause);
    }

    function place() {
        frame = 0;
        if (!body.isConnected) return;
        const bounds = body.getBoundingClientRect();
        const viewportRight = document.documentElement.clientWidth - viewportGutter;
        const room = viewportRight - bounds.right;
        if (!bounds.width || room < badgeWidth + minimumGap) {
            if (!panel.hidden) {
                if (panel.contains(document.activeElement)) body.focus({preventScroll: true});
                closePopover();
                panel.hidden = true;
                schedule();
            }
            return;
        }
        const gap = Math.min(preferredGap, room - badgeWidth);
        const badgeLeft = bounds.right + gap;
        const available = Math.floor(viewportRight - badgeLeft);
        const maxWidth = Math.min(preferredWidth, viewportRight - viewportGutter);
        const popoverWidth = available >= minimumPopoverWidth ? Math.min(maxWidth, available) : maxWidth;
        const origin = card.getBoundingClientRect();
        const left = (badgeLeft - origin.left - card.clientLeft) + 'px';
        const top = (bounds.top - origin.top - card.clientTop) + 'px';
        // The sticky anchor travels only within the height of the editable text.
        const height = bounds.height + 'px';
        if (panel.style.left !== left) panel.style.left = left;
        if (panel.style.top !== top) panel.style.top = top;
        if (panel.style.height !== height) panel.style.height = height;
        setStyle('--readability-popover-left', Math.min(0, available - popoverWidth) + 'px');
        setStyle('--readability-popover-width', popoverWidth + 'px');
        if (panel.hidden) {
            panel.hidden = false;
            schedule();
        }
        fitPopover();
    }

    function setStyle(property, value) {
        if (panel.style.getPropertyValue(property) !== value) panel.style.setProperty(property, value);
    }

    function fitPopover() {
        if (popover.hidden) return;
        const viewport = window.visualViewport;
        const top = viewport?.offsetTop ?? 0;
        const bottom = top + (viewport?.height ?? document.documentElement.clientHeight);
        const bounds = badge.getBoundingClientRect();
        const below = bottom - bounds.bottom;
        const above = bounds.top - top;
        const height = content.scrollHeight + viewportGutter + popoverGap;
        const opensAbove = below < height && above > below;
        popover.toggleAttribute('data-above', opensAbove);
        const room = Math.floor((opensAbove ? above : below) - viewportGutter - popoverGap);
        setStyle('--readability-popover-height', Math.max(48, room) + 'px');
    }

    function renderPopover() {
        const open = hovered || pinned || popover.contains(document.activeElement);
        if (open === !popover.hidden) return;
        popover.hidden = !open;
        badge.setAttribute('aria-expanded', String(open));
        if (open) {
            openListeners = new AbortController();
            window.addEventListener('scroll', fitPopover, {passive: true, signal: openListeners.signal});
            window.visualViewport?.addEventListener('resize', fitPopover, {signal: openListeners.signal});
            fitPopover();
        } else {
            openListeners.abort();
            openListeners = undefined;
        }
    }

    function closePopover() {
        if (popover.contains(document.activeElement)) badge.focus({preventScroll: true});
        hovered = false;
        pinned = false;
        renderPopover();
    }

    function schedulePlacement() {
        if (!frame) frame = requestAnimationFrame(place);
    }

    card.append(panel);
    const style = getComputedStyle(panel);
    const badgeWidth = Number.parseFloat(style.getPropertyValue('--readability-badge-width'));
    if (style.position !== 'absolute' || !Number.isFinite(badgeWidth)) {
        panel.remove();
        return;
    }
    setStyle('--readability-popover-gap', popoverGap + 'px');
    renderScore(null);
    badge.addEventListener('pointerenter', event => {
        if (event.pointerType === 'touch') return;
        hovered = true;
        renderPopover();
    }, {signal});
    anchor.addEventListener('pointerleave', () => {
        hovered = false;
        renderPopover();
    }, {signal});
    badge.addEventListener('click', () => {
        if (pinned) {
            closePopover();
            return;
        }
        pinned = true;
        renderPopover();
    }, {signal});
    anchor.addEventListener('focusout', () => queueMicrotask(() => {
        if (!signal.aborted) renderPopover();
    }), {signal});
    details.addEventListener('toggle', fitPopover, {signal});
    document.addEventListener('click', event => {
        if (!popover.hidden && !anchor.contains(event.target)) closePopover();
    }, {signal});
    card.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || event.isComposing || event.defaultPrevented || popover.hidden) return;
        event.preventDefault();
        event.stopPropagation();
        closePopover();
    }, {signal});
    const resizeObserver = new ResizeObserver(schedulePlacement);
    [card, body, state.title, state.time.parentElement]
        .forEach(element => resizeObserver.observe(element));
    const observer = new MutationObserver(changes => {
        if (changes.some(change => {
            const element = change.target.nodeType === Node.ELEMENT_NODE ? change.target : change.target.parentElement;
            // A visibility change of a paragraph can add or remove prose.
            return change.type === 'attributes' || !element?.closest(skipped);
        })) schedule();
    });
    observer.observe(body, {subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['hidden']});
    body.addEventListener('compositionstart', schedule, {signal});
    body.addEventListener('compositionend', schedule, {signal});
    window.addEventListener('resize', schedulePlacement, {signal});
    signal.addEventListener('abort', () => {
        observer.disconnect();
        resizeObserver.disconnect();
        clearTimeout(timer);
        cancelAnimationFrame(frame);
        openListeners?.abort();
        worker?.terminate();
        panel.remove();
    }, {once: true});
    schedulePlacement();
}
