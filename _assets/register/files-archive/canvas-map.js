(() => {
    'use strict';

    const MAP_SIZE = 2000;
    const OBJECT_SIZE = 20;
    const OBJECT_COUNT = 4000;
    const KEYBOARD_STEP = 48;

    const initialize = (demo) => {
        if (!(demo instanceof HTMLElement) || demo.dataset.initialized === 'yes') {
            return;
        }

        const viewport = demo.querySelector('.files-archive-canvas-map-viewport');
        const canvas = demo.querySelector('.files-archive-canvas-map-canvas');
        const reset = demo.querySelector('.files-archive-canvas-map-reset');
        const regenerate = demo.querySelector('.files-archive-canvas-map-regenerate');
        const statusText = demo.querySelector('[data-files-archive-map-status="text"]');
        const swatch = demo.querySelector('.files-archive-canvas-map-swatch');
        if (!(viewport instanceof HTMLElement)
            || !(canvas instanceof HTMLCanvasElement)
            || !(reset instanceof HTMLButtonElement)
            || !(regenerate instanceof HTMLButtonElement)
            || !(statusText instanceof HTMLElement)
            || !(swatch instanceof HTMLElement)
        ) {
            return;
        }

        const context = canvas.getContext('2d');
        if (context === null) {
            reset.disabled = true;
            regenerate.disabled = true;
            return;
        }

        const objects = [];
        let offsetX = 0;
        let offsetY = 0;
        let pointerId = null;
        let pointerStartX = 0;
        let pointerStartY = 0;
        let offsetStartX = 0;
        let offsetStartY = 0;
        let moved = false;
        let ignoreClick = false;

        const clamp = (value, viewportSize) => Math.min(0, Math.max(viewportSize - MAP_SIZE, value));
        const setPosition = (x, y) => {
            offsetX = clamp(x, viewport.clientWidth);
            offsetY = clamp(y, viewport.clientHeight);
            canvas.style.transform = `translate3d(${offsetX}px, ${offsetY}px, 0)`;
            demo.dataset.offsetX = String(Math.round(offsetX));
            demo.dataset.offsetY = String(Math.round(offsetY));
        };

        const setStatus = (text, color = '') => {
            statusText.textContent = text;
            swatch.style.backgroundColor = color;
            swatch.hidden = color === '';
        };

        const generate = () => {
            objects.length = 0;
            context.clearRect(0, 0, MAP_SIZE, MAP_SIZE);
            for (let index = 0; index < OBJECT_COUNT; ++index) {
                const x = Math.random() * (MAP_SIZE - OBJECT_SIZE);
                const y = Math.random() * (MAP_SIZE - OBJECT_SIZE);
                const red = Math.floor(Math.random() * 255);
                const green = Math.floor(Math.random() * 255);
                const blue = Math.floor(Math.random() * 255);
                const color = `rgb(${red}, ${green}, ${blue})`;
                objects.push({x, y, color});
                context.fillStyle = color;
                context.fillRect(x, y, OBJECT_SIZE, OBJECT_SIZE);
            }
            const generation = Number.parseInt(demo.dataset.generation ?? '0', 10);
            demo.dataset.generation = String(Number.isFinite(generation) ? generation + 1 : 1);
            demo.dataset.objectCount = String(objects.length);
            setStatus('Нажмите на квадрат, чтобы узнать его цвет.');
        };

        const finishDrag = (event) => {
            if (pointerId !== event.pointerId) {
                return;
            }
            ignoreClick = moved;
            pointerId = null;
            viewport.classList.remove('is-dragging');
            if (canvas.hasPointerCapture?.(event.pointerId)) {
                canvas.releasePointerCapture(event.pointerId);
            }
        };

        canvas.addEventListener('pointerdown', (event) => {
            if (event.button !== 0) {
                return;
            }
            pointerId = event.pointerId;
            pointerStartX = event.clientX;
            pointerStartY = event.clientY;
            offsetStartX = offsetX;
            offsetStartY = offsetY;
            moved = false;
            viewport.classList.add('is-dragging');
            canvas.setPointerCapture?.(event.pointerId);
        });
        canvas.addEventListener('pointermove', (event) => {
            if (pointerId !== event.pointerId) {
                return;
            }
            const deltaX = event.clientX - pointerStartX;
            const deltaY = event.clientY - pointerStartY;
            moved ||= Math.abs(deltaX) + Math.abs(deltaY) > 3;
            setPosition(offsetStartX + deltaX, offsetStartY + deltaY);
            event.preventDefault();
        });
        canvas.addEventListener('pointerup', finishDrag);
        canvas.addEventListener('pointercancel', finishDrag);
        canvas.addEventListener('click', (event) => {
            if (ignoreClick) {
                ignoreClick = false;
                return;
            }
            const rect = canvas.getBoundingClientRect();
            const x = (event.clientX - rect.left) * (MAP_SIZE / rect.width);
            const y = (event.clientY - rect.top) * (MAP_SIZE / rect.height);
            const object = [...objects].reverse().find((candidate) => candidate.x < x
                && x < candidate.x + OBJECT_SIZE
                && candidate.y < y
                && y < candidate.y + OBJECT_SIZE);
            if (object === undefined) {
                setStatus('В этой точке квадрата нет.');
                return;
            }
            setStatus(`Цвет квадрата: ${object.color}`, object.color);
        });

        viewport.addEventListener('keydown', (event) => {
            const movement = {
                ArrowLeft: [-KEYBOARD_STEP, 0],
                ArrowRight: [KEYBOARD_STEP, 0],
                ArrowUp: [0, -KEYBOARD_STEP],
                ArrowDown: [0, KEYBOARD_STEP],
            }[event.key];
            if (movement !== undefined) {
                setPosition(offsetX + movement[0], offsetY + movement[1]);
                event.preventDefault();
            } else if (event.key === 'Home') {
                setPosition(0, 0);
                event.preventDefault();
            }
        });
        reset.addEventListener('click', () => {
            setPosition(0, 0);
            viewport.focus();
        });
        regenerate.addEventListener('click', generate);
        window.addEventListener('resize', () => setPosition(offsetX, offsetY));

        demo.dataset.initialized = 'yes';
        setPosition(0, 0);
        generate();
    };

    const boot = () => document
        .querySelectorAll('[data-files-archive-demo="canvas-map"]')
        .forEach(initialize);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, {once: true});
    } else {
        boot();
    }
})();
