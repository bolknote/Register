/**
 * Shared state and deterministic policy for automatic editor image optimization.
 *
 * @copyright 2025-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

const imageState = {
    pictureFolderCsrfTokens: {},
    lastPreviewWrapper: null,
    activeImageOperations: 0,
    pasteImageJobs: new Map(),
    pasteImageBySrc: new Map(),
    pasteImageCounter: 0,
    previewOverlayStylesId: 'register-image-overlay-styles',
    editorForm: null,
    contentImageDirectory: '',
    defaultPublicationDate: '',
    reservationTail: Promise.resolve(),
    policy: {
        retinaSourceWidth: 2000,
        maxDecodedPixels: 80 * 1000 * 1000,
        maxOutputPixels: 20 * 1000 * 1000,
        maxDecodedEdge: 32767,
        jpegMinSsim: 0.985,
        retinaMinSsim: 0.97,
        png8MinSsim: 0.98,
        png8MinPsnr: 40,
        jpegQuality: 0.95,
        jpegMinQuality: 0.75,
        jpegQualitySearchSteps: 6,
        webpQuality: 82,
        png24OptLevel: 2,
        png8OptLevel: 2
    }
};

function humanFileSize(bytes) {
    if (typeof bytes !== 'number' || !isFinite(bytes)) {
        return '-';
    }
    if (bytes < 1024) {
        return bytes + ' B';
    }
    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = -1;
    do {
        value /= 1024;
        unit += 1;
    } while (value >= 1024 && unit < units.length - 1);
    return value.toFixed(value >= 10 ? 1 : 2) + ' ' + units[unit];
}

function formatDimensionValue(value) {
    if (typeof value !== 'number' || !isFinite(value) || value <= 0) {
        return 'auto';
    }
    return String(Math.round(value));
}

function formatDimensions(width, height) {
    return formatDimensionValue(width) + '×' + formatDimensionValue(height);
}

function isCalendarDate(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
    if (!match) {
        return false;
    }
    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const date = new Date(Date.UTC(year, month - 1, day));
    return date.getUTCFullYear() === year
        && date.getUTCMonth() === month - 1
        && date.getUTCDate() === day;
}

function datePart(value) {
    const match = /^(\d{4}-\d{2}-\d{2})/.exec(String(value || '').trim());
    return match && isCalendarDate(match[1]) ? match[1] : '';
}

function effectivePublicationDate() {
    const form = imageState.editorForm;
    if (form) {
        const selectedState = form.querySelector('[data-publication-state-input]:checked')?.value || 'draft';
        const scheduledDate = datePart(form.elements.namedItem('scheduled_at')?.value);
        const publishedDate = datePart(form.elements.namedItem('published_at')?.value);
        if (selectedState === 'scheduled' && scheduledDate) {
            return scheduledDate;
        }
        if (publishedDate) {
            return publishedDate;
        }
    }

    const fallback = datePart(imageState.defaultPublicationDate);
    if (!fallback) {
        throw new Error('The note publication date is unavailable.');
    }
    return fallback;
}

function planImageDimensions(width, height) {
    if (
        !Number.isInteger(width)
        || !Number.isInteger(height)
        || width <= 0
        || height <= 0
        || width > imageState.policy.maxDecodedEdge
        || height > imageState.policy.maxDecodedEdge
        || width * height > imageState.policy.maxDecodedPixels
    ) {
        throw new Error('The image dimensions are too large to optimize safely in the browser.');
    }

    const retina = width >= imageState.policy.retinaSourceWidth;
    const targetWidth = retina ? imageState.policy.retinaSourceWidth : width;
    const targetHeight = retina
        ? Math.max(1, Math.round(height * targetWidth / width))
        : height;
    if (targetWidth * targetHeight > imageState.policy.maxOutputPixels) {
        throw new Error('The optimized image dimensions are too large to process safely in the browser.');
    }
    return {
        retina: retina,
        targetWidth: targetWidth,
        targetHeight: targetHeight,
        displayWidth: retina ? Math.max(1, Math.floor(targetWidth / 2)) : targetWidth,
        displayHeight: retina ? Math.max(1, Math.floor(targetHeight / 2)) : targetHeight,
        resized: targetWidth !== width || targetHeight !== height
    };
}

function candidateIsAccepted(candidate, hasAlpha) {
    if (!candidate || !candidate.blob || typeof candidate.size !== 'number') {
        return false;
    }
    switch (candidate.type) {
        case 'jpeg':
            return !hasAlpha && candidate.ssim >= (candidate.minSsim ?? imageState.policy.jpegMinSsim);
        case 'webp':
            return true;
        case 'png8':
            return candidate.ssim >= imageState.policy.png8MinSsim
                && candidate.psnr >= imageState.policy.png8MinPsnr;
        case 'webp-lossless':
        case 'png24':
        case 'original':
            return true;
        default:
            return false;
    }
}

function chooseBestCandidate(candidates, hasAlpha) {
    const accepted = Object.values(candidates || {}).filter(function (candidate) {
        return candidateIsAccepted(candidate, hasAlpha);
    });
    if (accepted.length === 0) {
        return null;
    }
    accepted.sort(function (left, right) {
        return left.size - right.size;
    });
    return accepted[0];
}

function extensionForCandidate(candidate) {
    switch (candidate?.type) {
        case 'jpeg':
            return 'jpg';
        case 'png8':
        case 'png24':
            return 'png';
        case 'webp':
        case 'webp-lossless':
            return 'webp';
        case 'original':
            if (['jpg', 'png', 'webp'].includes(candidate.extension)) {
                return candidate.extension;
            }
            break;
        default:
            break;
    }
    throw new Error('Unsupported optimized image format.');
}

function webpEncodeOptions(quality, lossless, method = 6) {
    return {
        quality: quality,
        lossless: !!lossless,
        method: method,
        alphaQuality: 100,
        nearLossless: 100,
        sharpYuv: true,
        // `exact` only preserves invisible RGB below alpha=0; it is unrelated to gamma.
        exact: false
    };
}

function logPipelineSummary(job) {
    const candidates = {};
    Object.keys(job.candidates || {}).forEach(function (key) {
        const candidate = job.candidates[key];
        candidates[key] = candidate ? {
            size: candidate.size,
            ssim: candidate.ssim,
            psnr: candidate.psnr,
            quality: candidate.quality
        } : null;
    });
    console.info('Automatic image optimization summary', JSON.stringify({
        publicationDate: job.publicationDate,
        original: job.original,
        output: job.output,
        retina: !!job.retina,
        selected: job.selected?.type || null,
        candidates: candidates
    }));
}

export {
    imageState,
    humanFileSize,
    formatDimensionValue,
    formatDimensions,
    isCalendarDate,
    effectivePublicationDate,
    planImageDimensions,
    candidateIsAccepted,
    chooseBestCandidate,
    extensionForCandidate,
    webpEncodeOptions,
    logPipelineSummary
};
