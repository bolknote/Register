(() => {
    'use strict';

    const initialize = (demo) => {
        if (!(demo instanceof HTMLElement) || demo.dataset.initialized === 'yes') {
            return;
        }

        const preview = demo.querySelector('.files-archive-maze-preview');
        const canvas = demo.querySelector('.files-archive-maze-canvas');
        const button = demo.querySelector('.files-archive-maze-regenerate');
        if (!(preview instanceof HTMLElement)
            || !(canvas instanceof HTMLCanvasElement)
            || !(button instanceof HTMLButtonElement)
        ) {
            return;
        }

        const context = canvas.getContext('2d');
        if (context === null) {
            button.disabled = true;
            return;
        }

        const draw = () => {
            const width = canvas.width;
            const height = canvas.height;
            context.clearRect(0, 0, width, height);
            context.fillStyle = '#36f';
            context.fillRect(0, 0, width, height);
            context.font = '10px monospace';
            context.fillStyle = '#068';

            for (let y = 7; y < height; y += 8) {
                for (let x = -1; x < width; x += 4) {
                    context.fillText(Math.random() < 0.5 ? '/' : '\\', x, y);
                }
            }

            preview.style.backgroundImage = `url("${canvas.toDataURL('image/png')}")`;
            const generation = Number.parseInt(demo.dataset.generation ?? '0', 10);
            demo.dataset.generation = String(Number.isFinite(generation) ? generation + 1 : 1);
        };

        button.addEventListener('click', draw);
        demo.dataset.initialized = 'yes';
        draw();
    };

    const boot = () => document
        .querySelectorAll('[data-files-archive-demo="slash-maze"]')
        .forEach(initialize);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, {once: true});
    } else {
        boot();
    }
})();
