const pause = 700;
const preferredPopoverWidth = 234;
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
    const heading = state.title.closest('.post.head') ?? state.title;
    const tools = card.querySelector(':scope > .post-inplace-tools');
    if (!tools) return;
    const fields = panel.querySelectorAll('[data-readability-value]');
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
    let popoverFrame = 0;
    let hovered = false;
    let pinned = false;
    let openListeners;

    function renderScore(score) {
        const band = score === null ? 'unavailable' : scoreBands.find(([limit]) => score < limit)[1];
        const label = level.dataset[band];
        if (level.textContent !== label) level.textContent = label;
        const name = score === null ? `${scoreLabel}. ${label}` : `${scoreLabel} ${number.format(score)}. ${label}`;
        if (badge.getAttribute('aria-label') !== name) badge.setAttribute('aria-label', name);
        panel.toggleAttribute('data-score-available', score !== null);
        scale.hidden = score === null;
        if (score !== null) {
            const position = Math.max(0, Math.min(100, score));
            setStyle('--readability-score', position + '%');
            setStyle('--readability-angle', (position * 1.8 - 90) + 'deg');
        }
    }

    function render(metrics) {
        for (const field of fields) {
            const key = field.dataset.readabilityValue;
            if (!Object.hasOwn(metrics, key)) continue;
            const value = metrics[key];
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
            schedulePopoverFit();
        }
    }

    function workerFailed() {
        failed = true;
        worker?.terminate();
        worker = undefined;
        schedule();
        for (const field of fields) field.textContent = '—';
        renderScore(null);
        schedulePopoverFit();
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
        const toolbar = tools.getBoundingClientRect();
        const origin = card.getBoundingClientRect();
        const title = heading.getBoundingClientRect();
        const badgeLeft = toolbar.left + (toolbar.width - badgeWidth) / 2;
        if (!title.width || toolbar.right > origin.left + 1 || badgeLeft < 0) {
            if (!panel.hidden) {
                if (panel.contains(document.activeElement)) body.focus({preventScroll: true});
                closePopover();
                panel.hidden = true;
                schedule();
            }
            return;
        }
        const left = (badgeLeft - origin.left - card.clientLeft) + 'px';
        const top = (title.top - origin.top - card.clientTop) + 'px';
        if (panel.style.left !== left) panel.style.left = left;
        if (panel.style.top !== top) panel.style.top = top;
        // Both rails end at the card. Reserve the toolbar's height so the
        // badge stays above its buttons while they scroll off together.
        setStyle('--readability-toolbar-height', toolbar.height + 'px');
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
        const viewportTop = viewport?.offsetTop ?? 0;
        const viewportBottom = viewportTop + (viewport?.height ?? document.documentElement.clientHeight);
        const right = (viewport?.offsetLeft ?? 0) + (viewport?.width ?? document.documentElement.clientWidth);
        const bounds = badge.getBoundingClientRect();
        if (bounds.bottom <= viewportTop || bounds.top >= viewportBottom) {
            closePopover();
            return;
        }
        const top = viewportTop + viewportGutter;
        const bottom = viewportBottom - viewportGutter;
        const width = Math.floor(right - viewportGutter - popoverGap - bounds.right);
        setStyle('--readability-popover-width', Math.max(0, Math.min(preferredPopoverWidth, width)) + 'px');
        setStyle('--readability-popover-height', Math.max(0, Math.floor(bottom - top)) + 'px');
        const height = content.getBoundingClientRect().height;
        const offset = Math.max(top, Math.min(bounds.top + popoverGap, bottom - height));
        setStyle('--readability-popover-top', (offset - bounds.top) + 'px');
    }

    function schedulePopoverFit() {
        if (popover.hidden || popoverFrame) return;
        popoverFrame = requestAnimationFrame(() => {
            popoverFrame = 0;
            fitPopover();
        });
    }

    function renderPopover() {
        const open = hovered || pinned || popover.contains(document.activeElement);
        if (open === !popover.hidden) return;
        popover.hidden = !open;
        badge.setAttribute('aria-expanded', String(open));
        if (open) {
            openListeners = new AbortController();
            const scrollOptions = {capture: true, passive: true, signal: openListeners.signal};
            window.addEventListener('scroll', schedulePopoverFit, scrollOptions);
            window.visualViewport?.addEventListener('resize', schedulePopoverFit, {signal: openListeners.signal});
            window.visualViewport?.addEventListener('scroll', schedulePopoverFit, {signal: openListeners.signal});
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
    details.addEventListener('toggle', schedulePopoverFit, {signal});
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
    // Changes above the article can move an open panel without resizing the post.
    [card, body, heading, tools, state.time.parentElement, document.body]
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
    document.fonts?.addEventListener('loadingdone', schedulePlacement, {signal});
    signal.addEventListener('abort', () => {
        observer.disconnect();
        resizeObserver.disconnect();
        clearTimeout(timer);
        cancelAnimationFrame(frame);
        cancelAnimationFrame(popoverFrame);
        openListeners?.abort();
        worker?.terminate();
        panel.remove();
    }, {once: true});
    schedulePlacement();
}
