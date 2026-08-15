(() => {
    'use strict';

    const formats = [
        ['JPEG', 'jpeg'],
        ['GIF', 'gif'],
        ['PNG', 'png'],
        ['XBM', 'xbm'],
        ['WebP', 'webp'],
        ['BMP', 'bmp'],
        ['SVG', 'svg'],
        ['JPEG XR', 'jpegxr'],
        ['TIFF', 'tiff'],
        ['PDF', 'pdf'],
        ['EMF', 'emf'],
        ['WMF', 'wmf'],
        ['WBMP', 'wbmp'],
        ['JPEG 2000', 'jpeg2000'],
        ['ICO', 'ico'],
        ['MNG', 'mng'],
        ['BPG', 'bpg'],
        ['AVIF', 'avif'],
    ];

    const apngData = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACGFjVEwAAAABAAAAAcMq2TYAAAANSURBVAiZY2BgYPgPAAEEAQB9ssjfAAAAGmZjVEwAAAAAAAAAAQAAAAEAAAAAAAAAAAD6A+gBAbNU+2sAAAARZmRBVAAAAAEImWNgYGBgAAAABQAB6MzFdgAAAABJRU5ErkJggg==';

    const testImage = (url) => new Promise((resolve) => {
        const image = new Image();
        const timeout = window.setTimeout(() => {
            image.src = '';
            resolve(false);
        }, 3000);
        const finish = (result) => {
            window.clearTimeout(timeout);
            image.onload = null;
            image.onerror = null;
            resolve(result);
        };

        image.onload = () => finish(true);
        image.onerror = () => finish(false);
        image.src = url;
    });

    const testApng = () => new Promise((resolve) => {
        const image = new Image();
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');
        if (context === null) {
            resolve(false);
            return;
        }
        canvas.width = 1;
        canvas.height = 1;
        image.onload = () => {
            try {
                context.drawImage(image, 0, 0);
                resolve(context.getImageData(0, 0, 1, 1).data[3] === 0);
            } catch (_error) {
                resolve(false);
            }
        };
        image.onerror = () => resolve(false);
        image.src = apngData;
    });

    const createResult = (name) => {
        const item = document.createElement('li');
        item.className = 'files-archive-format-detector-result';
        item.dataset.state = 'pending';
        const label = document.createElement('span');
        label.className = 'files-archive-format-detector-name';
        label.textContent = name;
        const state = document.createElement('span');
        state.className = 'files-archive-format-detector-state';
        state.textContent = 'Проверка…';
        item.append(label, state);
        return {item, state};
    };

    const setResult = (entry, supported) => {
        entry.item.dataset.state = supported ? 'supported' : 'unsupported';
        entry.state.textContent = supported ? 'Поддерживается' : 'Не поддерживается';
    };

    document.querySelectorAll('[data-files-archive-demo="graphics-format-detector"]').forEach((demo) => {
        const prefix = demo.dataset.assetsPrefix;
        const results = demo.querySelector('[data-format-detector-results]');
        const summary = demo.querySelector('[data-format-detector-summary]');
        const repeat = demo.querySelector('[data-format-detector-repeat]');
        if (!prefix || !(results instanceof HTMLElement) || !(summary instanceof HTMLElement)
            || !(repeat instanceof HTMLButtonElement)) {
            return;
        }

        let generation = 0;
        const run = async () => {
            generation += 1;
            const currentGeneration = generation;
            repeat.disabled = true;
            results.replaceChildren();
            summary.textContent = 'Проверяются 19 форматов…';

            const entries = formats.map(([name]) => createResult(name));
            const apngEntry = createResult('APNG');
            [...entries, apngEntry].forEach(({item}) => results.append(item));

            const checks = formats.map(async ([name, extension], index) => {
                const supported = await testImage(`${prefix}0.${extension}?detector=${currentGeneration}`);
                if (generation === currentGeneration) {
                    setResult(entries[index], supported);
                }
                return supported;
            });
            checks.push((async () => {
                const supported = await testApng();
                if (generation === currentGeneration) {
                    setResult(apngEntry, supported);
                }
                return supported;
            })());

            const detected = await Promise.all(checks);
            if (generation !== currentGeneration) {
                return;
            }
            const supportedCount = detected.filter(Boolean).length;
            summary.textContent = `Поддерживается ${supportedCount} из ${detected.length} форматов`;
            repeat.disabled = false;
        };

        repeat.addEventListener('click', run);
        run();
    });
})();
