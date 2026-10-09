/* Copyright 2026 Register contributors. MIT license. */
(() => {
    'use strict';
    const states = new Map();
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let rendererPromise;

    function renderer() {
        if (window.lottie) return Promise.resolve(window.lottie);
        if (!rendererPromise) {
            rendererPromise = new Promise((resolve, reject) => {
                const source = document.querySelector('meta[name="register-sticker-renderer"]')?.content;
                const url = source && new URL(source, location.href);
                if (!url || url.origin !== location.origin) return reject(new Error('Sticker renderer unavailable'));
                const script = document.createElement('script');
                script.src = url.href;
                script.onload = () => window.lottie ? resolve(window.lottie) : reject(new Error('Sticker renderer unavailable'));
                script.onerror = () => { script.remove(); rendererPromise = undefined; reject(new Error('Sticker renderer unavailable')); };
                document.head.append(script);
            });
        }
        return rendererPromise;
    }

    function update(state) {
        if (state.video) {
            if (reducedMotion.matches || !state.visible || document.hidden) state.video.pause();
            else void state.video.play().catch(() => {});
            return;
        }
        if (!state.animation) return;
        if (reducedMotion.matches) {
            state.animation.goToAndStop(0, true);
        } else if (state.visible && !document.hidden) {
            state.animation.play();
        } else {
            state.animation.pause();
        }
    }

    async function load(node, state) {
        if (state.loading || state.animation) return;
        state.loading = true;
        const fallback = node.textContent;
        try {
            const source = new URL(node.dataset.animation, location.href);
            if (source.origin !== location.origin || !/\/_pictures\/[A-Za-z0-9_-]+\/comments\/telegram\/[1-9]\d*\/[1-9]\d*\/\d+-[a-f0-9]{20}\.json$/.test(source.pathname)) return;
            const [lottie, response] = await Promise.all([renderer(), fetch(source, { signal: state.abort.signal })]);
            if (!response.ok) throw new Error('Sticker unavailable');
            const data = await response.json();
            if (!node.isConnected || state.abort.signal.aborted) return;
            node.textContent = '';
            node.setAttribute('role', 'img');
            node.setAttribute('aria-label', fallback || '✦');
            const animation = lottie.loadAnimation({ container: node, renderer: 'canvas', loop: true, autoplay: false,
                animationData: data, rendererSettings: { clearCanvas: true } });
            state.animation = animation;
            animation.addEventListener('data_failed', () => { animation.destroy(); node.textContent = fallback; });
            animation.addEventListener('DOMLoaded', () => update(state));
            update(state);
        } catch {
            if (node.isConnected && !state.animation) node.textContent = fallback;
        }
    }

    const observer = 'IntersectionObserver' in window ? new IntersectionObserver(entries => {
        for (const entry of entries) {
            const state = states.get(entry.target);
            if (!state) continue;
            state.visible = entry.isIntersecting;
            if (state.visible && !state.video) void load(entry.target, state);
            update(state);
        }
    }) : null;

    function scan() {
        for (const [node, state] of states) {
            if (node.isConnected) continue;
            state.abort.abort();
            state.animation?.destroy();
            state.video?.pause();
            observer?.unobserve(node);
            states.delete(node);
        }
        for (const node of document.querySelectorAll('.comment-sticker[data-animation], video.comment-sticker-video')) {
            if (states.has(node)) continue;
            const state = { abort: new AbortController(), visible: !observer, loading: false, animation: null,
                video: node instanceof HTMLVideoElement ? node : null };
            states.set(node, state);
            if (observer) observer.observe(node);
            else if (state.video) update(state);
            else void load(node, state);
        }
    }

    document.addEventListener('visibilitychange', () => states.forEach(update));
    reducedMotion.addEventListener('change', () => states.forEach(update));
    new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
    scan();
})();
