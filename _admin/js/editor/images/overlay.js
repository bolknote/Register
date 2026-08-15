/**
 * Image optimization overlay UI for editor preview in S2.
 *
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

import {formatDimensions, humanFileSize, imageState} from './state.js';
import {editorDeps} from '../deps.js';

function findPreviewImageForJob(job) {
    if (!job || !imageState.lastPreviewWrapper) {
        return null;
    }
    const images = imageState.lastPreviewWrapper.querySelectorAll('img');
    for (let i = 0; i < images.length; i += 1) {
        const img = images[i];
        const key = img.getAttribute('data-pending-src') || img.getAttribute('src');
        if (!key) {
            continue;
        }
        if (job.src && key === job.src) {
            return img;
        }
        if (job.blobUrl && key === job.blobUrl) {
            return img;
        }
    }
    return null;
}

function findJobOverlayContainer(job) {
    if (!job || !job.overlay || !job.overlay.overlay) {
        return null;
    }
    const overlay = job.overlay.overlay;
    return overlay.closest('.s2-image-overlay-wrap');
}

function detachJobOverlay(job) {
    const container = findJobOverlayContainer(job);
    if (!container) {
        return;
    }
    const img = container.querySelector('img');
    if (img && container.parentNode) {
        container.parentNode.insertBefore(img, container);
    }
    container.remove();
}

function ensurePreviewOverlayStyles(doc) {
    if (!doc || doc.getElementById(imageState.previewOverlayStylesId)) {
        return;
    }
    if (!editorDeps.imageOverlayStylesheet) {
        return;
    }

    const stylesheet = doc.createElement('link');
    stylesheet.id = imageState.previewOverlayStylesId;
    stylesheet.rel = 'stylesheet';
    stylesheet.href = editorDeps.imageOverlayStylesheet;
    doc.head.appendChild(stylesheet);
}

function createOverlayLine(doc, className) {
    const line = doc.createElement('div');
    line.className = 's2-image-overlay-line ' + className;
    line.textContent = '-';
    return line;
}

function createOverlayButton(doc, attributeName, value, label) {
    const button = doc.createElement('button');
    button.type = 'button';
    button.setAttribute(attributeName, value);
    button.textContent = label;
    return button;
}

function createFormatRow(doc, format, label) {
    const row = doc.createElement('label');
    row.className = 's2-image-format';
    row.setAttribute('data-format', format);

    const input = doc.createElement('input');
    input.type = 'checkbox';
    input.setAttribute('data-format', format);

    const name = doc.createElement('span');
    name.className = 's2-format-name';
    name.textContent = label;

    const size = doc.createElement('span');
    size.className = 's2-format-size';
    size.textContent = '-';

    const info = doc.createElement('span');
    info.className = 's2-format-info';
    row.append(input, name, size, info);
    return row;
}

function renderImageOverlay(img, job, handlers) {
    if (!img || !job || job.closed) {
        return;
    }

    const doc = img.ownerDocument;
    ensurePreviewOverlayStyles(doc);
    let container = img.closest('.s2-image-overlay-wrap');
    if (!container || container.getAttribute('data-job-id') !== String(job.id)) {
        container = doc.createElement('span');
        container.className = 's2-image-overlay-wrap';
        container.setAttribute('data-job-id', String(job.id));
        img.parentNode.insertBefore(container, img);
        container.appendChild(img);
    }

    let overlay = container.querySelector('.s2-image-overlay');
    if (!overlay) {
        overlay = doc.createElement('div');
        overlay.className = 's2-image-overlay';

        const controls = doc.createElement('div');
        controls.className = 's2-image-overlay-controls';
        const modeGroup = doc.createElement('div');
        modeGroup.className = 's2-image-overlay-group s2-image-overlay-mode';
        modeGroup.append(
            createOverlayButton(doc, 'data-mode', '1x', '1x'),
            createOverlayButton(doc, 'data-mode', '2x', '2x')
        );
        controls.appendChild(modeGroup);

        const formats = doc.createElement('div');
        formats.className = 's2-image-overlay-formats';
        formats.append(
            createFormatRow(doc, 'jpeg', 'jpg'),
            createFormatRow(doc, 'png8', 'png8'),
            createFormatRow(doc, 'png24', 'png24')
        );

        overlay.append(
            createOverlayLine(doc, 's2-image-overlay-dims'),
            createOverlayLine(doc, 's2-image-overlay-sizes'),
            controls,
            formats
        );
        container.appendChild(overlay);

        const closeButton = doc.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 's2-image-overlay-close';
        closeButton.textContent = '×';
        closeButton.addEventListener('click', function () {
            if (handlers && handlers.closeImageJob) {
                handlers.closeImageJob(job);
            }
        });
        overlay.appendChild(closeButton);

        overlay.querySelectorAll('button[data-mode]').forEach(function (button) {
            button.addEventListener('click', function () {
                const mode = button.getAttribute('data-mode');
                if (mode && handlers && handlers.switchImageJobMode) {
                    handlers.switchImageJobMode(job, mode);
                }
            });
        });

        overlay.querySelectorAll('input[type="checkbox"][data-format]').forEach(function (input) {
            input.addEventListener('change', function () {
                const format = input.getAttribute('data-format');
                if (format && handlers && handlers.toggleImageJobFormat) {
                    handlers.toggleImageJobFormat(job, format, input.checked);
                }
            });
        });

        const sizeGroup = doc.createElement('div');
        sizeGroup.className = 's2-image-overlay-group s2-image-overlay-size';
        imageState.sizeOptions.forEach(function (sizeOption) {
            const value = sizeOption === Infinity ? 'inf' : String(sizeOption);
            const button = createOverlayButton(doc, 'data-size', value, sizeOption === Infinity ? '∞' : String(sizeOption));
            button.addEventListener('click', function () {
                const selectedValue = button.getAttribute('data-size');
                if (selectedValue && handlers && handlers.switchImageJobSize) {
                    handlers.switchImageJobSize(job, selectedValue);
                }
            });
            sizeGroup.appendChild(button);
        });
        controls.appendChild(sizeGroup);
    }

    job.overlay = {
        overlay: overlay,
        dims: overlay.querySelector('.s2-image-overlay-dims'),
        sizes: overlay.querySelector('.s2-image-overlay-sizes'),
        modeButtons: overlay.querySelectorAll('button[data-mode]'),
        sizeButtons: overlay.querySelectorAll('button[data-size]'),
        formatRows: {
            jpeg: overlay.querySelector('.s2-image-format[data-format="jpeg"]'),
            png8: overlay.querySelector('.s2-image-format[data-format="png8"]'),
            png24: overlay.querySelector('.s2-image-format[data-format="png24"]')
        }
    };

    updateImageJobOverlay(job, handlers);
}

function updateImageJobOverlay(job, handlers) {
    if (!job || job.closed) {
        return;
    }
    if (!job.overlay || !job.overlay.overlay || !job.overlay.overlay.isConnected) {
        const img = findPreviewImageForJob(job);
        if (img) {
            renderImageOverlay(img, job, handlers);
        }
    }
    if (!job.overlay || !job.overlay.overlay || !job.overlay.overlay.isConnected) {
        return;
    }
    const state = job.modes[job.currentMode];
    const overlay = job.overlay;
    const status = state && state.status ? state.status : 'idle';
    overlay.overlay.setAttribute('data-status', status);

    let bestSize = null;
    if (state) {
        ['jpeg', 'png8', 'png24'].forEach(function (format) {
            if (!state.formatEnabled[format]) {
                return;
            }
            const candidate = state.candidates[format];
            if (candidate && typeof candidate.size === 'number') {
                if (bestSize === null || candidate.size < bestSize) {
                    bestSize = candidate.size;
                }
            }
        });
    }

    let dimText = '–';
    if (job.original.width && job.original.height) {
        dimText = formatDimensions(job.original.width, job.original.height);
        if (state && state.sourceInfo && typeof state.sourceInfo.width === 'number') {
            if (state.sourceInfo.resized || state.sourceInfo.cropped) {
                dimText += ' → ' + formatDimensions(state.sourceInfo.width, state.sourceInfo.height);
            }
        }
    }
    overlay.dims.textContent = dimText;

    let sizeText = humanFileSize(job.original.size);
    if (bestSize !== null) {
        sizeText += ' → ' + humanFileSize(bestSize);
    } else {
        sizeText += ' → ?';
    }
    overlay.sizes.textContent = sizeText;

    overlay.modeButtons.forEach(function (button) {
        const mode = button.getAttribute('data-mode');
        if (mode === job.currentMode) {
            button.classList.add('is-active');
        } else {
            button.classList.remove('is-active');
        }
    });

    overlay.sizeButtons.forEach(function (button) {
        const value = button.getAttribute('data-size');
        const sizeValue = value === 'inf' ? Infinity : parseInt(value, 10);
        if (state && state.sizeChoice === sizeValue) {
            button.classList.add('is-active');
        } else {
            button.classList.remove('is-active');
        }
    });

    ['jpeg', 'png8', 'png24'].forEach(function (format) {
        const row = overlay.formatRows[format];
        if (!row || !state) {
            return;
        }
        const input = row.querySelector('input');
        const size = row.querySelector('.s2-format-size');
        const info = row.querySelector('.s2-format-info');
        if (input) {
            input.checked = !!state.formatEnabled[format];
        }
        if (state.candidates[format] && typeof state.candidates[format].size === 'number') {
            size.textContent = humanFileSize(state.candidates[format].size);
        } else {
            size.textContent = state.candidateReady[format] ? '-' : '...';
        }
        let infoText = '';
        if (format === 'jpeg' && state.candidates.jpeg && typeof state.candidates.jpeg.quality === 'number') {
            infoText = 'q ' + Math.round(state.candidates.jpeg.quality * 100) + '%';
        } else if (format === 'png8' && state.candidates.png8 && typeof state.candidates.png8.colors === 'number') {
            infoText = 'colors ' + state.candidates.png8.colors;
        }
        if (infoText && !state.candidateReady[format]) {
            infoText += '...';
        }
        info.textContent = infoText;
        if (state.selectedType === format) {
            row.classList.add('is-best');
        } else {
            row.classList.remove('is-best');
        }
    });
}

export {
    detachJobOverlay,
    renderImageOverlay,
    updateImageJobOverlay
};
