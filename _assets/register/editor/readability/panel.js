const pause = 700;
const preferredWidth = 226;
const minimumWidth = 180;
const columnGap = 16;
const viewportGutter = 16;
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
    }

    function workerFailed() {
        failed = true;
        worker?.terminate();
        worker = undefined;
        schedule();
        for (const field of fields.values()) field.textContent = '—';
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
        const available = Math.floor(document.documentElement.clientWidth - bounds.right - columnGap - viewportGutter);
        if (!bounds.width || available < minimumWidth) {
            if (!panel.hidden) {
                panel.hidden = true;
                schedule();
            }
            return;
        }
        if (panel.hidden && getComputedStyle(panel).position !== 'absolute') return;
        const origin = card.getBoundingClientRect();
        const left = (bounds.right - origin.left - card.clientLeft + columnGap) + 'px';
        const top = (bounds.top - origin.top - card.clientTop) + 'px';
        const width = Math.min(preferredWidth, available) + 'px';
        if (panel.style.left !== left) panel.style.left = left;
        if (panel.style.top !== top) panel.style.top = top;
        if (panel.style.width !== width) panel.style.width = width;
        if (panel.hidden) {
            panel.hidden = false;
            schedule();
        }
    }

    function schedulePlacement() {
        if (!frame) frame = requestAnimationFrame(place);
    }

    card.append(panel);
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
        worker?.terminate();
        panel.remove();
    }, {once: true});
    schedulePlacement();
}
