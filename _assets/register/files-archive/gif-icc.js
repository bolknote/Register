(() => {
    const demo = document.querySelector('[data-files-archive-demo="gif-icc"]');
    if (!(demo instanceof HTMLElement)) {
        return;
    }

    const image = demo.querySelector('[data-gif-icc-pixel]');
    const status = demo.querySelector('[data-gif-icc-status]');
    const values = demo.querySelector('[data-gif-icc-values]');
    if (!(image instanceof HTMLImageElement)
        || !(status instanceof HTMLElement)
        || !(values instanceof HTMLElement)
    ) {
        return;
    }

    const fail = (message) => {
        demo.dataset.state = 'error';
        status.textContent = 'Проверка не состоялась';
        values.textContent = message;
    };

    const inspect = () => {
        try {
            const canvas = document.createElement('canvas');
            canvas.width = 1;
            canvas.height = 1;
            const context = canvas.getContext('2d', { willReadFrequently: true });
            if (context === null) {
                fail('Браузер не предоставил двумерный Canvas.');
                return;
            }

            context.drawImage(image, 0, 0, 1, 1);
            const [red, green, blue, alpha] = context.getImageData(0, 0, 1, 1).data;
            const supported = red === 0;
            demo.dataset.state = supported ? 'supported' : 'unsupported';
            status.textContent = supported
                ? 'ICC-профиль применён'
                : 'ICC-профиль не применён';
            values.textContent = `Получившийся пиксель: rgb(${red} ${green} ${blue} / ${alpha / 255})`;
        } catch (error) {
            fail(error instanceof Error ? error.message : 'Неизвестная ошибка Canvas.');
        }
    };

    if (image.complete) {
        if (image.naturalWidth === 0) {
            fail('Тестовый GIF не удалось загрузить.');
        } else {
            inspect();
        }
        return;
    }

    image.addEventListener('load', inspect, { once: true });
    image.addEventListener('error', () => fail('Тестовый GIF не удалось загрузить.'), { once: true });
})();
