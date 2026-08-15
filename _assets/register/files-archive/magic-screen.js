(() => {
    'use strict';

    document.querySelectorAll('[data-files-archive-demo="magic-screen"]').forEach((demo) => {
        const canvas = demo.querySelector('.files-archive-magic-screen-canvas');
        if (!(canvas instanceof HTMLCanvasElement)) {
            return;
        }

        const context = canvas.getContext('2d');
        if (context === null) {
            return;
        }

        const clear = () => {
            context.fillStyle = '#e8e9e3';
            context.fillRect(0, 0, canvas.width, canvas.height);
        };
        const draw = (event) => {
            if (event.pointerType !== 'mouse' && event.buttons === 0) {
                return;
            }

            const bounds = canvas.getBoundingClientRect();
            const x = (event.clientX - bounds.left) * canvas.width / bounds.width;
            const y = (event.clientY - bounds.top) * canvas.height / bounds.height;
            context.fillStyle = '#777';
            context.beginPath();
            context.arc(x, y, 5, 0, Math.PI * 2);
            context.fill();
        };

        clear();
        canvas.addEventListener('pointerdown', (event) => {
            canvas.setPointerCapture(event.pointerId);
            draw(event);
        });
        canvas.addEventListener('pointermove', draw);
        canvas.addEventListener('keydown', (event) => {
            if (event.key !== 'Delete' && event.key !== 'Backspace') {
                return;
            }
            event.preventDefault();
            clear();
        });
        demo.querySelectorAll('[data-files-archive-magic-clear]').forEach((button) => {
            button.addEventListener('click', clear);
        });
    });
})();
