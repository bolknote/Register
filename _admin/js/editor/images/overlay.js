/** Read-only progress overlay for automatic editor image optimization. */

import {formatDimensions, humanFileSize, imageState} from './state.js';
import {editorDeps} from '../deps.js';

const candidateRows = [
    ['original', 'orig'],
    ['webp', 'webp'],
    ['webpLossless', 'webp L'],
    ['jpeg', 'jpg'],
    ['png8', 'png8'],
    ['png24', 'png']
];

function findPreviewImageForJob(job) {
    if (!job || !imageState.lastPreviewWrapper) {
        return null;
    }
    const images = imageState.lastPreviewWrapper.querySelectorAll('img');
    for (let index = 0; index < images.length; index += 1) {
        if (images[index].getAttribute('src') === job.src) {
            return images[index];
        }
    }
    return null;
}

function findJobOverlayContainer(job) {
    return job?.overlay?.overlay?.closest('.register-image-overlay-wrap') || null;
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
    job.overlay = null;
}

function ensurePreviewOverlayStyles(doc) {
    if (!doc || doc.getElementById(imageState.previewOverlayStylesId) || !editorDeps.imageOverlayStylesheet) {
        return;
    }
    const stylesheet = doc.createElement('link');
    stylesheet.id = imageState.previewOverlayStylesId;
    stylesheet.rel = 'stylesheet';
    stylesheet.href = editorDeps.imageOverlayStylesheet;
    doc.head.appendChild(stylesheet);
}

function line(doc, className) {
    const element = doc.createElement('div');
    element.className = 'register-image-overlay-line ' + className;
    element.textContent = '–';
    return element;
}

function formatRow(doc, key, label) {
    const row = doc.createElement('div');
    row.className = 'register-image-format';
    row.dataset.format = key;
    const name = doc.createElement('span');
    name.className = 'register-format-name';
    name.textContent = label;
    const size = doc.createElement('span');
    size.className = 'register-format-size';
    size.textContent = '…';
    const info = doc.createElement('span');
    info.className = 'register-format-info';
    row.append(name, size, info);
    return row;
}

function renderImageOverlay(img, job, handlers) {
    if (!img || !job || job.overlayHidden) {
        return;
    }
    const doc = img.ownerDocument;
    ensurePreviewOverlayStyles(doc);

    let container = img.closest('.register-image-overlay-wrap');
    if (!container || container.dataset.jobId !== String(job.id)) {
        container = doc.createElement('span');
        container.className = 'register-image-overlay-wrap';
        container.dataset.jobId = String(job.id);
        img.parentNode.insertBefore(container, img);
        container.appendChild(img);
    }

    let overlay = container.querySelector('.register-image-overlay');
    if (!overlay) {
        overlay = doc.createElement('div');
        overlay.className = 'register-image-overlay';
        const formats = doc.createElement('div');
        formats.className = 'register-image-overlay-formats';
        candidateRows.forEach(function ([key, label]) {
            formats.appendChild(formatRow(doc, key, label));
        });
        overlay.append(
            line(doc, 'register-image-overlay-status'),
            line(doc, 'register-image-overlay-dims'),
            line(doc, 'register-image-overlay-sizes'),
            formats
        );

        const closeButton = doc.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'register-image-overlay-close';
        closeButton.textContent = '×';
        closeButton.hidden = true;
        closeButton.addEventListener('click', function () {
            handlers?.closeImageJob?.(job);
        });
        overlay.appendChild(closeButton);
        container.appendChild(overlay);
    }

    const rows = {};
    candidateRows.forEach(function ([key]) {
        rows[key] = overlay.querySelector('[data-format="' + key + '"]');
    });
    job.overlay = {
        overlay: overlay,
        status: overlay.querySelector('.register-image-overlay-status'),
        dims: overlay.querySelector('.register-image-overlay-dims'),
        sizes: overlay.querySelector('.register-image-overlay-sizes'),
        rows: rows,
        close: overlay.querySelector('.register-image-overlay-close')
    };
    updateImageJobOverlay(job, handlers);
}

function candidateInfo(candidate) {
    if (!candidate) {
        return '';
    }
    const parts = [];
    if (typeof candidate.quality === 'number') {
        parts.push('q' + Math.round(candidate.quality));
    }
    if (typeof candidate.ssim === 'number' && isFinite(candidate.ssim)) {
        parts.push('SSIM ' + candidate.ssim.toFixed(4));
    }
    return parts.join(' · ');
}

function updateImageJobOverlay(job, handlers) {
    if (!job || job.overlayHidden) {
        return;
    }
    if (!job.overlay?.overlay?.isConnected) {
        const img = findPreviewImageForJob(job);
        if (img) {
            renderImageOverlay(img, job, handlers);
        }
    }
    if (!job.overlay?.overlay?.isConnected) {
        return;
    }

    const overlay = job.overlay;
    overlay.overlay.dataset.status = job.status || 'starting';
    overlay.status.textContent = job.statusLabel || 'Оптимизация…';

    if (job.original?.width && job.output?.width) {
        overlay.dims.textContent = formatDimensions(job.original.width, job.original.height)
            + (job.output.resized ? ' → ' + formatDimensions(job.output.width, job.output.height) : '')
            + (job.retina ? ' · @2x' : ' · 1x');
    } else {
        overlay.dims.textContent = 'Определяю размер…';
    }

    overlay.sizes.textContent = humanFileSize(job.original?.size);
    if (job.selected?.size) {
        overlay.sizes.textContent += ' → ' + humanFileSize(job.selected.size);
    }

    candidateRows.forEach(function ([key]) {
        const row = overlay.rows[key];
        const candidate = job.candidates?.[key] || null;
        row.hidden = candidate === false;
        row.classList.toggle('is-best', !!candidate && job.selected === candidate);
        row.querySelector('.register-format-size').textContent = candidate?.size
            ? humanFileSize(candidate.size)
            : (job.status === 'error' || job.status === 'done' ? '—' : '…');
        row.querySelector('.register-format-info').textContent = candidateInfo(candidate);
    });
    overlay.close.hidden = job.status !== 'done' && job.status !== 'error';
}

export {renderImageOverlay, updateImageJobOverlay, detachJobOverlay};
